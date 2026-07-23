<?php

namespace App\Http\Controllers;

use App\Models\EmailAccount;
use App\Models\User;
use App\Services\AttachmentService;
use App\Services\ChatService;
use App\Services\HtmlSanitizer;
use App\Services\MailService;
use App\Services\SlaService;
use App\Services\TicketLockService;
use App\Services\TicketService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * tickets.php — bandeja y gestión de tickets.
 * Dispatch por ?action=  (list | stats | detail | reply | status | assign | meta | create)
 */
class TicketsController extends Controller
{
    public function __construct(
        protected TicketService $tickets,
        protected AttachmentService $attachments,
    ) {}

    public function handle(Request $request)
    {
        return match ($request->query('action', 'list')) {
            'list'   => $this->list($request),
            'stats'  => $this->stats($request),
            'meta'   => $this->meta(),
            'detail' => $this->detail($request),
            'status' => $this->status($request),
            'assign' => $this->assign($request),
            'bulk'   => $this->bulk($request),
            'create' => $this->create($request),
            'agents'  => $this->agents($request),
            'history' => $this->history($request),
            'canned'  => $this->cannedList(),
            'note'    => $this->note($request),
            'reply'   => $this->reply($request),
            'unlock'  => $this->unlock($request),
            'delete'  => $this->delete($request),
            'pdf'     => $this->pdf($request),
            // Fusionar: primero se piden los candidatos, luego se ejecuta.
            'mergeable' => $this->mergeable($request),
            'merge'     => $this->merge($request),
            default  => response()->json(['error' => 'Acción no válida'], 400),
        };
    }

    /**
     * ¿Qué tickets ve este usuario?
     *  - Con `tickets.view_all` (encargado / superadmin): TODOS.
     *  - Sin él (agente): solo los de SUS CATEGORÍAS (sus áreas) + los que tenga
     *    asignados personalmente (por si le pasan uno de otra categoría).
     * Los tickets sin categorizar solo los ve quien tiene view_all (los clasifica).
     */
    protected function scope($query, User $me)
    {
        if ($me->can('tickets.view_all')) return $query;

        $cats = $me->categoryIds();
        $query->where(function ($q) use ($cats, $me) {
            if ($cats) $q->whereIn('t.category_id', $cats);
            $q->orWhere('t.assigned_to', $me->id);
        });
        return $query;
    }

    /**
     * Igual que baseQuery pero SIN los JOIN, para cuando solo se cuenta.
     *
     * Contar no necesita el nombre del contacto, ni la categoría, ni el agente: son
     * tres uniones que MySQL resuelve para nada. El filtro de permisos (`scope`) solo
     * mira columnas del propio ticket, así que funciona igual. Con 50.000 tickets,
     * los contadores bajan de ~38 ms a ~29 ms.
     */
    protected function countQuery(User $me)
    {
        return $this->scope(DB::table('tickets as t')->where('t.channel', '!=', 'cron'), $me);
    }

    protected function baseQuery(User $me)
    {
        $q = DB::table('tickets as t')
            ->leftJoin('contacts as c', 'c.id', '=', 't.contact_id')
            ->leftJoin('ticket_categories as cat', 'cat.id', '=', 't.category_id')
            ->leftJoin('users as u', 'u.id', '=', 't.assigned_to')
            // Los avisos de cron tienen su propio apartado: ni en la bandeja, ni en
            // los contadores, ni en las estadísticas de soporte.
            ->where('t.channel', '!=', 'cron');

        return $this->scope($q, $me);
    }

