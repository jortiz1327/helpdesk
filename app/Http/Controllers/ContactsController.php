<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Portado de api/contacts.php — gestión y acciones en lote. Requiere token. */
class ContactsController extends Controller
{
    public function handle(Request $request)
    {
        $action = $request->query('action', '');
        $post   = $request->isMethod('post');

        return match (true) {
            $post && $action === 'bulk_label'     => $this->bulkLabel($request),
            $post && $action === 'set_optout'     => $this->setOptout($request),
            $post && $action === 'bulk_phonebook' => $this->bulkPhonebook($request),
            $post && $action === 'merge'          => $this->merge($request),
            $request->isMethod('get')             => $this->list($request),
            default => response()->json(['ok' => false, 'error' => 'Método no permitido'], 405),
        };
    }

    protected function ids(Request $r): array
    {
        return array_values(array_filter(array_map('intval', (array) $r->input('contact_ids', []))));
    }

    protected function bulkLabel(Request $r)
    {
        $ids   = $this->ids($r);
        $label = (int) $r->input('label_id');
        $mode  = $r->input('mode', 'add') === 'remove' ? 'remove' : 'add';
        if (!$ids || !$label) return response()->json(['ok' => false, 'error' => 'Faltan contactos o etiqueta'], 400);

        if ($mode === 'add') {
            $rows = array_map(fn ($cid) => ['contact_id' => $cid, 'label_id' => $label], $ids);
            $n = DB::table('contact_labels')->insertOrIgnore($rows);
            return response()->json(['ok' => true, 'changed' => $n]);
        }
        $n = DB::table('contact_labels')->where('label_id', $label)->whereIn('contact_id', $ids)->delete();
        return response()->json(['ok' => true, 'changed' => $n]);
    }

    protected function setOptout(Request $r)
    {
        $ids = $this->ids($r);
        $val = $r->boolean('value');
        if (!$ids) return response()->json(['ok' => false, 'error' => 'Faltan contactos'], 400);
        DB::table('contacts')->whereIn('id', $ids)->update(
            $val ? ['opted_out' => 1, 'opted_out_at' => now()]
                 : ['opted_out' => 0, 'opted_out_at' => null]
        );
        return response()->json(['ok' => true]);
    }

    protected function bulkPhonebook(Request $r)
    {
        $ids = $this->ids($r);
        $pb  = (int) $r->input('phonebook_id');
        if (!$ids || !$pb) return response()->json(['ok' => false, 'error' => 'Faltan contactos o agenda'], 400);

        $rows = DB::table('contacts')->whereIn('id', $ids)->get(['wa_id', 'name']);
        $insert = $rows->map(fn ($c) => ['phonebook_id' => $pb, 'wa_id' => $c->wa_id, 'name' => $c->name])->all();
        $added = $insert ? DB::table('phonebook_contacts')->insertOrIgnore($insert) : 0;
        return response()->json(['ok' => true, 'added' => $added]);
    }

    /**
     * FUSIONAR dos contactos en uno.
     *
     * Un mismo cliente puede acabar duplicado: escribe por WhatsApp (contacto con
     * teléfono) y otro día abre un ticket por correo (contacto con correo). No hay
     * ningún dato común para detectarlo solo, así que la fusión es manual.
     *
     * Todo lo que cuelga del contacto absorbido pasa al principal; los datos que al
     * principal le falten se rellenan con los del otro (los que ya tenga NO se pisan).
     * Va en transacción: o se hace entero, o no se hace.
     */
    protected function merge(Request $r)
    {
        $keepId  = (int) $r->input('keep_id');
        $mergeId = (int) $r->input('merge_id');

        if (!$keepId || !$mergeId) return response()->json(['ok' => false, 'error' => 'Faltan los contactos a fusionar'], 400);
        if ($keepId === $mergeId)  return response()->json(['ok' => false, 'error' => 'No se puede fusionar un contacto consigo mismo'], 400);

        $keep  = DB::table('contacts')->where('id', $keepId)->first();
        $merge = DB::table('contacts')->where('id', $mergeId)->first();
        if (!$keep || !$merge) return response()->json(['ok' => false, 'error' => 'Contacto no encontrado'], 404);

        DB::transaction(function () use ($keep, $merge, $keepId, $mergeId) {
            // 1) Todo lo que cuelga del contacto pasa al principal.
            foreach (['messages', 'tickets', 'flow_sessions', 'flow_responses', 'form_submissions'] as $t) {
                DB::table($t)->where('contact_id', $mergeId)->update(['contact_id' => $keepId]);
            }
            // Etiquetas: clave compuesta (contact_id,label_id) → insertOrIgnore evita chocar
            // si ambos ya comparten una etiqueta.
            $labels = DB::table('contact_labels')->where('contact_id', $mergeId)->pluck('label_id');
            foreach ($labels as $lid) {
                DB::table('contact_labels')->insertOrIgnore(['contact_id' => $keepId, 'label_id' => $lid]);
            }
            DB::table('contact_labels')->where('contact_id', $mergeId)->delete();

            // 2) Rellenar SOLO los huecos del principal (lo suyo manda).
            $upd = [];
            foreach (['name', 'email', 'wa_id', 'country_code', 'note'] as $col) {
                if (empty($keep->$col) && !empty($merge->$col)) $upd[$col] = $merge->$col;
            }
            // Se conserva la actividad más reciente de los dos.
            if (!empty($merge->last_time) && (empty($keep->last_time) || $merge->last_time > $keep->last_time)) {
                $upd['last_time']    = $merge->last_time;
                $upd['last_message'] = $merge->last_message;
            }
            $upd['unread'] = (int) $keep->unread + (int) $merge->unread;
            // Si cualquiera de los dos se dio de baja, la baja manda (más conservador).
            if ((int) $merge->opted_out === 1) $upd['opted_out'] = 1;

            // El wa_id es único: hay que liberarlo del absorbido ANTES de copiarlo.
            DB::table('contacts')->where('id', $mergeId)->update(['wa_id' => null]);
            DB::table('contacts')->where('id', $keepId)->update($upd);

            // 3) Fuera el duplicado.
            DB::table('contacts')->where('id', $mergeId)->delete();
        });

        return response()->json(['ok' => true]);
    }

