<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Portado de api/conversations.php — requiere token. Dispatch por ?action=. */
class ConversationController extends Controller
{
    public function handle(Request $request)
    {
        $action = $request->query('action', 'list');
        $post   = $request->isMethod('post');

        return match (true) {
            $action === 'mark'   && $post => $this->mark($request),
            $action === 'agents'          => $this->agents(),
            $action === 'assign' && $post => $this->assign($request),
            $action === 'delete' && $post => $this->delete($request),
            $action === 'list'            => $this->list($request),
            $action === 'messages'        => $this->messages($request),
            $action === 'poll'            => $this->poll($request),
            default => response()->json(['error' => 'Acción no válida'], 400),
        };
    }

    protected function mark(Request $r)
    {
        $id = (int) $r->input('contact_id');
        if (!$id) return response()->json(['ok' => false, 'error' => 'Falta contact_id'], 400);
        DB::update('UPDATE contacts SET unread = ? WHERE id = ?', [$r->boolean('read') ? 0 : 1, $id]);
        return response()->json(['ok' => true]);
    }

    /**
     * Agentes a los que se puede asignar una conversación del Chat en vivo. Como el
     * Chat en vivo es de CAMPAÑAS, se asigna a gente de Campañas (permiso
     * campaigns.access), NO a los agentes de soporte. Y solo usuarios ACTIVOS: los
     * ex-empleados (active=0) no deben salir como destinatarios.
     */
    protected function agents()
    {
        $agents = User::where(fn ($q) => $q->whereNull('active')->orWhere('active', true))
            ->orderByRaw('name IS NULL, name ASC, email ASC')->get()
            ->filter(fn ($u) => $u->can('campaigns.access'))
            ->map(fn ($u) => [
                'id'       => (int) $u->id,
                'name'     => $u->name,
                'email'    => $u->email,
                'role'     => $u->roleName(),
            ])
            ->values();

        return response()->json(['ok' => true, 'agents' => $agents]);
    }

    protected function assign(Request $r)
    {
        $id  = (int) $r->input('contact_id');
        $uid = (int) $r->input('user_id');
        if (!$id) return response()->json(['ok' => false, 'error' => 'Falta contact_id'], 400);
        // Al desasignar se reactiva el bot (pudo pausarse en una transferencia).
        if ($uid) {
            DB::update('UPDATE contacts SET assigned_to = ? WHERE id = ?', [$uid, $id]);
        } else {
            DB::update('UPDATE contacts SET assigned_to = NULL, bot_off = 0 WHERE id = ?', [$id]);
        }
        return response()->json(['ok' => true]);
    }

    protected function delete(Request $r)
    {
        $id = (int) $r->input('contact_id');
        if (!$id) return response()->json(['ok' => false, 'error' => 'Falta contact_id'], 400);

        $optedOut = (int) DB::table('contacts')->where('id', $id)->value('opted_out');

        DB::delete('DELETE FROM messages WHERE contact_id = ?', [$id]);
        DB::delete('DELETE FROM contact_labels WHERE contact_id = ?', [$id]);

        // RGPD: si el contacto se dio de BAJA, NO se borra aunque se limpie el chat —
        // hay que seguir respetando la baja si vuelve a escribir. Se conserva como
        // registro de supresión (número + opted_out); sin mensajes ya no aparece en el
        // «Chat en vivo» (el inbox solo lista contactos con mensajes de campañas), y se
        // vacía el resumen para que quede limpio. Si NO está de baja, se borra del todo.
        if ($optedOut === 1) {
            DB::update('UPDATE contacts SET last_message = NULL, last_time = NULL, unread = 0, assigned_to = NULL WHERE id = ?', [$id]);
            return response()->json(['ok' => true, 'suppressed' => true]);
        }

        DB::delete('DELETE FROM contacts WHERE id = ?', [$id]);
        return response()->json(['ok' => true]);
    }