    protected function list(Request $request)
    {
        $me = $request->user();
        $q  = $this->baseQuery($me);

        /*
         * DOS BÚSQUEDAS DISTINTAS, a propósito (decisión del usuario):
         *   · 'ficha'    → código, asunto y datos del cliente. Es la de diario.
         *   · 'mensajes' → DENTRO del texto de la conversación. Encuentra cosas que
         *     nadie puso en el asunto («el pedido 4471», «error 500»), pero es otra
         *     pregunta y por eso es otro botón, no un buscador que mezcla ambas.
         *
         * Las notas internas SÍ se buscan: son parte de lo que sabe el equipo.
         */
        $s = trim((string) $request->query('q', ''));
        $donde = $request->query('search_in') === 'messages' ? 'messages' : 'ficha';

        if ($s !== '' && $donde === 'ficha') {
            $q->where(function ($w) use ($s) {
                $w->where('t.code', 'like', "%$s%")
                  ->orWhere('t.subject', 'like', "%$s%")
                  ->orWhere('c.name', 'like', "%$s%")
                  ->orWhere('c.email', 'like', "%$s%")
                  ->orWhere('c.wa_id', 'like', "%$s%");
            });
        } elseif ($s !== '') {
            $q->whereExists(function ($w) use ($s) {
                $w->select(DB::raw(1))->from('messages as ms')
                  ->whereColumn('ms.ticket_id', 't.id');

                /*
                 * El índice de texto completo hace el trabajo duro (403 ms → 0,8 ms
                 * sobre 100.000 mensajes) y el LIKE afina sobre los pocos mensajes
                 * que quedan. Así una frase como «luz de error» sigue siendo exacta
                 * —el índice ignora las palabras de menos de 3 letras— sin pagar el
                 * recorrido completo de la tabla.
                 */
                [$termino, $afinar] = $this->terminoTextoCompleto($s);

                if ($termino) $w->whereRaw('MATCH(ms.body) AGAINST (? IN BOOLEAN MODE)', [$termino]);
                if ($afinar)  $w->where('ms.body', 'like', "%$s%");
            });
        }
        // «Abiertos» agrupa todos los estados vivos: es el filtro por defecto de la cola.
        $status = $request->query('status', 'open');
        if ($status === 'open') {
            $q->whereIn('t.status', TicketService::OPEN_STATUSES);
        } elseif ($status !== '' && $status !== 'all') {
            $q->where('t.status', $status);
        }

        // `contact_email` agrupa los tickets de un MISMO cliente aunque estén en
        // varias fichas de contacto (entró por correo y por WhatsApp = dos filas).
        // `contact` es el repliegue cuando el contacto no tiene correo.
        foreach ([
            'priority'      => 't.priority',
            'category'      => 't.category_id',
            'channel'       => 't.channel',
            'contact'       => 't.contact_id',
            'contact_email' => 'c.email',
        ] as $param => $col) {
            if (($v = $request->query($param, '')) !== '' && $v !== 'all') $q->where($col, $v);
        }
        if (($a = $request->query('assigned', '')) !== '' && $a !== 'all') {
            match (true) {
                $a === 'me'   => $q->where('t.assigned_to', $me->id),   // filtro «Mis tickets»
                $a === 'none' => $q->whereNull('t.assigned_to'),
                default       => $q->where('t.assigned_to', (int) $a),
            };
        }

        /*
         * ¿De quién es la ÚLTIMA respuesta del hilo? Va GUARDADO en el ticket
         * (`last_direction`, lo escribe ChatService al llegar cada mensaje):
         *   'in'  = habló el cliente  → la pelota está en nuestro tejado (sin responder)
         *   'out' = hablamos nosotros → ya está contestado, esperamos al cliente
         * Antes se calculaba con una subconsulta por cada ticket, cada vez.
         */

        /*
         * Vista «SLA vencido»: se pasó el plazo y el ticket sigue vivo. Se apoya en
         * los vencimientos GUARDADOS (ver TicketService::recalcularSla) porque
         * calcularlos al vuelo no permitiría filtrar ni paginar.
         * El de respuesta solo cuenta si aún no se ha contestado.
         */
        if ($request->query('sla') === 'late' && SlaService::activo()) {
            $q->whereIn('t.status', TicketService::OPEN_STATUSES)
              ->where(function ($w) {
                  $w->where('t.sla_resolve_due_at', '<', now())
                    ->orWhere(fn ($x) => $x->where('t.sla_response_due_at', '<', now())
                                           ->whereNull('t.first_response_at'));
              });
        }

        // Filtro «Sin responder» / «Respondidos»
        if (($r = $request->query('reply', '')) === 'pending') {
            $q->where('t.last_direction', 'in');
        } elseif ($r === 'answered') {
            $q->where('t.last_direction', 'out');
        }

        /*
         * PAGINACIÓN. Antes había un `limit(200)` fijo: pasados 200 tickets, el resto
         * desaparecía sin avisar. Ahora se cuenta el total (con los MISMOS filtros) y
         * se sirve la página pedida, así que el agente sabe siempre cuántos hay.
         */
        $porPagina = (int) $request->query('per_page', 25);
        if (!in_array($porPagina, [10, 25, 50, 100], true)) $porPagina = 25;

        $total   = (clone $q)->count('t.id');
        $paginas = max(1, (int) ceil($total / $porPagina));
        $pagina  = max(1, min($paginas, (int) $request->query('page', 1)));   // fuera de rango → última

        $rows = $q
            // ORDEN: por ÚLTIMA ACTIVIDAD, no por fecha de creación. Un ticket de hace un
            // mes con una respuesta de hace un minuto tiene que salir el primero.
            ->orderByDesc(DB::raw('COALESCE(t.last_message_at, t.created_at)'))
            ->orderByDesc('t.id')   // desempate estable: sin él, dos tickets con la misma
                                    // hora pueden bailar entre páginas y salir repetidos
            ->forPage($pagina, $porPagina)
            ->get([
                't.id', 't.code', 't.subject', 't.status', 't.priority', 't.channel',
                't.created_at', 't.last_message_at', 't.first_response_at', 't.resolved_at', 't.opened_at',
                't.assigned_to',
                // `contact_id` y `merged_into_id`: la lista necesita saber si dos
                // tickets marcados son del mismo cliente (para poder fusionarlos) y
                // si alguno ya está fusionado.
                't.contact_id', 't.merged_into_id',
                'c.name as contact_name', 'c.email as contact_email', 'c.wa_id as contact_wa',
                'cat.name as category_name', 'cat.color as category_color',
                'cat.sla_response_hours', 'cat.sla_resolve_hours', 't.sla_paused_minutes', 't.sla_paused_since',
                'u.name as agent_name', 'u.email as agent_email',
                't.last_direction',
            ]);

        /*
         * Al buscar dentro de los mensajes, se devuelve EL TROZO encontrado. Sin esto
         * aparecen tickets cuyo asunto no menciona lo buscado y no hay forma de saber
         * por qué han salido. Solo para la página que se muestra, no para todo.
         */
        if ($s !== '' && $donde === 'messages' && $rows->isNotEmpty()) {
            $encontrados = DB::table('messages')
                ->whereIn('ticket_id', $rows->pluck('id'))
                ->where('body', 'like', "%$s%")
                ->orderByDesc('id')
                ->get(['ticket_id', 'body', 'is_internal_note', 'direction']);

            $porTicket = [];
            foreach ($encontrados as $m) {
                $porTicket[$m->ticket_id] ??= $m;   // el más reciente de cada ticket
            }
            foreach ($rows as $t) {
                $m = $porTicket[$t->id] ?? null;
                $t->match = $m ? [
                    'texto'   => $this->fragmento((string) $m->body, $s),
                    'interna' => (bool) $m->is_internal_note,
                    'de'      => $m->direction === 'in' ? 'cliente' : 'soporte',
                ] : null;
            }
        }

        // Los tiempos solo se calculan (y se envían) a quien tiene permiso para verlos.
        $canTimes = $me->can('tickets.view_times');
        $sla = app(SlaService::class);
        $rows->transform(function ($t) use ($canTimes, $sla) {
            if ($canTimes) {
                $t->response_mins = $t->first_response_at ? $this->minsBetween($t->opened_at, $t->first_response_at) : null;
                $t->resolve_mins  = $t->resolved_at ? $this->minsBetween($t->opened_at, $t->resolved_at) : null;
            }
            // Estado de los dos relojes del SLA (null si su categoría no tiene plazo).
            $t->sla = $sla->forTicket($t);
            unset($t->first_response_at, $t->resolved_at, $t->opened_at, $t->sla_response_hours, $t->sla_resolve_hours, $t->sla_paused_minutes, $t->sla_paused_since);
            return $t;
        });

        return response()->json([
            'ok'        => true,
            'tickets'   => $rows,
            'can_times' => $canTimes,
            'counts'    => $this->counts($me),
            'page'      => $pagina,
            'per_page'  => $porPagina,
            'total'     => $total,
            'pages'     => $paginas,
        ]);
    }

