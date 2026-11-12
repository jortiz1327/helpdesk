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
            default      => response()->json(['ok' => true, 'enabled' => $this->ai->enabled()]),
        };
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
