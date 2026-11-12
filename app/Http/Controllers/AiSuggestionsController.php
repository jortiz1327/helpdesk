<?php

namespace App\Http\Controllers;

use App\Services\Ai\AiSuggestionService;
use App\Support\TicketVisibility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Sugerencias de la IA en la ficha del ticket. Requiere helpdesk.access (ruta) y que el
 * agente pueda ver ese ticket. La IA nunca envía nada: solo propone; quien responde es la persona.
 *
 *   GET  ?action=status                  → ¿está activa?
 *   GET  ?action=suggest&ticket=ID       → la sugerencia vigente (la genera si no existe)
 *   POST ?action=regenerate  ticket      → pide otra
 *   POST ?action=rate  id, rating, correction → 👍 / 👎 (con corrección obligatoria)
 *   POST ?action=used  id                → el agente la usó de base
 *   GET  ?action=admin                   → panel de Ajustes: estado, acierto, conocimiento (support.config)
 *   POST ?action=admin_save  enabled     → enciende/apaga el asistente (support.config)
 *   POST ?action=admin_index             → actualiza la base de conocimiento (support.config)
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
            'regenerate' => $this->post($request) ?? $this->suggest($request, true),
            'rate'       => $this->post($request) ?? $this->rate($request),
            'used'       => $this->post($request) ?? $this->used($request),
            'admin'      => $this->admin($request),
            'lab_cases'  => $this->labCases($request),
            'lab_generate' => $this->post($request) ?? $this->labGenerate($request),
            'admin_save' => $this->post($request) ?? $this->adminSave($request),
            'admin_index' => $this->post($request) ?? $this->adminIndex($request),
            default      => response()->json(['ok' => true, 'enabled' => $this->ai->enabled()]),
        };
    }

    /** Estado del asistente para Ajustes. Nunca devuelve la clave: solo si hay una. */
    private function admin(Request $request)
    {
        if ($denegado = $this->soloConfig($request)) return $denegado;
        return response()->json(['ok' => true] + $this->ai->panel());
    }

    /** Laboratorio (solo APP_ENV=local): casos ya resueltos para validar la IA de un vistazo. */
    private function labCases(Request $request)
    {
        if ($denegado = $this->soloLab($request)) return $denegado;
        $cases = $request->query('modo') === 'corregidos'
            ? $this->ai->labCorregidos()
            : $this->ai->labCasos(max(1, min(20, (int) $request->query('n', 10))));
        foreach ($cases as &$c) {
            $c['suggestion'] = $c['suggestion'] ? $this->salida($c['suggestion']) : null;
        }
        return response()->json(['ok' => true, 'cases' => $cases]);
    }

    private function labGenerate(Request $request)
    {
        if ($denegado = $this->soloLab($request)) return $denegado;
        $ticket = (int) $request->input('ticket');
        $msg = (int) $request->input('message');
        if (! DB::table('messages')->where('id', $msg)->where('ticket_id', $ticket)->where('direction', 'in')->exists()) {
            return response()->json(['ok' => false, 'error' => 'Caso no válido'], 404);
        }
        $s = $this->ai->forMensaje($ticket, $msg, $request->user()->id, filter_var($request->input('regenerate'), FILTER_VALIDATE_BOOLEAN));
        return response()->json(['ok' => true, 'suggestion' => $this->salida($s)]);
    }

    private function soloLab(Request $request)
    {
        if (! $this->ai->labActivo()) return response()->json(['ok' => false, 'error' => 'No disponible'], 404);
        if (! $this->ai->enabled()) return response()->json(['ok' => false, 'error' => 'El asistente está apagado'], 422);
        return $this->soloConfig($request);
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

    private function adminIndex(Request $request)
    {
        if ($denegado = $this->soloConfig($request)) return $denegado;
        $k = app(\App\Services\Ai\AiKnowledge::class);
        [$nuevos] = $k->indexarTickets();
        $trozos = $k->indexarManuales();
        return response()->json(['ok' => true, 'nuevos' => $nuevos, 'trozos' => $trozos] + $this->ai->panel());
    }

    private function soloConfig(Request $request)
    {
        return $request->user()?->can('support.config') ? null : response()->json(['ok' => false, 'error' => 'No tienes permiso'], 403);
    }

    private function post(Request $request)
    {
        return $request->isMethod('post') ? null : response()->json(['ok' => false, 'error' => 'Método no permitido'], 405);
    }

    private function suggest(Request $request, bool $regenerate)
    {
        if (! $this->ai->enabled()) {
            return response()->json(['ok' => true, 'enabled' => false, 'suggestion' => null]);
        }
        $ticket = (int) ($request->input('ticket') ?: $request->query('ticket'));
        if (! TicketVisibility::puedeVer($request->user(), $ticket)) {
            return response()->json(['ok' => false, 'error' => 'Ticket no encontrado'], 404);
        }
        $s = $this->ai->forTicket($ticket, $request->user()->id, $regenerate);
        return response()->json(['ok' => true, 'enabled' => true, 'suggestion' => $s ? $this->salida($s) : null]);
    }

    private function rate(Request $request)
    {
        $s = $this->propia($request);
        if (! $s) {
            return response()->json(['ok' => false, 'error' => 'Sugerencia no encontrada'], 404);
        }
        try {
            $r = $this->ai->rate($s->id, (string) $request->input('rating'), $request->input('correction'), $request->user()->id);
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
            'rating' => $s->rating, 'correction' => $s->correction, 'used' => $s->used_at !== null,
        ];
    }
}