    /**
     * Traduce lo buscado a la sintaxis del índice de texto completo.
     *
     * Devuelve [termino, afinarConLike]:
     *   · `termino`  — expresión para MATCH, o null si no hay ninguna palabra de 3
     *     letras o más (el índice no las guarda) y hay que tirar solo de LIKE.
     *   · `afinar`   — si además hace falta el LIKE para que el resultado sea EXACTO.
     *
     * Una palabra suelta se busca como prefijo (`factura*`) y no necesita afinado.
     * Varias palabras se exigen TODAS (`+luz* +error*`) y luego el LIKE comprueba que
     * aparezcan juntas y en orden: sin él, «luz de error» encontraría un mensaje que
     * dijera «error en la luz».
     */
    protected function terminoTextoCompleto(string $s): array
    {
        // Fuera los operadores de la sintaxis booleana: aquí son texto del usuario.
        $limpio = trim(preg_replace('/[+\-><()~*"@]+/u', ' ', $s) ?? '');
        $palabras = preg_split('/\s+/u', $limpio, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $largas = array_values(array_filter($palabras, fn ($p) => mb_strlen($p) >= 3));
        if (!$largas) return [null, true];                       // todo corto: solo LIKE

        if (count($palabras) === 1) return [$largas[0] . '*', false];

        $termino = implode(' ', array_map(fn ($p) => '+' . $p . '*', $largas));
        return [$termino, true];
    }

    /**
     * Un trozo de texto alrededor de lo buscado, para enseñar por qué salió el ticket.
     *
     * Se quita el HTML primero: si no, se buscaría dentro de etiquetas y atributos y
     * el fragmento saldría lleno de basura. Si tras limpiarlo la palabra ya no está
     * (estaba en un atributo, no en el texto visible), se devuelve el principio.
     */
    protected function fragmento(string $html, string $aguja, int $largo = 160): string
    {
        $texto = trim(preg_replace('/\s+/u', ' ', HtmlSanitizer::toText($html)) ?? '');
        if ($texto === '') return '';

        $pos = mb_stripos($texto, $aguja);
        if ($pos === false) return mb_substr($texto, 0, $largo) . (mb_strlen($texto) > $largo ? '…' : '');

        $desde = max(0, $pos - intdiv($largo, 3));
        $corte = mb_substr($texto, $desde, $largo);

        return ($desde > 0 ? '…' : '') . trim($corte) . ($desde + $largo < mb_strlen($texto) ? '…' : '');
    }

    /**
     * Contadores de las VISTAS RÁPIDAS. La pregunta que se hace un agente al entrar
     * no es «¿cómo filtro?», es «¿qué me toca ahora?». Estos números la responden.
     * Todos ignoran resueltos y cerrados salvo «todos».
     */
    protected function counts(User $me): array
    {
        $abiertos = "t.status IN ('" . implode("','", TicketService::OPEN_STATUSES) . "')";

        // Fuera de plazo: o se pasó la resolución, o se pasó la respuesta sin contestar.
        $vencido = SlaService::activo()
            ? "(t.sla_resolve_due_at < NOW()
                OR (t.sla_response_due_at < NOW() AND t.first_response_at IS NULL))"
            : '0';

        /*
         * LOS CINCO CONTADORES EN UNA SOLA CONSULTA. Antes eran cinco recorridos
         * completos de la tabla; ahora es uno con sumas condicionales. Con 50.000
         * tickets eso son ~90 ms frente a ~45 ms, y la diferencia crece con el
         * volumen porque cada contador costaba una pasada entera.
         *
         * «Pendientes» ya no calcula quién habló el último: lo lee de la columna
         * `last_direction` del ticket. Era, de largo, el contador más caro.
         */
        $r = $this->countQuery($me)->selectRaw(
            "SUM($abiertos) AS activos,
             SUM($abiertos AND t.last_direction = 'in') AS pendientes,
             SUM($abiertos AND t.assigned_to = ?) AS mios,
             SUM($abiertos AND t.assigned_to IS NULL) AS sin_asignar,
             SUM($abiertos AND $vencido) AS vencidos,
             COUNT(*) AS total",
            [$me->id],
        )->first();

        return [
            'active'     => (int) ($r->activos ?? 0),
            'pending'    => (int) ($r->pendientes ?? 0),   // el cliente espera
            'mine'       => (int) ($r->mios ?? 0),
            'unassigned' => (int) ($r->sin_asignar ?? 0),
            'all'        => (int) ($r->total ?? 0),
            'sla_late'   => (int) ($r->vencidos ?? 0),
        ];
    }

    /** Tarjetas del dashboard + reparto por estado. */
    protected function stats(Request $request)
    {
        $me = $request->user();

        /*
         * Reparto por estado: UNA consulta agrupada, no una por estado. Eran seis
         * recorridos completos de la tabla para pintar seis números.
         */
        $byStatus = array_fill_keys(array_keys(TicketService::STATUSES), 0);
        foreach ($this->countQuery($me)->groupBy('t.status')
            ->get([DB::raw('t.status'), DB::raw('COUNT(*) AS n')]) as $fila) {
            if (array_key_exists($fila->status, $byStatus)) $byStatus[$fila->status] = (int) $fila->n;
        }

        // Últimos tickets (panel «Tickets recientes» del Centro de Soporte)
        $recent = (clone $this->baseQuery($me))
            ->orderByDesc(DB::raw('COALESCE(t.last_message_at, t.created_at)'))
            ->limit(5)
            ->get([
                't.id', 't.code', 't.subject', 't.status', 't.priority', 't.channel',
                'c.name as contact_name', 'c.email as contact_email', 'c.wa_id as contact_wa',
            ]);

        return response()->json([
            'ok'        => true,
            'total'     => array_sum($byStatus),
            'open'      => array_sum(array_intersect_key($byStatus, array_flip(TicketService::OPEN_STATUSES))),
            'resolved'  => $byStatus['resuelto'] + $byStatus['cerrado'],
            'urgent'    => (clone $this->baseQuery($me))->where('t.priority', 'urgente')
                                ->whereIn('t.status', TicketService::OPEN_STATUSES)->count(),
            'by_status' => $byStatus,
            'recent'    => $recent,
        ]);
    }

    /** Catálogos para los filtros y los selectores. */
    protected function meta()
    {
        $users = User::with('roles.permissions', 'permissions')->orderByRaw('name IS NULL, name ASC, email ASC')->get()
            ->filter(fn ($u) => $u->can('helpdesk.access'))
            ->map(fn ($u) => ['id' => (int) $u->id, 'name' => $u->name ?: $u->email])
            ->values();

        return response()->json([
            'ok'         => true,
            'statuses'   => TicketService::STATUSES,
            'priorities' => TicketService::priorities(),
            // Con su color, para pintar las etiquetas sin quemarlos en el CSS.
            'priority_meta' => TicketService::prioritiesMeta(),
            'categories' => DB::table('ticket_categories')->where('active', 1)->orderBy('position')->get(),
            'users'      => $users,   // a quién se puede asignar
        ]);
    }

