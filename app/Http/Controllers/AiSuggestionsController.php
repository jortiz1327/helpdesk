<?php

namespace App\Http\Controllers;

use App\Services\Ai\AiSuggestionService;
use App\Support\TicketVisibility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;

/**
 * Sugerencias de la IA en la ficha del ticket. Requiere helpdesk.access (ruta) y que el
 * agente pueda ver ese ticket. La IA nunca envía nada: solo propone; quien responde es la persona.
 *
 *   GET  ?action=status                  → ¿está activa?
 *   GET  ?action=suggest&ticket=ID       → la sugerencia vigente (la genera si no existe)
 *   GET  ?action=stream&ticket=ID[&regen=1] → igual, pero devuelve cada paso al momento (una línea JSON por paso, NDJSON)
 *   POST ?action=regenerate  ticket      → pide otra
 *   POST ?action=rate  id, rating, correction → 👍 / 👎 (con corrección obligatoria)
 *   POST ?action=used  id                → el agente la usó de base
 *   GET  ?action=admin                   → panel de Ajustes: estado, acierto, conocimiento (support.config)
 *   POST ?action=admin_save  enabled     → enciende/apaga el asistente (support.config)
 *   POST ?action=admin_index             → actualiza la base de conocimiento (support.config)
 *   POST ?action=admin_review  id, status → valida / rechaza una corrección de un agente (support.config)
 */
class AiSuggestionsController extends Controller
{
    public function __construct(private AiSuggestionService $ai)
    {
    }

    public function handle(Request $request)
    {
        return match ($request->query('action', 'status')) {
            'suggest'    => $this->suggest($request, false),
            'stream'     => $this->stream($request),
            'regenerate' => $this->post($request) ?? $this->suggest($request, true),
            'rate'       => $this->post($request) ?? $this->rate($request),
            'used'       => $this->post($request) ?? $this->used($request),
            'admin'      => $this->admin($request),
            'admin_save' => $this->post($request) ?? $this->adminSave($request),
            'admin_budget' => $this->post($request) ?? $this->adminBudget($request),
            'admin_index' => $this->post($request) ?? $this->adminIndex($request),
            'admin_review' => $this->post($request) ?? $this->adminReview($request),
            'admin_history' => $this->adminHistory($request),
            default     => response()->json(['ok' => true, 'enabled' => $this->ai->enabled()]),
        };
    }

    /** Estado del asistente para Ajustes. Nunca devuelve la clave: solo si hay una. */
    private function admin(Request $request)
    {
        if ($denegado = $this->soloConfig($request)) return $denegado;
        return response()->json(['ok' => true] + $this->ai->panel());
    }

    private function adminSave(Request $request)
    {
        if ($denegado = $this->soloConfig($request)) return $denegado;
        $quiere = filter_var($request->input('enabled'), FILTER_VALIDATE_BOOLEAN);
        if ($quiere && ! $this->ai->configurado()) {
            return response()->json(['ok' => false, 'error' => 'Falta la clave del proveedor de IA en el servidor (.env)'], 422);
        }
        \App\Models\Setting::put('ai_suggestions_enabled', $quiere ? '1' : '0');
        return response()->json(['ok' => true] + $this->ai->panel());
    }

    /** Tope mensual de gasto (USD, mes natural). 0 = sin tope. */
    private function adminBudget(Request $request)
    {
        if ($denegado = $this->soloConfig($request)) return $denegado;
        $v = $request->input('budget');
        if (! is_numeric($v) || $v < 0 || $v > 100000) {
            return response()->json(['ok' => false, 'error' => 'Escribe un importe en dólares entre 0 y 100000'], 422);
        }
        \App\Models\Setting::put('ai_budget_month_usd', (string) round((float) $v, 2));
        return response()->json(['ok' => true] + $this->ai->panel());
    }

    private function adminIndex(Request $request)
    {
        if ($denegado = $this->soloConfig($request)) return $denegado;
        $k = app(\App\Services\Ai\AiKnowledge::class);
        [$nuevos] = $k->indexarTickets();
        $trozos = $k->indexarManuales();
        $k->indexarPlantillas();
        return response()->json(['ok' => true, 'nuevos' => $nuevos, 'trozos' => $trozos] + $this->ai->panel());
    }