    protected function list(Request $r)
    {
        $q        = trim((string) $r->query('q', ''));
        $assigned = $r->query('assigned', ''); // '', 'me', 'none'

        $sql = 'SELECT c.id, c.wa_id, c.name, c.last_message, c.last_time, c.unread, c.assigned_to, u.name AS assignee_name
                FROM contacts c LEFT JOIN users u ON u.id = c.assigned_to';
        // SEPARACIÓN DE CANALES: el «Chat en vivo» es SOLO WhatsApp. Los contactos
        // que solo tienen correo/soporte (tickets) NO aparecen aquí — su sitio es el
        // Helpdesk. Se listan únicamente contactos con al menos un mensaje de WhatsApp.
        // Solo conversaciones de CAMPAÑAS (el WhatsApp de soporte va al Helpdesk, no aquí).
        $where = ["EXISTS (SELECT 1 FROM messages mw WHERE mw.contact_id = c.id AND mw.channel = 'whatsapp' AND mw.funcion = 'campanas')"];
        $params = [];
        // Búsqueda por nombre, número O nombre de etiqueta.
        if ($q !== '') {
            $where[] = '(c.name LIKE ? OR c.wa_id LIKE ? OR EXISTS (
                SELECT 1 FROM contact_labels cl3 JOIN labels l3 ON l3.id = cl3.label_id
                WHERE cl3.contact_id = c.id AND l3.name LIKE ?))';
            $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%";
        }
        if ($assigned === 'me')   { $where[] = 'c.assigned_to = ?'; $params[] = $r->user()->id; }
        elseif ($assigned === 'none') { $where[] = 'c.assigned_to IS NULL'; }
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY (c.last_time IS NULL), c.last_time DESC, c.id DESC';

        /*
         * PAGINACIÓN OPT-IN (igual que Contactos): con `page` se sirve una página de 50
         * para el inbox con scroll; sin `page`, todo (tope 3000) para el Kanban. Se pide
         * una fila de más para saber si quedan más.
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

        $rows = DB::select($sql, $params);
        $more = false;
        if ($paginado && count($rows) > $size) { $more = true; array_pop($rows); }

        // adjuntar etiquetas
        $byId = [];
        foreach ($rows as $row) { $row->labels = []; $byId[$row->id] = $row; }
        if ($byId) {
            // Solo las etiquetas de los contactos devueltos (no toda la tabla: importa a escala).
            $ids = array_keys($byId);
            $ph  = implode(',', array_fill(0, count($ids), '?'));
            $ls = DB::select("SELECT cl.contact_id, l.id, l.name, l.color FROM contact_labels cl JOIN labels l ON l.id = cl.label_id WHERE cl.contact_id IN ($ph)", $ids);
            foreach ($ls as $lr) {
                if (isset($byId[$lr->contact_id])) {
                    $byId[$lr->contact_id]->labels[] = ['id' => $lr->id, 'name' => $lr->name, 'color' => $lr->color];
                }
            }
        }
        return response()->json(['conversations' => $rows, 'more' => $more]);
    }

    protected function messages(Request $r)
    {
        $contactId = (int) $r->query('contact_id', 0);
        $contact = DB::selectOne('SELECT c.*, u.name AS assignee_name FROM contacts c LEFT JOIN users u ON u.id = c.assigned_to WHERE c.id = ?', [$contactId]);
        if (!$contact) return response()->json(['error' => 'Contacto no encontrado'], 404);

        DB::update('UPDATE contacts SET unread = 0 WHERE id = ?', [$contactId]);

        $contact->labels = DB::select('SELECT l.id, l.name, l.color FROM contact_labels cl JOIN labels l ON l.id = cl.label_id WHERE cl.contact_id = ?', [$contactId]);
        // Solo mensajes de WhatsApp: los de correo/soporte viven en su ticket, no aquí.
        $messages = DB::select("SELECT m.*, u.name AS sent_by_name FROM messages m LEFT JOIN users u ON u.id = m.sent_by WHERE m.contact_id = ? AND m.channel = 'whatsapp' AND m.funcion = 'campanas' ORDER BY m.id ASC LIMIT 500", [$contactId]);

        return response()->json(['contact' => $contact, 'messages' => $messages]);
    }

    protected function poll(Request $r)
    {
        $contactId = (int) $r->query('contact_id', 0);
        $afterId   = (int) $r->query('after', 0);
        $messages = DB::select("SELECT m.*, u.name AS sent_by_name FROM messages m LEFT JOIN users u ON u.id = m.sent_by WHERE m.contact_id = ? AND m.channel = 'whatsapp' AND m.funcion = 'campanas' AND m.id > ? ORDER BY m.id ASC", [$contactId, $afterId]);
        return response()->json(['messages' => $messages]);
    }
}