    /** Un ticket con su hilo completo (el chat). */
    protected function detail(Request $request)
    {
        $me = $request->user();
        $id = (int) $request->query('id');

        $t = (clone $this->baseQuery($me))->where('t.id', $id)->first([
            't.*', 'c.name as contact_name', 'c.email as contact_email', 'c.wa_id as contact_wa',
            'cat.name as category_name', 'cat.color as category_color',
            'cat.sla_response_hours', 'cat.sla_resolve_hours', 't.sla_paused_minutes', 't.sla_paused_since',
            'u.name as agent_name',
        ]);
        if (!$t) return response()->json(['ok' => false, 'error' => 'Ticket no encontrado'], 404);

        /*
         * ¿Está fusionado en otro? Se manda el CÓDIGO además del id: la pantalla
         * tiene que poder decir «fusionado en TK-2607-0016» sin pedir otro detalle.
         */
        $t->merged_into_code = $t->merged_into_id
            ? DB::table('tickets')->where('id', $t->merged_into_id)->value('code')
            : null;

        // Estado del SLA (antes de ocultar los tiempos: se calcula a partir de ellos).
        $t->sla = app(SlaService::class)->forTicket($t);
        unset($t->sla_response_hours, $t->sla_resolve_hours, $t->sla_paused_minutes, $t->sla_paused_since);

        // Los tiempos no se envían a quien no puede verlos (ocultarlos en la UI no basta).
        if (!$me->can('tickets.view_times')) {
            unset($t->first_response_at, $t->resolved_at);
        }

        // Se une con users para saber QUIÉN escribió cada respuesta. Importa: en un
        // ticket pueden contestar varios agentes, y hay que ver quién dijo qué.
        $messages = DB::table('messages as m')
            ->leftJoin('users as au', 'au.id', '=', 'm.author_user_id')
            ->where('m.ticket_id', $id)->orderBy('m.id')
            ->get([
                'm.id', 'm.direction', 'm.channel', 'm.type', 'm.body', 'm.is_html', 'm.is_internal_note',
                'm.media_url', 'm.media_mime', 'm.status', 'm.author_user_id', 'm.created_at',
                'm.cc', 'm.bcc',
                'au.name as author_name', 'au.email as author_email',
            ]);

        // Adjuntos, colgados de su mensaje
        $byMessage = $this->attachments->forTicket($id);
        foreach ($messages as $m) {
            $m->attachments = $byMessage[$m->id] ?? [];
        }

        $events = DB::table('ticket_events as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.user_id')
            ->where('e.ticket_id', $id)->orderBy('e.id')
            ->get(['e.type', 'e.from_value', 'e.to_value', 'e.note', 'e.created_at', 'u.name as user_name']);

        // En los eventos de ASIGNACIÓN, from/to son IDs de usuario → se resuelven a nombres
        // para que el historial diga «asignado a Pedro», no «asignado a 3».
        $userIds = $events->where('type', 'assign')->flatMap(fn ($e) => [$e->from_value, $e->to_value])->filter()->unique();
        $names = $userIds->isNotEmpty()
            ? DB::table('users')->whereIn('id', $userIds)->pluck('name', 'id')
            : collect();
        foreach ($events as $e) {
            if ($e->type === 'assign') {
                $e->from_name = $e->from_value ? ($names[$e->from_value] ?? 'alguien') : null;
                $e->to_name   = $e->to_value ? ($names[$e->to_value] ?? 'alguien') : null;
            }
        }

        // Al abrir el ticket se TOMA para este agente (evita que dos contesten a la vez).
        // Si lo tiene otro y sigue vigente, se devuelve quién, para avisarlo en pantalla.
        $lock = app(TicketLockService::class)->acquire($id, (int) $me->id);

        return response()->json([
            'ok' => true, 'ticket' => $t, 'messages' => $messages, 'events' => $events, 'lock' => $lock,
            // Copias que ya estaban en la conversación, para proponerlas al responder.
            'cc_sugerido' => $this->ccDelHilo($messages, (string) $t->contact_email),
        ]);
    }

    /** Suelta el bloqueo al cerrar el ticket (si no, caduca solo). */
    protected function unlock(Request $request)
    {
        app(TicketLockService::class)->release((int) $request->input('id'), (int) $request->user()->id);
        return response()->json(['ok' => true]);
    }

    /**
     * Añade una NOTA INTERNA al ticket. NO se envía al cliente: solo se guarda y se
     * muestra a los agentes (is_internal_note=1). No necesita el canal de correo/WhatsApp.
     */
    protected function note(Request $request)
    {
        $me = $request->user();
        if (!$request->isMethod('post')) return response()->json(['ok' => false, 'error' => 'Método no permitido'], 405);
        if (!$me->can('tickets.reply')) return response()->json(['ok' => false, 'error' => 'No tienes permiso'], 403);

        $id = (int) $request->input('id');
        // Solo se puede anotar en un ticket que el usuario VE (mismo alcance que el detalle).
        $t = (clone $this->baseQuery($me))->where('t.id', $id)->first(['t.id', 't.contact_id', 'c.wa_id as contact_wa']);
        if (!$t) return response()->json(['ok' => false, 'error' => 'Ticket no encontrado'], 404);

        // Bloqueo: si lo está atendiendo otro agente, no se escribe encima.
        if ($quien = app(TicketLockService::class)->blockedBy((int) $t->id, (int) $me->id)) {
            return response()->json(['ok' => false, 'error' => "{$quien} está atendiendo este ticket ahora mismo"], 409);
        }

        $html = HtmlSanitizer::clean((string) $request->input('body', ''));
        // Vacía = ni texto ni imágenes (una nota de solo captura es válida).
        if (trim(HtmlSanitizer::toText($html)) === '' && stripos($html, '<img') === false) {
            return response()->json(['ok' => false, 'error' => 'La nota está vacía'], 400);
        }

        $mid = ChatService::storeMessage((int) $t->contact_id, (string) ($t->contact_wa ?? ''), 'out', 'note', $html, [
            'ticket_id'        => (int) $t->id,
            'author_user_id'   => (int) $me->id,
            'is_internal_note' => true,
            'is_html'          => true,
            'channel'          => 'web',   // la nota se escribe en la web (el valor no se muestra)
            /*
             * Una JUSTIFICACIÓN de retraso es una nota interna normal, solo que
             * marcada: así sale en el hilo donde tiene sentido (no en una pantalla
             * aparte) y mañana se puede sacar un listado de «por qué nos fuimos de
             * plazo» sin montar nada nuevo.
             */
            'status'           => $request->boolean('sla') ? 'sla_justificacion' : 'note',
        ]);

        return response()->json(['ok' => true, 'id' => $mid]);
    }