    protected function list(Request $r)
    {
        $q       = trim((string) $r->query('q', ''));
        $labelId = (int) $r->query('label', 0);
        $optout  = $r->query('optout', ''); // '', '1' solo bajas, '0' solo activos

        $where = [];
        $params = [];
        // Búsqueda por nombre, número O nombre de etiqueta (útil con muchas etiquetas de sedes).
        if ($q !== '') {
            $where[] = '(c.name LIKE ? OR c.wa_id LIKE ? OR c.email LIKE ? OR EXISTS (
                SELECT 1 FROM contact_labels cl3 JOIN labels l3 ON l3.id = cl3.label_id
                WHERE cl3.contact_id = c.id AND l3.name LIKE ?))';
            $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%";
        }

        /*
         * SEPARACIÓN POR ÁREA (por actividad):
         *   · campaigns → contactos con mensajes de WhatsApp (a los que se difunde).
         *   · helpdesk  → contactos que han abierto algún ticket (los de soporte).
         * Sin `area` se listan todos (compatibilidad).
         */
        $area = $r->query('area', '');
        if ($area === 'campaigns') {
            $where[] = "EXISTS (SELECT 1 FROM messages mw WHERE mw.contact_id = c.id AND mw.channel = 'whatsapp')";
        } elseif ($area === 'helpdesk') {
            $where[] = 'EXISTS (SELECT 1 FROM tickets tk WHERE tk.contact_id = c.id)';
        }
        if ($labelId) { $where[] = 'EXISTS (SELECT 1 FROM contact_labels cl2 WHERE cl2.contact_id = c.id AND cl2.label_id = ?)'; $params[] = $labelId; }
        if ($optout === '1') $where[] = 'c.opted_out = 1';
        elseif ($optout === '0') $where[] = 'c.opted_out = 0';

        $sql = 'SELECT c.id, c.wa_id, c.country_code, c.email, c.name, c.last_message, c.last_time, c.opted_out FROM contacts c';
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        // Tope de seguridad: el volumen real es ~1-2k contactos, 3000 da margen de sobra.
        $sql .= ' ORDER BY c.last_time IS NULL, c.last_time DESC, c.id DESC LIMIT 3000';

        $contacts = DB::select($sql, $params);

        if ($contacts) {
            $ids = array_map(fn ($c) => $c->id, $contacts);
            $ls = DB::table('contact_labels as cl')
                ->join('labels as l', 'l.id', '=', 'cl.label_id')
                ->whereIn('cl.contact_id', $ids)
                ->get(['cl.contact_id', 'l.id', 'l.name', 'l.color']);
            $byContact = [];
            foreach ($ls as $row) {
                $byContact[$row->contact_id][] = ['id' => (int) $row->id, 'name' => $row->name, 'color' => $row->color];
            }
            foreach ($contacts as $c) $c->labels = $byContact[$c->id] ?? [];
        }
        return response()->json(['ok' => true, 'contacts' => $contacts]);
    }
}
