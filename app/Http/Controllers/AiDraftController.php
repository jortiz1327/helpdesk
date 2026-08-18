<?php

namespace App\Http\Controllers;

use App\Services\ClaudeBrain;
use Illuminate\Http\Request;

/**
 * Borrador de la IA a demanda: el agente pulsa «Sugerir respuesta» en la ficha
 * del ticket y aquí se genera la propuesta (modo borrador). No envía nada al
 * cliente: solo devuelve el texto para que el agente lo revise en el compositor.
 *
 * Requiere tickets.reply (quien puede responder, puede pedir una sugerencia).
 */
class AiDraftController extends Controller
{
    public function handle(Request $request, ClaudeBrain $brain)
    {
        $ticketId = (int) $request->input('ticket_id', $request->query('ticket_id', 0));
        if (!$ticketId) {
            return response()->json(['ok' => false, 'error' => 'Falta el ticket.'], 400);
        }

        return response()->json($brain->sugerir($ticketId));
    }
}