    /**
     * RESPONDER al cliente (Paso 2). De momento el envío es por CORREO: si el ticket
     * es de canal «email», se manda la respuesta por SMTP con [CODE] en el asunto
     * (para que la contestación del cliente vuelva al mismo ticket) y se guarda como
     * mensaje saliente del hilo. Los adjuntos se envían y se guardan.
     */
    protected function reply(Request $request)
    {
        $me = $request->user();
        if (!$request->isMethod('post')) return response()->json(['ok' => false, 'error' => 'Método no permitido'], 405);
        if (!$me->can('tickets.reply')) return response()->json(['ok' => false, 'error' => 'No tienes permiso'], 403);

        $id = (int) $request->input('id');
        $t  = (clone $this->baseQuery($me))->where('t.id', $id)
            ->first(['t.id', 't.code', 't.subject', 't.channel', 't.contact_id',
                     'c.email as contact_email', 'c.name as contact_name', 'c.wa_id as contact_wa']);
        if (!$t) return response()->json(['ok' => false, 'error' => 'Ticket no encontrado'], 404);

        // Bloqueo: evita que dos agentes respondan a la vez al mismo cliente.
        if ($quien = app(TicketLockService::class)->blockedBy((int) $t->id, (int) $me->id)) {
            return response()->json(['ok' => false, 'error' => "{$quien} está atendiendo este ticket ahora mismo"], 409);
        }

        $html  = HtmlSanitizer::clean((string) $request->input('body', ''));
        $files = $request->file('files', []);
        if (trim(HtmlSanitizer::toText($html)) === '' && stripos($html, '<img') === false && !$files) {
            return response()->json(['ok' => false, 'error' => 'La respuesta está vacía'], 400);
        }

        // De momento solo se responde por CORREO (los demás canales aún no envían).
        if ($t->channel !== 'email') {
            return response()->json(['ok' => false, 'error' => 'El envío para el canal «' . $t->channel . '» aún no está disponible'], 422);
        }
        if (!$t->contact_email) {
            return response()->json(['ok' => false, 'error' => 'El contacto no tiene dirección de correo'], 422);
        }
        $acc = EmailAccount::where('active', true)->whereNotNull('smtp_host')->orderBy('id')->first();
        if (!$acc) {
            return response()->json(['ok' => false, 'error' => 'No hay un buzón SMTP configurado'], 422);
        }

        // Adjuntos primero (validados + en disco), pero SIN mensaje aún: si el SMTP
        // falla, se limpian y no queda un mensaje fantasma en el hilo.
        $savedIds = [];
        $warnings = [];
        $forMail  = [];
        if ($files) {
            [$savedIds, $warnings] = $this->attachments->store($files, (int) $t->id, null, (int) $me->id);
            foreach ($savedIds as $aid) {
                if ($f = $this->attachments->find($aid)) {
                    [$path, $row] = $f;
                    $forMail[] = ['path' => $path, 'name' => $row->name, 'mime' => $row->mime];
                }
            }
        }

        // Enviar por SMTP.
        $subject = $this->replySubject((string) $t->subject, (string) $t->code);

        // Encadenado del hilo: cadena de Message-IDs previos del ticket (In-Reply-To =
        // el último; References = toda la cadena) para que el correo del cliente los agrupe.
        $refs = DB::table('messages')->where('ticket_id', $t->id)->whereNotNull('wamid')
            ->orderBy('id')->pluck('wamid')
            ->map(fn ($w) => trim((string) $w, "<> \t\r\n"))
            ->filter()->values()->all();
        $inReplyTo = $refs ? end($refs) : null;

        /*
         * COPIAS: quien venía en Cc sigue en la conversación, así que el agente puede
         * mantenerlo, quitarlo o añadir a alguien más. Lo que llega del formulario se
         * valida aquí; el filtrado fino (destinatario repetido, nuestro propio buzón)
         * lo hace sendMail() justo antes de enviar.
         */
        $cc  = $this->direcciones($request->input('cc'));
        $bcc = $this->direcciones($request->input('bcc'));

        try {
            $smtpId = app(MailService::class)->sendMail(
                $acc, (string) $t->contact_email, (string) $t->contact_name,
                $subject, $this->absolutizeInline($html), $forMail, $inReplyTo, $refs, $cc, $bcc
            );
        } catch (\Throwable $e) {
            // Deshacer adjuntos guardados (ficheros + filas) para no dejar basura.
            foreach ($savedIds as $aid) {
                if ($f = $this->attachments->find($aid)) { try { Storage::disk('local')->delete($f[1]->path); } catch (\Throwable $x) {} }
            }
            if ($savedIds) DB::table('attachments')->whereIn('id', $savedIds)->delete();

            return response()->json(['ok' => false, 'error' => 'No se pudo enviar el correo: ' . mb_substr($e->getMessage(), 0, 160)], 502);
        }

        // Enviado: guardar el mensaje saliente y colgarle los adjuntos.
        $messageId = ChatService::storeMessage((int) $t->contact_id, (string) ($t->contact_wa ?? ''), 'out', 'text', $html, [
            'ticket_id'      => (int) $t->id,
            'author_user_id' => (int) $me->id,
            'is_html'        => true,
            'channel'        => 'email',
            'status'         => 'sent',
            'cc'             => $cc ? implode(', ', $cc) : null,
            'bcc'            => $bcc ? implode(', ', $bcc) : null,
            'wamid'          => $smtpId ? mb_substr($smtpId, 0, 128) : null,
        ]);
        if ($savedIds) {
            DB::table('attachments')->whereIn('id', $savedIds)->update(['message_id' => $messageId]);
        }

        return response()->json(['ok' => true, 'id' => $messageId, 'warnings' => $warnings]);
    }

    /**
     * Copias que ya circulaban en el hilo, para proponerlas al responder.
     *
     * Se acumulan las de TODOS los mensajes (no solo el último): si alguien entró en
     * copia al principio, sigue esperando enterarse. Se quitan el destinatario
     * principal y nuestros propios buzones, que no son «copias» de nadie.
     */
    protected function ccDelHilo($messages, string $contactEmail): array
    {
        $fuera = array_map('mb_strtolower', array_filter(array_merge(
            [$contactEmail],
            EmailAccount::pluck('email')->all(),
            EmailAccount::whereNotNull('imap_user')->pluck('imap_user')->all(),
        )));

        $todas = [];
        foreach ($messages as $m) {
            foreach ($this->direcciones($m->cc ?? '') as $d) {
                if (!in_array($d, $fuera, true) && !in_array($d, $todas, true)) $todas[] = $d;
            }
        }
        return $todas;
    }

    /**
     * Normaliza una lista de direcciones venga como array o como texto separado por
     * comas/puntoycoma. Descarta lo que no sea un correo válido en vez de reventar:
     * una copia mal escrita no debe impedir que salga la respuesta.
     */
    protected function direcciones($v): array
    {
        $bruto = is_array($v) ? $v : preg_split('/[,;\s]+/', (string) $v);
        $ok = [];
        foreach ($bruto as $d) {
            $d = mb_strtolower(trim((string) $d));
            if ($d !== '' && filter_var($d, FILTER_VALIDATE_EMAIL) && !in_array($d, $ok, true)) $ok[] = $d;
        }
        return array_slice($ok, 0, 20);   // tope sano: nadie responde a 50 copias
    }

    /** Asunto de respuesta: «Re: … [TK-AAMM-NNNN]» para mantener el hilo por código. */
    protected function replySubject(string $subject, string $code): string
    {
        $s = trim($subject) ?: 'Sin asunto';
        if (stripos($s, 're:') !== 0)   $s = 'Re: ' . $s;
        if (stripos($s, $code) === false) $s .= ' [' . $code . ']';
        return mb_substr($s, 0, 200);
    }

