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
        $me     = $request->user();

        // Escribir exige contacts.edit (la ruta solo pide contacts.access, que es «ver»).
        if ($post && !$me->can('contacts.edit')) {
            return response()->json(['ok' => false, 'error' => 'No tienes permiso para editar contactos'], 403);
        }
        // Etiquetas, bajas y agendas son de CAMPAÑAS: un usuario solo de Helpdesk no las toca.
        if ($post && in_array($action, ['bulk_label', 'set_optout', 'bulk_phonebook'], true) && !$me->can('campaigns.access')) {
            return response()->json(['ok' => false, 'error' => 'Esta acción es de Campañas'], 403);
        }

        return match (true) {
            $action === 'duplicates'              => $this->duplicados($request),
            $action === 'merge_preview'           => $this->vistaFusion($request),
            $post && $action === 'ignore_duplicate' => $this->ignorarDuplicado($request),
            $post && $action === 'bulk_label'     => $this->bulkLabel($request),
            $post && $action === 'set_optout'     => $this->setOptout($request),
            $post && $action === 'bulk_phonebook' => $this->bulkPhonebook($request),
            $post && $action === 'merge'          => $this->merge($request),
            $request->isMethod('get')             => $this->list($request),
            default => response()->json(['ok' => false, 'error' => 'Método no permitido'], 405),
        };
    }

    /**
     * ¿El contacto `c` es de CAMPAÑAS? Si ha hablado con el WhatsApp de Campañas, tiene
     * alguna etiqueta (las etiquetas son de Campañas), o tiene número y NO es del Helpdesk
     * (p. ej. alta a mano). Tener teléfono no basta: un ticket del portal con teléfono o un
     * cliente del WhatsApp de soporte NO es de Campañas. Única definición: la usan esta
     * lista y la importación a agendas («Todos mis contactos»).
     */
    public const SQL_CAMPANAS = "(EXISTS (SELECT 1 FROM messages mw WHERE mw.contact_id = c.id AND mw.channel = 'whatsapp' AND mw.funcion = 'campanas')
                OR EXISTS (SELECT 1 FROM contact_labels clc WHERE clc.contact_id = c.id)
                OR (c.wa_id IS NOT NULL
                    AND NOT EXISTS (SELECT 1 FROM tickets tk WHERE tk.contact_id = c.id)
                    AND NOT EXISTS (SELECT 1 FROM messages ms WHERE ms.contact_id = c.id AND ms.funcion = 'soporte')))";

    /**
     * POSIBLES DUPLICADOS: la misma persona en dos fichas. Lo inequívoco (mismo correo o
     * mismo teléfono) ya no puede pasar —índices únicos y teléfono normalizado—, así que
     * aquí queda lo que una persona tiene que confirmar: mismo nombre y una ficha solo con
     * teléfono (WhatsApp) y otra solo con correo. Sin los pares marcados como «no son la
     * misma persona». Cada uno ve solo pares de las áreas a las que tiene acceso.
     */
    protected function duplicados(Request $r)
    {
        $me = $r->user();
        if (!$me->can('contacts.edit')) return response()->json(['ok' => true, 'pairs' => []]);

        $norm = fn ($s) => trim(preg_replace('/[^a-z0-9 ]+/', '', preg_replace('/\s+/', ' ', mb_strtolower(\Illuminate\Support\Str::ascii((string) $s)))));
        $cs = DB::table('contacts')->whereNotNull('name')->where('name', '<>', '')
            ->where(fn ($w) => $w->whereNull('email')->orWhere('email', '')->orWhereNull('wa_id')->orWhere('wa_id', ''))
            ->get(['id', 'name', 'email', 'wa_id', 'created_at']);
        $soloTel  = $cs->filter(fn ($c) => $c->wa_id && !$c->email && mb_strlen($norm($c->name)) >= 4);
        $soloMail = $cs->filter(fn ($c) => $c->email && !$c->wa_id)->groupBy(fn ($c) => $norm($c->name));

        $ignorados = DB::table('contact_duplicate_ignores')->get(['contact_a', 'contact_b'])
            ->map(fn ($i) => $i->contact_a . '-' . $i->contact_b)->flip();

        $pares = [];
        foreach ($soloTel as $t) {
            foreach ($soloMail->get($norm($t->name), []) as $e) {
                [$a, $b] = $t->id < $e->id ? [$t->id, $e->id] : [$e->id, $t->id];
                if (isset($ignorados["$a-$b"])) continue;
                $pares[] = [$t, $e, str_contains($norm($t->name), ' ') ? 'nombre' : 'nombre_corto'];
            }
        }
        if (!$pares) return response()->json(['ok' => true, 'pairs' => []]);

        // Datos de cada ficha + a qué área pertenece (para no enseñar clientes de otra área).
        $ids = collect($pares)->flatMap(fn ($p) => [$p[0]->id, $p[1]->id])->unique()->values();
        $tickets = DB::table('tickets')->whereIn('contact_id', $ids)->groupBy('contact_id')->selectRaw('contact_id, COUNT(*) n')->pluck('n', 'contact_id');
        $soporte = DB::table('messages')->whereIn('contact_id', $ids)->where('funcion', 'soporte')->distinct()->pluck('contact_id')->flip();
        $puedeVer = fn ($c) => (isset($tickets[$c->id]) || isset($soporte[$c->id])) ? $me->can('helpdesk.access') : $me->can('campaigns.access');
        $recientes = $this->ticketsRecientes($ids->all(), $me);
        $ficha = fn ($c) => ['id' => (int) $c->id, 'name' => $c->name, 'email' => $c->email, 'wa_id' => $c->wa_id,
            'tickets' => (int) ($tickets[$c->id] ?? 0), 'created_at' => $c->created_at,
            'recent' => $recientes[$c->id] ?? []];

        $out = [];
        foreach ($pares as [$t, $e, $motivo]) {
            if (!$puedeVer($t) || !$puedeVer($e)) continue;
            $out[] = ['a' => $ficha($e), 'b' => $ficha($t), 'motivo' => $motivo];
        }
        return response()->json(['ok' => true, 'pairs' => $out]);
    }

    /**
     * Últimos tickets (4) de cada contacto, para comprobar antes de fusionar que es la
     * misma persona (un nombre solo no basta: hay muchos «Juan Pérez»). Solo los que este
     * agente puede ver, y nada sin acceso al Helpdesk. [contact_id => [tickets]]
     */
    protected function ticketsRecientes(array $ids, $me): \Illuminate\Support\Collection
    {
        if (!$ids || !$me->can('helpdesk.access')) return collect();
        return \App\Support\TicketVisibility::scope(DB::table('tickets as t')->whereIn('t.contact_id', $ids)->whereNull('t.merged_into_id'), $me)
            ->orderByDesc('t.id')->get(['t.contact_id', 't.code', 't.subject', 't.channel', 't.status', 't.created_at'])
            ->groupBy('contact_id')->map(fn ($g) => $g->take(4)->values());
    }

    /** Para el diálogo de fusionar abierto desde la lista: nº de tickets y los últimos de cada uno. */
    protected function vistaFusion(Request $r)
    {
        $me  = $r->user();
        $ids = array_slice(array_values(array_filter(array_map('intval', explode(',', (string) $r->query('ids', ''))))), 0, 2);
        if (count($ids) !== 2 || !$me->can('contacts.edit')) return response()->json(['ok' => true, 'contacts' => []]);
        $n = $me->can('helpdesk.access')
            ? DB::table('tickets')->whereIn('contact_id', $ids)->groupBy('contact_id')->selectRaw('contact_id, COUNT(*) n')->pluck('n', 'contact_id')
            : collect();
        $rec = $this->ticketsRecientes($ids, $me);
        $out = [];
        foreach ($ids as $id) $out[$id] = ['tickets' => (int) ($n[$id] ?? 0), 'recent' => $rec[$id] ?? []];
        return response()->json(['ok' => true, 'contacts' => $out]);
    }

    /** «No son la misma persona»: ese par deja de sugerirse. */
    protected function ignorarDuplicado(Request $r)
    {
        $a = (int) $r->input('a'); $b = (int) $r->input('b');
        if (!$a || !$b || $a === $b) return response()->json(['ok' => false, 'error' => 'Faltan los contactos'], 400);
        [$a, $b] = $a < $b ? [$a, $b] : [$b, $a];
        DB::table('contact_duplicate_ignores')->insertOrIgnore(['contact_a' => $a, 'contact_b' => $b, 'user_id' => $r->user()->id]);
        return response()->json(['ok' => true]);
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
            \App\Models\Label::quitarHermanas($ids, $label);   // excluyentes: fuera las del mismo grupo
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
        // Solo los que CAMBIAN quedan en el registro de consentimientos (RGPD).
        $cambian = DB::table('contacts')->whereIn('id', $ids)->where('opted_out', $val ? 0 : 1)->pluck('id');
        DB::table('contacts')->whereIn('id', $ids)->update(
            $val ? ['opted_out' => 1, 'opted_out_at' => now()]
                 : ['opted_out' => 0, 'opted_out_at' => null]
        );
        foreach ($cambian as $cid) \App\Services\ConsentLog::anotar((int) $cid, $val ? 'optout' : 'optin', 'agente', (int) $r->user()->id);
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

        // Fusionar MUEVE tickets: si alguno tiene tickets, hace falta acceso al Helpdesk.
        if (!$r->user()->can('helpdesk.access') && DB::table('tickets')->whereIn('contact_id', [$keepId, $mergeId])->exists()) {
            return response()->json(['ok' => false, 'error' => 'Estos contactos tienen tickets: solo se pueden fusionar desde el Helpdesk'], 403);
        }

        app(\App\Services\ContactMergeService::class)->fusionar($keepId, $mergeId);
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
        // El ÁREA la decide el servidor según los permisos, no el navegador: quien solo
        // tiene Campañas ve los de Campañas (no los clientes de los tickets) y al revés.
        $me = $r->user();
        if (!$me->can('campaigns.access')) $area = 'helpdesk';
        elseif (!$me->can('helpdesk.access')) $area = 'campaigns';
        if ($area === 'campaigns') {
            $where[] = self::SQL_CAMPANAS;
        } elseif ($area === 'helpdesk') {
            $where[] = 'EXISTS (SELECT 1 FROM tickets tk WHERE tk.contact_id = c.id)';
        }
        if ($labelId) { $where[] = 'EXISTS (SELECT 1 FROM contact_labels cl2 WHERE cl2.contact_id = c.id AND cl2.label_id = ?)'; $params[] = $labelId; }
        if ($optout === '1') $where[] = 'c.opted_out = 1';
        elseif ($optout === '0') $where[] = 'c.opted_out = 0';

        $sql = 'SELECT c.id, c.wa_id, c.country_code, c.email, c.name, c.empresa, c.tienda, c.cargo, c.provincia, c.comentarios, c.last_message, c.last_time, c.opted_out, c.sede_id FROM contacts c';
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY c.last_time IS NULL, c.last_time DESC, c.id DESC';

        /*
         * PAGINACIÓN OPT-IN: con `page` se sirve una página de 50 (para las listas con
         * scroll, que antes traían hasta 3000 de golpe). Sin `page` se mantiene el
         * comportamiento de siempre (todo, tope 3000) para el TABLERO Kanban, que
         * necesita todas las tarjetas a la vez. Se pide una fila de más para saber si
         * quedan más sin un COUNT aparte.
         */
        $pageRaw = $r->query('page', null);
        $paginado = $pageRaw !== null && $pageRaw !== '';
        $size = 50;
        if ($paginado) {
            $page = max(0, (int) $pageRaw);
            $sql .= ' LIMIT ' . ($size + 1) . ' OFFSET ' . ($page * $size);
        } else {
            $sql .= ' LIMIT 3000';
        }

        $contacts = DB::select($sql, $params);
        $more = false;
        if ($paginado && count($contacts) > $size) { $more = true; array_pop($contacts); }

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
        return response()->json(['ok' => true, 'contacts' => $contacts, 'more' => $more]);
    }
}
