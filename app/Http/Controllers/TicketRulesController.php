<?php

namespace App\Http\Controllers;

use App\Models\TicketRule;
use App\Services\TicketService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Reglas automáticas de tickets («Flujo de trabajo» de osTicket).
 * Vive en «Configuración de soporte». Requiere support.config.
 */
class TicketRulesController extends Controller
{
    public function handle(Request $request)
    {
        return match ($request->query('action', 'list')) {
            'save'   => $this->save($request),
            'delete' => $this->delete($request),
            default  => $this->list(),
        };
    }

    protected function list()
    {
        return response()->json([
            'rules'      => TicketRule::orderBy('position')->orderBy('id')->get(),
            // Catálogos para construir la pantalla sin quemar valores en el frontend.
            'fields'     => TicketRule::FIELDS,
            'ops'        => TicketRule::OPS,
            'channels'   => TicketRule::CHANNELS,
            'priorities' => TicketService::priorities(),
            'categories' => DB::table('ticket_categories')->orderBy('position')->get(['id', 'name']),
            'agents'     => DB::table('users')->orderBy('name')->get(['id', 'name', 'email']),
        ]);
    }

    protected function save(Request $request)
    {
        $name = trim((string) $request->input('name'));
        if ($name === '') return response()->json(['ok' => false, 'error' => 'El nombre es obligatorio'], 400);

        // Condiciones: se limpian y se validan campo/operador contra la lista blanca.
        $conds = [];
        foreach ((array) $request->input('conditions', []) as $c) {
            $field = (string) ($c['field'] ?? '');
            $op    = (string) ($c['op'] ?? '');
            $value = trim((string) ($c['value'] ?? ''));
            if ($value === '' || !isset(TicketRule::FIELDS[$field]) || !isset(TicketRule::OPS[$op])) continue;
            $conds[] = ['field' => $field, 'op' => $op, 'value' => mb_substr($value, 0, 190)];
        }
        if (!$conds) return response()->json(['ok' => false, 'error' => 'Añade al menos una condición válida'], 400);

        // Acciones: al menos una, si no la regla no haría nada.
        $actions = [];
        if ($a = (int) $request->input('actions.assign_to'))   $actions['assign_to'] = $a;
        if ($c = (int) $request->input('actions.category_id')) $actions['category_id'] = $c;
        $prio = (string) $request->input('actions.priority');
        if ($prio !== '' && array_key_exists($prio, TicketService::priorities())) $actions['priority'] = $prio;
        if (!$actions) return response()->json(['ok' => false, 'error' => 'Elige al menos una acción'], 400);

        $channel = (string) $request->input('channel', 'any');
        $match   = $request->input('match') === 'all' ? 'all' : 'any';

        $data = [
            'name'       => mb_substr($name, 0, 120),
            'active'     => filter_var($request->input('active', true), FILTER_VALIDATE_BOOLEAN),
            'position'   => (int) $request->input('position', 0),
            'channel'    => isset(TicketRule::CHANNELS[$channel]) ? $channel : 'any',
            'match'      => $match,
            'conditions' => $conds,
            'actions'    => $actions,
            'stop'       => filter_var($request->input('stop', false), FILTER_VALIDATE_BOOLEAN),
        ];

        $id = (int) $request->input('id');
        $id ? TicketRule::where('id', $id)->update($data) : TicketRule::create($data);

        return response()->json(['ok' => true]);
    }

    protected function delete(Request $request)
    {
        TicketRule::where('id', (int) $request->input('id'))->delete();
        return response()->json(['ok' => true]);
    }
}