    /**
     * Convierte las rutas de imágenes EN LÍNEA (relativas /api/inline/.. y
     * /api/attachment_inline/..) en ABSOLUTAS, para que carguen en el cliente de
     * correo del destinatario (la firma de la URL las autoriza sin token).
     */
    protected function absolutizeInline(string $html): string
    {
        $base = rtrim((string) config('app.url'), '/');
        if ($base === '') return $html;
        return preg_replace('#(src=")(/api/(?:inline|attachment_inline)/)#i', '$1' . $base . '$2', $html);
    }

    /**
     * Borra un ticket ENTERO. Requiere tickets.delete. La BD borra en cascada sus
     * mensajes, eventos y filas de adjuntos; aquí se limpian además los FICHEROS en disco.
     */
    /**
     * CANDIDATOS A FUSIONAR: los otros tickets del MISMO cliente.
     *
     * El filtro por contacto no es solo comodidad, es la regla del negocio: juntar
     * conversaciones de dos clientes distintos mezclaría datos de uno en el hilo del
     * otro, y eso no se puede deshacer con un botón.
     */
    protected function mergeable(Request $request)
    {
        $me = $request->user();
        $id = (int) $request->query('id');

        $t = (clone $this->baseQuery($me))->where('t.id', $id)
            ->first(['t.id', 't.code', 't.subject', 't.contact_id', 't.created_at', 't.merged_into_id']);
        if (!$t) return response()->json(['ok' => false, 'error' => 'Ticket no encontrado'], 404);
        if ($t->merged_into_id) return response()->json(['ok' => false, 'error' => 'Este ticket ya está fusionado'], 409);

        // Mismo alcance que la lista: no se ofrece fusionar con algo que no puedes ver.
        $otros = (clone $this->baseQuery($me))
            ->where('t.contact_id', $t->contact_id)
            ->where('t.id', '!=', $id)
            ->whereNull('t.merged_into_id')
            ->where('t.channel', '!=', 'cron')
            ->orderByDesc('t.created_at')->limit(50)
            ->get(['t.id', 't.code', 't.subject', 't.status', 't.created_at', 't.channel']);

        foreach ($otros as $o) {
            $o->messages = DB::table('messages')->where('ticket_id', $o->id)->where('is_internal_note', 0)->count();
        }

        return response()->json([
            'ok'      => true,
            'ticket'  => $t,
            'others'  => $otros,
            'messages' => DB::table('messages')->where('ticket_id', $t->id)->where('is_internal_note', 0)->count(),
        ]);
    }

    /** Ejecuta la fusión. La comprobación de verdad está en TicketService::merge(). */
    protected function merge(Request $request)
    {
        $me = $request->user();
        if (!$request->isMethod('post')) return response()->json(['ok' => false, 'error' => 'Método no permitido'], 405);
        if (!$me->can('tickets.reply')) return response()->json(['ok' => false, 'error' => 'No tienes permiso'], 403);

        $principal = (int) $request->input('into');
        $absorbido = (int) $request->input('from');

        // Los dos tienen que estar dentro de lo que este usuario ve.
        foreach ([$principal, $absorbido] as $x) {
            if (!(clone $this->baseQuery($me))->where('t.id', $x)->exists()) {
                return response()->json(['ok' => false, 'error' => 'Ticket no encontrado'], 404);
            }
        }

        [$ok, $error] = $this->tickets->merge(
            $principal, $absorbido, (int) $me->id, (string) $request->input('reason', ''),
        );
        if (!$ok) return response()->json(['ok' => false, 'error' => $error], 422);

        return response()->json(['ok' => true, 'into' => $principal]);
    }

    protected function delete(Request $request)
    {
        $me = $request->user();
        if (!$request->isMethod('post')) return response()->json(['ok' => false, 'error' => 'Método no permitido'], 405);
        if (!$me->can('tickets.delete')) return response()->json(['ok' => false, 'error' => 'No tienes permiso para eliminar tickets'], 403);

        $id = (int) $request->input('id');
        // Solo se borra un ticket que el usuario VE (mismo alcance que el detalle).
        $t = (clone $this->baseQuery($me))->where('t.id', $id)->first(['t.id', 't.code']);
        if (!$t) return response()->json(['ok' => false, 'error' => 'Ticket no encontrado'], 404);

        // Ficheros de adjuntos en disco (la cascada de BD no borra los ficheros).
        $paths = DB::table('attachments')->where('ticket_id', $id)->pluck('path');
        foreach ($paths as $p) { try { Storage::disk('local')->delete($p); } catch (\Throwable $e) { /* ignora */ } }

        DB::table('tickets')->where('id', $id)->delete(); // cascada: messages, ticket_events, attachments

        return response()->json(['ok' => true, 'code' => $t->code]);
    }