    /** Validar / rechazar / dejar pendiente una corrección de un agente (support.config). */
    private function adminReview(Request $request)
    {
        if ($denegado = $this->soloConfig($request)) return $denegado;
        try {
            $ok = $this->ai->revisarCorreccion((int) $request->input('id'), (string) $request->input('status'), $request->user()->id);
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
        return $ok ? response()->json(['ok' => true] + $this->ai->panel())
                   : response()->json(['ok' => false, 'error' => 'Corrección no encontrada'], 404);
    }

    /** Historial de valoraciones (quién, cuándo, qué sugirió la IA, qué corrigió la persona y qué se envió). Solo support.config. */
    private function adminHistory(Request $request)
    {
        if ($denegado = $this->soloConfig($request)) return $denegado;
        return response()->json(['ok' => true] + $this->ai->historial(
            (string) $request->query('filter', ''), (string) $request->query('q', ''), (int) $request->query('offset', 0), 30,
        ));
    }

    private function soloConfig(Request $request)
    {
        return $request->user()?->can('support.config') ? null : response()->json(['ok' => false, 'error' => 'No tienes permiso'], 403);
    }

    private function post(Request $request)
    {
        return $request->isMethod('post') ? null : response()->json(['ok' => false, 'error' => 'Método no permitido'], 405);
    }

    /** Máximo de sugerencias que pide un agente por minuto: frena un bucle o un abuso que gastaría el tope mensual de todos. */
    public const MAX_POR_MINUTO = 30;

    private function demasiadas(Request $request)
    {
        $clave = 'ai-sugerir:' . (int) $request->user()?->id;
        if (RateLimiter::tooManyAttempts($clave, self::MAX_POR_MINUTO)) {
            return response()->json(['ok' => false, 'error' => 'Demasiadas sugerencias seguidas. Espera un minuto.'], 429);
        }
        RateLimiter::hit($clave, 60);
        return null;
    }

    private function suggest(Request $request, bool $regenerate)
    {
        if (! $this->ai->enabled()) {
            return response()->json(['ok' => true, 'enabled' => false, 'suggestion' => null]);
        }
        if ($limite = $this->demasiadas($request)) return $limite;
        $ticket = (int) ($request->input('ticket') ?: $request->query('ticket'));
        if (! TicketVisibility::puedeVer($request->user(), $ticket)) {
            return response()->json(['ok' => false, 'error' => 'Ticket no encontrado'], 404);
        }
        $s = $this->ai->forTicket($ticket, $request->user()->id, $regenerate);
        return response()->json(['ok' => true, 'enabled' => true, 'suggestion' => $s ? $this->salida($s) : null]);
    }

    /** Igual que suggest, pero cada paso de la IA (hotel, correo, antenas, memoria…) llega al momento, en una línea JSON. */
    private function stream(Request $request)
    {
        if (! $this->ai->enabled()) {
            return response()->json(['ok' => true, 'enabled' => false, 'suggestion' => null]);
        }
        if ($limite = $this->demasiadas($request)) return $limite;
        $ticket = (int) $request->query('ticket');
        if (! TicketVisibility::puedeVer($request->user(), $ticket)) {
            return response()->json(['ok' => false, 'error' => 'Ticket no encontrado'], 404);
        }
        $regen = $request->boolean('regen');
        $uid = $request->user()->id;
        return response()->stream(function () use ($ticket, $regen, $uid) {
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }
            $emitir = function (array $e) {
                echo json_encode($e, JSON_UNESCAPED_UNICODE) . "\n";
                @flush();
            };
            $this->ai->alPaso($emitir);
            try {
                $s = $this->ai->forTicket($ticket, $uid, $regen);
                $emitir(['fin' => ['suggestion' => $s ? $this->salida($s) : null]]);
            } catch (\Throwable $e) {
                report($e);
                $emitir(['error' => 'No se pudo preparar la sugerencia.']);
            } finally {
                $this->ai->alPaso(null);
            }
        }, 200, ['Content-Type' => 'application/x-ndjson; charset=utf-8', 'Cache-Control' => 'no-cache, no-store', 'X-Accel-Buffering' => 'no']);
    }

    private function rate(Request $request)
    {
        $s = $this->propia($request);
        if (! $s) {
            return response()->json(['ok' => false, 'error' => 'Sugerencia no encontrada'], 404);
        }
        try {
            $r = $this->ai->rate($s->id, (string) $request->input('rating'), $request->input('correction'), $request->user()->id, $request->input('reason'));
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
        return response()->json(['ok' => true, 'suggestion' => $this->salida($r)]);
    }

    private function used(Request $request)
    {
        $s = $this->propia($request);
        if (! $s) {
            return response()->json(['ok' => false, 'error' => 'Sugerencia no encontrada'], 404);
        }
        $this->ai->markUsed($s->id);
        return response()->json(['ok' => true]);
    }

    /** La sugerencia pedida, solo si el agente puede ver su ticket. */
    private function propia(Request $request): ?object
    {
        $s = DB::table('ai_suggestions')->where('id', (int) $request->input('id'))->first();
        return $s && TicketVisibility::puedeVer($request->user(), (int) $s->ticket_id) ? $s : null;
    }

    /** Lo que ve la pantalla: sin tokens ni errores internos. */
    private function salida(object $s): array
    {
        return [
            'id' => (int) $s->id, 'ticket_id' => (int) $s->ticket_id, 'status' => $s->status, 'body' => $s->body,
            'sources' => json_decode((string) $s->sources, true) ?: ['faqs' => [], 'examples' => []],
            'rating' => $s->rating, 'correction' => $s->correction, 'reason' => $s->reason ?? null, 'used' => $s->used_at !== null,
            'model' => $s->model, 'created_at' => $s->created_at,
            // Solo este motivo se enseña al agente; el resto de errores técnicos no salen del servidor.
            'limit_reached' => str_starts_with((string) ($s->error ?? ''), 'Se alcanzó el tope mensual'),
        ];
    }
}
