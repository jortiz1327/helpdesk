<?php

namespace App\Http\Controllers;

use App\Models\TicketCustomField;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Campos personalizados de ticket (globales). Definirlos/borrarlos → support.config
 * (comprobado dentro). Rellenar los valores de un ticket → helpdesk.access (permiso de
 * la ruta).
 */
class TicketFieldsController extends Controller
{
    public function handle(Request $request)
    {
        return match ($request->query('action', 'defs')) {
            'defs'        => $this->defs($request),
            'save_def'    => $this->saveDef($request),
            'delete_def'  => $this->deleteDef($request),
            'save_values' => $this->saveValues($request),
            default       => response()->json(['ok' => false, 'error' => 'Acción no válida'], 400),
        };
    }

    /** Catálogo de definiciones (para la pantalla de gestión). */
    protected function defs(Request $request)
    {
        if (!$request->user()->can('support.config')) {
            return response()->json(['ok' => false, 'error' => 'Sin permiso'], 403);
        }
        $rows = TicketCustomField::orderBy('position')->orderBy('id')->get();
        // Cuántos tickets tienen valor en cada campo (para avisar antes de borrar).
        $uso = DB::table('ticket_field_values')->select('field_id', DB::raw('COUNT(*) n'))
            ->whereNotNull('value')->where('value', '!=', '')->groupBy('field_id')->pluck('n', 'field_id');
        foreach ($rows as $r) $r->used = (int) ($uso[$r->id] ?? 0);

        return response()->json(['ok' => true, 'fields' => $rows, 'types' => TicketCustomField::TIPOS]);
    }

    /** Crea o edita una definición. */
    protected function saveDef(Request $request)
    {
        if (!$request->user()->can('support.config')) {
            return response()->json(['ok' => false, 'error' => 'Sin permiso'], 403);
        }

        $label = trim((string) $request->input('label'));
        if ($label === '') return response()->json(['ok' => false, 'error' => 'La etiqueta es obligatoria'], 400);

        $type = (string) $request->input('type', 'text');
        if (!in_array($type, TicketCustomField::TIPOS, true)) $type = 'text';

        // Opciones solo para desplegable: lista de textos no vacíos.
        $options = null;
        if ($type === 'select') {
            $options = array_values(array_filter(array_map(
                fn ($o) => mb_substr(trim((string) $o), 0, 120),
                (array) $request->input('options', [])
            ), fn ($o) => $o !== ''));
            if (!$options) return response()->json(['ok' => false, 'error' => 'Un desplegable necesita al menos una opción'], 400);
        }

        $id  = (int) $request->input('id');
        $cur = $id ? TicketCustomField::find($id) : null;
        if ($id && !$cur) return response()->json(['ok' => false, 'error' => 'Campo no encontrado'], 404);

        $key = $cur?->key ?: Str::slug($label, '_');
        if ($key === '') $key = 'campo_' . Str::random(6);
        if (!$cur && TicketCustomField::where('key', $key)->exists()) $key .= '_' . Str::random(4);

        $data = [
            'key'      => $key,
            'label'    => mb_substr($label, 0, 120),
            'type'     => $type,
            'options'  => $options,
            'required' => filter_var($request->input('required', false), FILTER_VALIDATE_BOOLEAN),
            'position' => (int) $request->input('position', 0),
            'active'   => filter_var($request->input('active', true), FILTER_VALIDATE_BOOLEAN),
        ];

        $cur ? $cur->update($data) : TicketCustomField::create($data + ['created_at' => now()]);
        return response()->json(['ok' => true]);
    }

    /** Borra una definición (y sus valores por cascade). */
    protected function deleteDef(Request $request)
    {
        if (!$request->user()->can('support.config')) {
            return response()->json(['ok' => false, 'error' => 'Sin permiso'], 403);
        }
        TicketCustomField::where('id', (int) $request->input('id'))->delete();
        return response()->json(['ok' => true]);
    }

    /** Guarda los valores de los campos de un ticket. */
    protected function saveValues(Request $request)
    {
        $ticketId = (int) $request->input('ticket_id');
        if (!DB::table('tickets')->where('id', $ticketId)->exists()) {
            return response()->json(['ok' => false, 'error' => 'Ticket no encontrado'], 404);
        }

        $valores = (array) $request->input('values', []);   // { field_id: value }
        $defs = TicketCustomField::where('active', true)->get(['id', 'label', 'type', 'required']);

        foreach ($defs as $d) {
            $raw = $valores[$d->id] ?? null;
            // Normaliza según el tipo.
            if ($d->type === 'checkbox') {
                $v = filter_var($raw, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
            } else {
                $v = $raw === null ? null : mb_substr(trim((string) $raw), 0, 5000);
                if ($v === '') $v = null;
            }

            if ($d->required && $d->type !== 'checkbox' && ($v === null || $v === '')) {
                return response()->json(['ok' => false, 'error' => "El campo «{$d->label}» es obligatorio"], 422);
            }

            DB::table('ticket_field_values')->updateOrInsert(
                ['ticket_id' => $ticketId, 'field_id' => $d->id],
                ['value' => $v]
            );
        }

        return response()->json(['ok' => true]);
    }
}