    /**
     * Genera un PDF del hilo del ticket (dompdf). Opciones: incluir notas internas y/o
     * imágenes. Las imágenes en línea se INCRUSTAN como data URI (dompdf no pide URLs
     * firmadas). Requiere ver el ticket (mismo alcance que el detalle).
     */
    protected function pdf(Request $request)
    {
        $me = $request->user();
        $id = (int) $request->input('id');
        $withNotes  = filter_var($request->input('notes', true), FILTER_VALIDATE_BOOLEAN);
        $withImages = filter_var($request->input('images', true), FILTER_VALIDATE_BOOLEAN);

        $t = (clone $this->baseQuery($me))->where('t.id', $id)->first([
            't.*', 'c.name as contact_name', 'c.email as contact_email', 'c.wa_id as contact_wa',
            'cat.name as category_name', 'u.name as agent_name',
        ]);
        if (!$t) return response()->json(['ok' => false, 'error' => 'Ticket no encontrado'], 404);

        $q = DB::table('messages as m')->leftJoin('users as au', 'au.id', '=', 'm.author_user_id')
            ->where('m.ticket_id', $id)->orderBy('m.id');
        if (!$withNotes) $q->where('m.is_internal_note', 0);
        $messages = $q->get(['m.id', 'm.direction', 'm.body', 'm.is_html', 'm.is_internal_note', 'm.created_at', 'au.name as author_name']);

        // Cuerpo de cada mensaje ya listo para el PDF (HTML saneado con imágenes incrustadas, o texto escapado).
        $bodies = [];
        foreach ($messages as $m) {
            $bodies[$m->id] = (int) $m->is_html === 1
                ? $this->pdfImages((string) $m->body, $withImages)
                : nl2br(e((string) $m->body));
        }

        $html = view('ticket-pdf', [
            't' => $t, 'messages' => $messages, 'bodies' => $bodies,
            'statuses' => TicketService::STATUSES, 'priorities' => TicketService::priorities(),
        ])->render();

        $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return response($dompdf->output(), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="ticket-' . $t->code . '.pdf"',
        ]);
    }

    /** Para el PDF: incrusta las imágenes en línea como data URI, o las quita. */
    protected function pdfImages(string $html, bool $withImages): string
    {
        if (!$withImages) return preg_replace('#<img[^>]*>#i', '', $html);

        return preg_replace_callback('#<img([^>]*?)src="[^"]*?/api/inline/(\d+)\?[^"]*"([^>]*)>#i', function ($mm) {
            $row = DB::table('inline_uploads')->find((int) $mm[2]);
            if (!$row || !Storage::disk('local')->exists($row->path)) return '';
            $data = base64_encode(Storage::disk('local')->get($row->path));
            return '<img' . $mm[1] . 'src="data:' . $row->mime . ';base64,' . $data . '"' . $mm[3] . '>';
        }, $html);
    }

    protected function status(Request $request)
    {
        $me = $request->user();
        if (!$me->can('tickets.close')) return response()->json(['ok' => false, 'error' => 'Sin permiso'], 403);

        $id = (int) $request->input('id');
        $st = (string) $request->input('status');
        if (!array_key_exists($st, TicketService::STATUSES)) {
            return response()->json(['ok' => false, 'error' => 'Estado no válido'], 400);
        }

        $this->tickets->setStatus($id, $st, (int) $me->id);
        return response()->json(['ok' => true]);
    }

    protected function assign(Request $request)
    {
        $me  = $request->user();
        $id  = (int) $request->input('id');
        $uid = $request->input('user_id');
        $target = $uid ? (int) $uid : null;

        /*
         * Dos permisos distintos:
         *  - Asignárselo a UNO MISMO (coger un ticket de la cola): cualquier agente.
         *  - Asignárselo a OTRO, o desasignar: requiere tickets.assign (reparto).
         */
        $isSelfClaim = $target === (int) $me->id;
        if (!$isSelfClaim && !$me->can('tickets.assign')) {
            return response()->json(['ok' => false, 'error' => 'Solo puedes cogerte tickets a ti mismo'], 403);
        }

        $this->tickets->assign($id, $target, (int) $me->id);
        return response()->json(['ok' => true]);
    }

    /** Acciones EN LOTE sobre varios tickets a la vez (cerrar, asignar…). */
    protected function bulk(Request $request)
    {
        $me  = $request->user();
        $ids = array_slice(array_map('intval', (array) $request->input('ids', [])), 0, 200);
        $op  = (string) $request->input('op');
        if (!$ids) return response()->json(['ok' => false, 'error' => 'No hay tickets seleccionados'], 400);

        // Solo se actúa sobre tickets que este usuario PUEDE ver (respeta view_all).
        $visible = (clone $this->baseQuery($me))->whereIn('t.id', $ids)->pluck('t.id')->all();
        if (!$visible) return response()->json(['ok' => false, 'error' => 'Sin tickets válidos'], 400);

        $n = 0;
        if ($op === 'status') {
            if (!$me->can('tickets.close')) return response()->json(['ok' => false, 'error' => 'Sin permiso'], 403);
            $st = (string) $request->input('status');
            if (!array_key_exists($st, TicketService::STATUSES)) return response()->json(['ok' => false, 'error' => 'Estado no válido'], 400);
            foreach ($visible as $tid) { if ($this->tickets->setStatus($tid, $st, (int) $me->id)) $n++; }

        } elseif ($op === 'assign') {
            $uid = $request->input('user_id');
            $target = $uid ? (int) $uid : null;
            $isSelfClaim = $target === (int) $me->id;
            if (!$isSelfClaim && !$me->can('tickets.assign')) {
                return response()->json(['ok' => false, 'error' => 'Solo puedes cogerte tickets a ti mismo'], 403);
            }
            foreach ($visible as $tid) { $this->tickets->assign($tid, $target, (int) $me->id); $n++; }

        } else {
            return response()->json(['ok' => false, 'error' => 'Operación no válida'], 400);
        }

        return response()->json(['ok' => true, 'affected' => $n]);
    }

    /**
     * Alta manual de un ticket (formulario «Nuevo Ticket»).
     * El solicitante se busca por email o teléfono; si no existe, se crea.
     */
    protected function create(Request $request)
    {
        $me = $request->user();
        if (!$me->can('tickets.create')) {
            return response()->json(['ok' => false, 'error' => 'No tienes permiso para crear tickets'], 403);
        }

        $name    = trim((string) $request->input('name'));
        $email   = trim((string) $request->input('email'));
        $phone   = preg_replace('/\D+/', '', (string) $request->input('phone'));
        $subject = trim((string) $request->input('subject'));

        // La descripción llega como HTML del editor: se SANEA por lista blanca.
        $body  = HtmlSanitizer::clean($request->input('description'));
        $plain = HtmlSanitizer::toText($body);
        $files = $request->file('files', []);

        if ($name === '')    return response()->json(['ok' => false, 'error' => 'El nombre es obligatorio'], 400);
        if ($email === '' && $phone === '') return response()->json(['ok' => false, 'error' => 'Indica al menos un email o un teléfono'], 400);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['ok' => false, 'error' => 'El email no es válido'], 400);
        }
        if ($subject === '') return response()->json(['ok' => false, 'error' => 'El asunto es obligatorio'], 400);
        // Vale con que haya texto O adjuntos: a veces una captura lo dice todo.
        if ($plain === '' && !$files) {
            return response()->json(['ok' => false, 'error' => 'Describe el problema o adjunta un archivo'], 400);
        }

        // Buscar al solicitante (por email o por teléfono) o crearlo
        $q = DB::table('contacts');
        $email !== '' ? $q->where('email', $email) : $q->where('wa_id', $phone);
        $contactId = $q->value('id');

        if (!$contactId) {
            $contactId = DB::table('contacts')->insertGetId([
                'name'  => $name,
                'email' => $email ?: null,
                'wa_id' => $phone ?: null,
            ]);
        }

        // Solo quien tiene permiso puede asignar a otro; el resto crea sin asignar.
        $assignee = null;
        if ($me->can('tickets.assign') && $request->input('assigned_to')) {
            $assignee = (int) $request->input('assigned_to');
        }

        $ticketId = $this->tickets->create([
            'contact_id'  => $contactId,
            'channel'     => 'web',   // creado desde la app (canal interno/web)
            'subject'     => $subject,
            'category_id' => $request->input('category_id') ?: null,
            'priority'    => in_array($request->input('priority'), array_keys(TicketService::priorities()), true)
                                ? $request->input('priority') : 'media',
            'assigned_to' => $assignee,
            'user_id'     => (int) $me->id,
            // Contexto para las reglas automáticas
            'body'        => $plain,
            'email'       => $email,
        ]);

        // La descripción es el primer mensaje del hilo
        $messageId = DB::table('messages')->insertGetId([
            'contact_id' => $contactId,
            'ticket_id'  => $ticketId,
            'wa_id'      => $phone ?: null,
            'direction'  => 'in',
            'channel'    => 'web',
            'type'       => 'text',
            'body'       => $body,
            'is_html'    => true,      // ya saneado arriba
            'status'     => 'received',
        ]);

        // Este mensaje NO pasa por ChatService, así que hay que dejar el ticket con
        // «quién habló el último» al día a mano (lo abre el cliente → 'in').
        DB::table('tickets')->where('id', $ticketId)->update(['last_direction' => 'in']);

        // Adjuntos
        $errors = [];
        if ($files) {
            [, $errors] = $this->attachments->store($files, $ticketId, $messageId, (int) $me->id);
        }

        $code = DB::table('tickets')->where('id', $ticketId)->value('code');
        return response()->json(['ok' => true, 'id' => $ticketId, 'code' => $code, 'warnings' => $errors]);
    }

    /**
     * GESTIÓN DE AGENTES — carga de trabajo del equipo.
     *
     * Solo para quien tiene `agents.view` (encargado / superadmin): son métricas de
     * rendimiento del equipo, no algo que un agente deba ver de sus compañeros.
     *
     * Todo se saca de UNA consulta agregada. Hacer una consulta por agente (el clásico
     * N+1) funcionaría hoy con 6 personas y se arrastraría el día que sean 30.
     */
    protected function agents(Request $request)
    {
        $me = $request->user();
        if (!$me->can('agents.view')) {
            return response()->json(['ok' => false, 'error' => 'Sin permiso'], 403);
        }

        $open = "'" . implode("','", TicketService::OPEN_STATUSES) . "'";

        $stats = DB::table('tickets')
            ->whereNotNull('assigned_to')
            ->groupBy('assigned_to')
            ->get([
                'assigned_to',
                DB::raw('COUNT(*) AS total'),
                DB::raw("SUM(status IN ($open)) AS open_n"),
                DB::raw("SUM(status IN ('resuelto','cerrado')) AS resolved_n"),
                DB::raw("SUM(priority = 'urgente' AND status IN ($open)) AS urgent_n"),
                DB::raw('AVG(CASE WHEN first_response_at IS NOT NULL
                                  THEN TIMESTAMPDIFF(MINUTE, opened_at, first_response_at) END) AS avg_response'),
                DB::raw('AVG(CASE WHEN resolved_at IS NOT NULL
                                  THEN TIMESTAMPDIFF(MINUTE, opened_at, resolved_at) END) AS avg_resolve'),
            ])
            ->keyBy('assigned_to');

        // Se listan TODOS los del helpdesk, incluidos los que aún no tienen ningún
        // ticket: un agente a cero es justo al que hay que darle trabajo.
        $agents = User::with('roles.permissions', 'permissions')->orderByRaw('name IS NULL, name ASC, email ASC')->get()
            ->filter(fn ($u) => $u->can('helpdesk.access'))
            ->map(function ($u) use ($stats) {
                $s = $stats[$u->id] ?? null;
                $total    = (int) ($s->total ?? 0);
                $resolved = (int) ($s->resolved_n ?? 0);

                return [
                    'id'           => (int) $u->id,
                    'name'         => $u->name ?: $u->email,
                    'email'        => $u->email,
                    'role'         => $u->roleName(),
                    'role_label'   => config("rbac.roles.{$u->roleName()}.label"),
                    'total'        => $total,
                    'open'         => (int) ($s->open_n ?? 0),
                    'resolved'     => $resolved,
                    'urgent'       => (int) ($s->urgent_n ?? 0),
                    // Tasa de resolución sobre lo que se le ha asignado
                    'rate'         => $total ? (int) round($resolved / $total * 100) : null,
                    'avg_response' => $s?->avg_response !== null ? (int) round($s->avg_response) : null,
                    'avg_resolve'  => $s?->avg_resolve  !== null ? (int) round($s->avg_resolve)  : null,
                ];
            })
            ->values();

        return response()->json([
            'ok'         => true,
            'agents'     => $agents,
            // Trabajo que no tiene dueño: es lo primero que mira un encargado.
            'unassigned' => DB::table('tickets')->whereNull('assigned_to')
                                ->whereIn('status', TicketService::OPEN_STATUSES)->count(),
        ]);
    }

    /**
     * HISTORIAL de un agente: sus tickets ya cerrados o resueltos.
     * Es una vista INFORMATIVA (lo que ya hizo), no su cola de trabajo.
     */
    protected function history(Request $request)
    {
        $me = $request->user();
        if (!$me->can('agents.view')) {
            return response()->json(['ok' => false, 'error' => 'Sin permiso'], 403);
        }

        $uid = (int) $request->query('user_id');
        $u = User::find($uid);
        if (!$u) return response()->json(['ok' => false, 'error' => 'Agente no encontrado'], 404);

        $rows = DB::table('tickets as t')
            ->leftJoin('contacts as c', 'c.id', '=', 't.contact_id')
            ->leftJoin('ticket_categories as cat', 'cat.id', '=', 't.category_id')
            ->where('t.assigned_to', $uid)
            ->whereIn('t.status', ['resuelto', 'cerrado'])
            ->orderByDesc(DB::raw('COALESCE(t.closed_at, t.resolved_at)'))
            ->limit(100)
            ->get([
                't.id', 't.code', 't.subject', 't.status', 't.priority', 't.channel',
                't.opened_at', 't.resolved_at', 't.closed_at',
                'c.name as contact_name', 'c.email as contact_email',
                'cat.name as category_name',
            ]);

        $rows->transform(function ($t) {
            $t->resolve_mins = $t->resolved_at ? $this->minsBetween($t->opened_at, $t->resolved_at) : null;
            $t->closed_on    = $t->closed_at ?: $t->resolved_at;
            return $t;
        });

        return response()->json([
            'ok'      => true,
            'agent'   => ['id' => $uid, 'name' => $u->name ?: $u->email, 'email' => $u->email],
            'tickets' => $rows,
        ]);
    }

    /** Respuestas predefinidas activas, para el menú «/» del editor. Cualquier agente. */
    protected function cannedList()
    {
        return response()->json([
            'ok'     => true,
            'canned' => DB::table('canned_responses')->where('active', 1)
                            ->orderBy('position')->orderBy('id')
                            ->get(['id', 'shortcut', 'title', 'body']),
        ]);
    }

    protected function minsBetween($from, $to): int
    {
        return (int) max(0, round((strtotime((string) $to) - strtotime((string) $from)) / 60));
    }
}
