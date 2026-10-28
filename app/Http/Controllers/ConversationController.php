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
            $action === 'ticket_cats'     => $this->ticketCats(),
            $action === 'to_ticket' && $post => $this->toTicket($request),
            $action === 'assign' && $post => $this->assign($request),
            $action === 'start'  && $post => $this->start($request),
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
     * campaigns.access), NO a los agentes de soporte. Solo usuarios ACTIVOS (los
     * ex-empleados, active=0, no salen) y SIN el superadmin: no se le reparten chats,
     * aunque por bypass tenga todos los permisos.
     */
    protected function agents()
    {
        $super = config('rbac.super_role');
        $agents = User::where(fn ($q) => $q->whereNull('active')->orWhere('active', true))
            ->orderByRaw('name IS NULL, name ASC, email ASC')->get()
            ->filter(fn ($u) => !$u->hasRole($super) && $u->can('campaigns.access'))
            ->map(fn ($u) => [
                'id'       => (int) $u->id,
                'name'     => $u->name,
                'email'    => $u->email,
                'role'     => $u->roleName(),
            ])
            ->values();

        return response()->json(['ok' => true, 'agents' => $agents]);
    }

    /** Categorías de ticket (para el modal «Crear ticket» desde el Chat en vivo). */
    protected function ticketCats()
    {
        return response()->json(['ok' => true, 'categories' => DB::table('ticket_categories')->orderBy('position')->get(['id', 'name'])]);
    }

    /**
     * Convierte una conversación de WhatsApp (Campañas) en un TICKET de soporte.
     * Se pide un correo (el soporte lo sigue por email). El contenido puede ser solo
     * un mensaje o toda la conversación. Deja una nota en el chat con el código.
     */
    protected function toTicket(Request $r)
    {
        $id      = (int) $r->input('contact_id');
        $email   = mb_strtolower(trim((string) $r->input('email')));
        $subject = trim((string) $r->input('subject'));
        $scope   = $r->input('scope') === 'message' ? 'message' : 'conversation';
        $msgId   = (int) $r->input('message_id');
        $catId   = (int) $r->input('category_id') ?: null;

        if (!$id) return response()->json(['ok' => false, 'error' => 'Falta el contacto'], 400);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['ok' => false, 'error' => 'Indica un correo válido para el ticket'], 400);
        }
        $contact = DB::table('contacts')->where('id', $id)->first();
        if (!$contact) return response()->json(['ok' => false, 'error' => 'Contacto no encontrado'], 404);

        /*
         * El correo de la ficha es la llave del portal (quien lo controle ve TODOS sus
         * tickets). Desde Campañas solo se pone si la ficha no tiene correo NI tickets de
         * soporte, y si ese correo no es ya de otra ficha. Si el cliente ya es de soporte,
         * su correo lo completa Soporte; si no, cualquiera de Campañas podría poner el suyo
         * y leer las incidencias de ese cliente.
         */
        if (!($contact->email ?? '')) {
            if (DB::table('tickets')->where('contact_id', $id)->exists()) {
                return response()->json(['ok' => false, 'error' => 'Este cliente ya tiene tickets de soporte y no tiene correo: pide a Soporte que lo complete en su ficha.'], 409);
            }
            if (DB::table('contacts')->where('email', $email)->where('id', '<>', $id)->exists()) {
                return response()->json(['ok' => false, 'error' => 'Ese correo ya es de otro contacto. Si es la misma persona, que Soporte una las fichas.'], 409);
            }
            DB::table('contacts')->where('id', $id)->update(['email' => $email]);
        }
        $emailFinal = ($contact->email ?? '') ?: $email;

        // Contenido del ticket: un mensaje o la transcripción de la conversación. SOLO
        // mensajes de Campañas: los del WhatsApp de Soporte (01) no salen por aquí.
        $deCampanas = fn ($q) => $q->where('funcion', '<>', 'soporte')->orWhereNull('funcion');
        if ($scope === 'message' && $msgId) {
            $m = DB::table('messages')->where('id', $msgId)->where('contact_id', $id)->where($deCampanas)->first(['body', 'type']);
            $cuerpo = $m ? (trim((string) $m->body) ?: '[' . $m->type . ']') : '';
        } else {
            $msgs = DB::table('messages')->where('contact_id', $id)->where('channel', 'whatsapp')->where($deCampanas)
                ->whereNull('ticket_id')->orderBy('created_at')->limit(200)->get(['direction', 'body', 'type', 'created_at']);
            $lineas = [];
            foreach ($msgs as $mm) {
                $b = trim((string) $mm->body) ?: '[' . $mm->type . ']';
                $lineas[] = ($mm->direction === 'in' ? 'Cliente' : 'Nosotros') . ' (' . $mm->created_at . '): ' . $b;
            }
            $cuerpo = implode("\n", $lineas);
        }
        if ($subject === '') {
            $subject = mb_substr(trim(preg_replace('/\s+/', ' ', $cuerpo)) ?: ('Consulta de ' . ($contact->name ?: $contact->wa_id)), 0, 120);
        }
        $bodyTicket = "[Ticket creado desde una conversación de WhatsApp de Campañas]\n\n" . $cuerpo;

        // Crea el ticket de soporte (canal correo: el soporte responde por email).
        $ticketId = app(\App\Services\TicketService::class)->create([
            'subject'     => $subject,
            'category_id' => $catId,
            'channel'     => 'email',
            'source'      => 'campanas',
            'contact_id'  => $id,
            'body'        => $cuerpo,
            'email'       => $emailFinal,
            'user_id'     => $r->user()?->id,
        ]);
        $code = DB::table('tickets')->where('id', $ticketId)->value('code');

        // Primer mensaje del ticket (lo que dijo el cliente).
        DB::table('messages')->insert([
            'ticket_id' => $ticketId, 'contact_id' => $id, 'wa_id' => $contact->wa_id,
            'direction' => 'in', 'channel' => 'email', 'funcion' => 'soporte', 'type' => 'text',
            'body' => $bodyTicket, 'is_internal_note' => 0, 'status' => 'received', 'created_at' => now(),
        ]);

        // Nota en el chat de WhatsApp para dejar constancia del ticket creado.
        DB::table('messages')->insert([
            'ticket_id' => null, 'contact_id' => $id, 'wa_id' => $contact->wa_id,
            'direction' => 'out', 'channel' => 'whatsapp', 'funcion' => 'campanas', 'type' => 'text',
            'body' => '🎫 Ticket de soporte ' . $code . ' creado a partir de esta conversación.',
            'is_internal_note' => 1, 'status' => 'sent', 'created_at' => now(),
        ]);

        return response()->json(['ok' => true, 'code' => $code, 'ticket_id' => $ticketId]);
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

    /**
     * Inicia (o reutiliza) una conversación de WhatsApp con un número suelto, SIN que
     * tenga que existir ya como contacto ni con histórico. Normaliza país+número, crea
     * el contacto si hace falta y lo devuelve para abrir el hilo en el Chat en vivo.
     *
     * NO envía nada: a un número «frío» (fuera de la ventana de 24 h) WhatsApp solo deja
     * arrancar la conversación con una PLANTILLA aprobada, que el agente elige luego en el
     * propio chat. Si el contacto ya existía, se respeta su nombre y su código de país.
     */
    protected function start(Request $r)
    {
        // Escribir en el Chat en vivo = mismo permiso que responder.
        if (!$r->user()?->can('inbox.reply')) return response()->json(['ok' => false, 'error' => 'Sin permiso'], 403);

        $cc   = preg_replace('/\D+/', '', (string) $r->input('country_code', ''));
        $num  = preg_replace('/\D+/', '', (string) $r->input('phone', ''));
        $name = trim((string) $r->input('name', ''));

        // Si el número ya trae el prefijo del país, no lo dupliques.
        $wa = ($cc && !str_starts_with($num, $cc)) ? $cc . $num : $num;
        $wa = (string) \App\Support\Telefono::normalizar($wa);   // formato único, con prefijo
        if (strlen($wa) < 7 || strlen($wa) > 20) {
            return response()->json(['ok' => false, 'error' => 'Número no válido'], 400);
        }

        $existente = DB::table('contacts')->where('wa_id', $wa)->first(['id']);
        if ($existente) {
            $id = (int) $existente->id;
        } else {
            $id = \App\Services\ChatService::upsertContact($wa, $name ?: null);
            if ($cc) DB::table('contacts')->where('id', $id)->update(['country_code' => $cc]);
        }

        $c = DB::table('contacts')->where('id', $id)->first(['id', 'wa_id', 'name', 'assigned_to']);

        // ¿Ya tenía conversación de campañas? El front lo usa para avisar de si es nueva.
        $tieneHist = DB::table('messages')->where('contact_id', $id)
            ->where('channel', 'whatsapp')->where('funcion', 'campanas')->exists();

        return response()->json(['ok' => true, 'contact' => $c, 'existing' => (bool) $tieneHist]);
    }

    protected function delete(Request $r)
    {
        $id = (int) $r->input('contact_id');
        if (!$id) return response()->json(['ok' => false, 'error' => 'Falta contact_id'], 400);

        $optedOut = (int) DB::table('contacts')->where('id', $id)->value('opted_out');

        // SOLO la conversación de Campañas. Los mensajes de soporte (correo, portal,
        // WhatsApp del 01) y todo lo que cuelga de un ticket NO son de este chat.
        DB::table('messages')->where('contact_id', $id)
            ->where('channel', 'whatsapp')->where('funcion', 'campanas')->whereNull('ticket_id')
            ->delete();
        DB::delete('DELETE FROM contact_labels WHERE contact_id = ?', [$id]);

        // Si el contacto es también cliente del Helpdesk (tickets u otros mensajes), se
        // CONSERVA: borrarlo arrastraría en cascada todos sus tickets. Solo se limpia el chat.
        $esDeSoporte = DB::table('tickets')->where('contact_id', $id)->exists()
            || DB::table('messages')->where('contact_id', $id)->exists();
        if ($esDeSoporte) {
            DB::update('UPDATE contacts SET last_message = NULL, last_time = NULL, unread = 0, assigned_to = NULL WHERE id = ?', [$id]);
            return response()->json(['ok' => true, 'kept' => true]);
        }

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

        /*
         * FILTROS del Chat en vivo (`f`, JSON). Etiquetas: dentro de un grupo vale cualquiera
         * (Interés alto O medio); entre grupos, todas (Interés alto Y No cliente) — igual que
         * las audiencias de campaña. Más: provincia, ventana de 24 h abierta (se le puede
         * escribir libremente), sin clasificar por interés y ocultar los dados de baja.
         */
        $f = json_decode((string) $r->query('f', ''), true);
        if (is_array($f)) {
            $ints = fn ($v) => array_values(array_filter(array_map('intval', (array) $v), fn ($x) => $x > 0));
            foreach (['interes', 'tipo', 'labels'] as $g) {
                if ($ids = $ints($f[$g] ?? [])) {
                    $where[] = 'EXISTS (SELECT 1 FROM contact_labels clf WHERE clf.contact_id = c.id AND clf.label_id IN (' . implode(',', $ids) . '))';
                }
            }
            if (($prov = trim((string) ($f['provincia'] ?? ''))) !== '') { $where[] = 'c.provincia = ?'; $params[] = $prov; }
            if (!empty($f['ventana'])) {
                $where[] = "EXISTS (SELECT 1 FROM messages mv WHERE mv.contact_id = c.id AND mv.channel = 'whatsapp' AND mv.funcion = 'campanas'
                            AND mv.direction = 'in' AND mv.created_at >= NOW() - INTERVAL 24 HOUR)";
            }
            if (!empty($f['sin_clasificar'])) {
                $where[] = "NOT EXISTS (SELECT 1 FROM contact_labels cls JOIN labels ls ON ls.id = cls.label_id WHERE cls.contact_id = c.id AND ls.grupo = 'interes')";
            }
            if (!empty($f['sin_bajas'])) $where[] = 'c.opted_out = 0';
        }
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

        return response()->json(['contact' => $contact, 'messages' => self::conVia($messages)]);
    }

    protected function poll(Request $r)
    {
        $contactId = (int) $r->query('contact_id', 0);
        $afterId   = (int) $r->query('after', 0);
        $messages = DB::select("SELECT m.*, u.name AS sent_by_name FROM messages m LEFT JOIN users u ON u.id = m.sent_by WHERE m.contact_id = ? AND m.channel = 'whatsapp' AND m.funcion = 'campanas' AND m.id > ? ORDER BY m.id ASC", [$contactId, $afterId]);
        return response()->json(['messages' => self::conVia($messages)]);
    }

    /** `via` = 'movil' en lo enviado desde el móvil (Coexistence), para pintarlo así en el chat. */
    protected static function conVia(array $messages): array
    {
        foreach ($messages as $m) {
            $m->via = ($m->direction === 'out' && $m->payload && str_contains((string) $m->payload, '"_via":"movil"')) ? 'movil' : null;
        }
        return $messages;
    }
}
