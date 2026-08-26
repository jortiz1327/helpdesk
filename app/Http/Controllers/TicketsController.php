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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * tickets.php — bandeja y gestión de tickets.
 * Dispatch por ?action=  (list | stats | detail | reply | status | assign | meta | create)
 */
class TicketsController extends Controller
{
    /*
     * «Despierto» = NO pospuesto. Un ticket duerme mientras tenga fecha futura
     * (snoozed_until > now) o espere respuesta del cliente (snooze_wake_on_reply=1).
     * Se comparte entre la cola (aplicarFiltros) y los contadores (counts) para que
     * nunca discrepen: lo que no cuenta, tampoco se lista, y al revés.
     */
    protected const SQL_DESPIERTO =
        '(t.snoozed_at IS NULL OR ((t.snoozed_until IS NULL OR t.snoozed_until <= NOW()) AND t.snooze_wake_on_reply = 0))';

    /** Tamaño de página del hilo de mensajes (los últimos N al abrir; el resto, bajo demanda). */
    protected const MSG_PAGE = 60;

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
            'messages' => $this->olderMessages($request),
            'status' => $this->status($request),
            'assign' => $this->assign($request),
            'snooze'   => $this->snoozeTicket($request),
            'unsnooze' => $this->unsnoozeTicket($request),
            'set_requester' => $this->setRequester($request),
            'contact_open' => $this->contactOpen($request),
            'sched_cancel' => $this->cancelScheduled($request),
            'category' => $this->setCategory($request),
            'bulk'   => $this->bulk($request),
            'create' => $this->create($request),
            'agents'  => $this->agents($request),
            'history' => $this->history($request),
            'canned'  => $this->cannedList(),
            'note'    => $this->note($request),
            'labels'  => $this->setLabels($request),
            'export'  => $this->export($request),
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
     *  - Sin él (agente): los de SUS CATEGORÍAS (sus áreas) + los asignados a él +
     *    CUALQUIER ticket ya CERRADO (histórico compartido: un caso cerrado de otro
     *    departamento se puede consultar desde el que sea). En la bandeja del día no
     *    molesta —por defecto solo se ven los abiertos de su área—; los cerrados de
     *    otros departamentos afloran al buscar o filtrar «todos/cerrados».
     * Los tickets sin categorizar y ABIERTOS solo los ve quien tiene view_all.
     */
    protected function scope($query, User $me)
    {
        if ($me->can('tickets.view_all')) return $query;

        $cats = $me->categoryIds();
        $query->where(function ($q) use ($cats, $me) {
            if ($cats) $q->whereIn('t.category_id', $cats);
            $q->orWhere('t.assigned_to', $me->id);
            $q->orWhere('t.status', 'cerrado');   // los cerrados, para todos
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
            // Prioridad: trae su propio plazo de SLA (en minutos), que manda sobre el
            // de la categoría. La clave es única, así que el join es 1:1 (no infla).
            ->leftJoin('ticket_priorities as pri', 'pri.key', '=', 't.priority')
            ->leftJoin('users as u', 'u.id', '=', 't.assigned_to')
            // Los avisos de cron tienen su propio apartado: ni en la bandeja, ni en
            // los contadores, ni en las estadísticas de soporte.
            ->where('t.channel', '!=', 'cron');

        return $this->scope($q, $me);
    }

    /** Aplica el filtro de organización («grupo:ID» / «marca:ID» / «sede:ID») a $q. */
    protected function filtroOrg($q, string $org): void
    {
        if ($org === '' || $org === 'all' || !str_contains($org, ':')) return;
        [$nivel, $oid] = explode(':', $org, 2);
        $oid = (int) $oid;
        if (!$oid) return;
        match ($nivel) {
            'sede'  => $q->where('c.sede_id', $oid),
            'marca' => $q->whereIn('c.sede_id', fn ($sub) => $sub->select('id')->from('sedes')->where('marca_id', $oid)),
            'grupo' => $q->whereIn('c.sede_id', fn ($sub) => $sub->select('s.id')->from('sedes as s')
                            ->join('marcas as m', 'm.id', '=', 's.marca_id')->where('m.grupo_id', $oid)),
            default => null,
        };
    }

    /**
     * EXPORTAR la bandeja a Excel (.xlsx) con el diseño de marca. Usa los MISMOS
     * filtros que la lista (lo que ves = lo que sacas). Datos de clientes → permiso
     * tickets.export. Tope de seguridad para no generar exportaciones gigantes.
     */
    protected function export(Request $request)
    {
        $me = $request->user();
        if (!$me->can('tickets.export')) {
            return response()->json(['ok' => false, 'error' => 'No tienes permiso para exportar'], 403);
        }

        $q = $this->baseQuery($me);
        $this->aplicarFiltros($q, $request, $me);

        $cap   = 5000;
        $total = (clone $q)->count('t.id');
        $rows  = $q->orderByDesc('t.last_message_at')->orderByDesc('t.id')
            ->limit($cap)
            ->get([
                't.id', 't.code', 't.subject', 't.status', 't.priority', 't.channel',
                't.created_at', 't.first_response_at', 't.resolved_at',
                't.sla_response_due_at', 't.sla_resolve_due_at',
                'c.name as contact_name', 'c.email as contact_email', 'c.wa_id as contact_wa',
                'cat.name as category_name', 'u.name as agent_name',
            ]);

        // Etiquetas de cada ticket, en una consulta.
        $labels = $this->labelsFor($rows->pluck('id')->all());
        foreach ($rows as $r) {
            $r->labels_txt = collect($labels[$r->id] ?? [])->pluck('name')->implode(', ');
        }

        $canTimes = $me->can('tickets.view_times');
        $slaOn = SlaService::activo();
        foreach ($rows as $r) {
            // Estado del SLA en una palabra (solo para quien ve tiempos y si el SLA está activo).
            $r->sla_txt = null;
            if ($canTimes && $slaOn) {
                $vivo = in_array($r->status, TicketService::OPEN_STATUSES, true);
                $vencido = ($r->sla_resolve_due_at && $r->sla_resolve_due_at < now())
                    || ($r->sla_response_due_at && $r->sla_response_due_at < now() && !$r->first_response_at);
                $r->sla_txt = !$r->sla_response_due_at && !$r->sla_resolve_due_at ? '—'
                    : ($vivo && $vencido ? 'Vencido' : 'En plazo');
            }
        }

        $xlsx = app(\App\Services\TicketXlsx::class)->build($rows, [
            'total'     => $total,
            'cap'       => $cap,
            'can_times' => $canTimes,
            'sla_on'    => $slaOn,
            'filtros'   => $this->resumenFiltros($request),
            'agente'    => $me->name ?: $me->email,
        ]);

        $nombre = 'tickets-' . now()->format('Ymd-Hi') . '.xlsx';
        return response($xlsx, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $nombre . '"',
            'Cache-Control'       => 'no-store',
        ]);
    }

    /** Resumen legible del filtro aplicado, para la cabecera del Excel. */
    protected function resumenFiltros(Request $request): string
    {
        $p = [];
        if (($s = trim((string) $request->query('q', ''))) !== '') $p[] = "búsqueda «{$s}»";
        $st = (string) $request->query('status', 'open');
        $p[] = $st === 'open' ? 'activos' : ($st === 'all' ? 'todos los estados' : (TicketService::STATUSES[$st] ?? $st));
        if ($request->query('reply') === 'pending')  $p[] = 'sin responder';
        if ($request->query('reply') === 'answered') $p[] = 'respondidos';
        if ($request->query('sla') === 'late')       $p[] = 'SLA vencido';
        $a = (string) $request->query('assigned', 'all');
        if ($a === 'none') $p[] = 'sin asignar';
        elseif ($a === 'me') $p[] = 'míos';
        if (($cat = (int) $request->query('category', 0)) > 0) {
            $p[] = 'categoría: ' . (DB::table('ticket_categories')->where('id', $cat)->value('name') ?? $cat);
        }
        if (($lbl = (int) $request->query('label', 0)) > 0) {
            $p[] = 'etiqueta: ' . (DB::table('ticket_labels')->where('id', $lbl)->value('name') ?? $lbl);
        }
        return implode(' · ', $p);
    }

    /**
     * Aplica al query `$q` (ya con baseQuery) TODOS los filtros de la bandeja según
     * la petición. Vive aparte para que la LISTA y el EXPORT filtren idéntico.
     */
    protected function aplicarFiltros($q, Request $request, $me): void
    {
        $s = trim((string) $request->query('q', ''));
        $donde = $request->query('search_in') === 'messages' ? 'messages' : 'ficha';

        if ($s !== '' && $donde === 'ficha') {
            [$termino] = $this->terminoTextoCompleto($s);
            $like = "%$s%";
            $q->where(function ($w) use ($s, $termino, $like) {
                // Código: subcadena sobre una columna corta (barato, aunque no use índice).
                $w->orWhere('t.code', 'like', $like);

                // Asunto: por FULLTEXT si el término es aprovechable (≥3 letras). Va en
                // subconsulta NO correlacionada para que el índice sí se use (un MATCH
                // OR-eado con otros LIKE no lo aprovecharía). Si el término es corto, LIKE.
                if ($termino) {
                    $w->orWhereIn('t.id', function ($sub) use ($termino) {
                        $sub->select('id')->from('tickets')
                            ->whereRaw('MATCH(subject) AGAINST (? IN BOOLEAN MODE)', [$termino]);
                    });
                } else {
                    $w->orWhere('t.subject', 'like', $like);
                }

                // Datos del contacto: subconsulta sobre `contacts` (tabla pequeña, no el
                // join de 50k). Nombre/correo por FULLTEXT cuando aplica; teléfono y correo
                // por subcadena (un número parcial o un dominio no son «palabras»).
                $w->orWhereIn('t.contact_id', function ($sub) use ($s, $termino, $like) {
                    $sub->select('id')->from('contacts')->where(function ($cc) use ($s, $termino, $like) {
                        $termino
                            ? $cc->whereRaw('MATCH(name, email) AGAINST (? IN BOOLEAN MODE)', [$termino])
                            : $cc->where('name', 'like', $like);
                        $cc->orWhere('wa_id', 'like', $like)
                           ->orWhere('email', 'like', $like);
                    });
                });
            });
        } elseif ($s !== '') {
            $q->whereExists(function ($w) use ($s) {
                $w->select(DB::raw(1))->from('messages as ms')
                  ->whereColumn('ms.ticket_id', 't.id');
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
                $a === 'me'   => $q->where('t.assigned_to', $me->id),
                $a === 'none' => $q->whereNull('t.assigned_to'),
                default       => $q->where('t.assigned_to', (int) $a),
            };
        }

        $this->filtroOrg($q, (string) $request->query('org', ''));

        if (($lbl = (int) $request->query('label', 0)) > 0) {
            $q->whereIn('t.id', function ($sub) use ($lbl) {
                $sub->select('ticket_id')->from('ticket_label_ticket')->where('label_id', $lbl);
            });
        }

        if ($request->query('sla') === 'late' && SlaService::activo()) {
            $q->whereIn('t.status', TicketService::OPEN_STATUSES)
              ->whereNull('t.sla_paused_since')   // en pausa ≠ vencido (reloj parado)
              ->where(function ($w) {
                  $w->where('t.sla_resolve_due_at', '<', now())
                    ->orWhere(fn ($x) => $x->where('t.sla_response_due_at', '<', now())
                                           ->whereNull('t.first_response_at'));
              });
        }

        if (($r = $request->query('reply', '')) === 'pending') {
            $q->where('t.last_direction', 'in');
        } elseif ($r === 'answered') {
            $q->where('t.last_direction', 'out');
        }

        /*
         * POSPUESTOS. Por defecto la cola OCULTA los dormidos (no molestan). La vista
         * «Pospuestos» (snoozed=only) enseña solo esos; snoozed=all no filtra. El
         * ocultado por defecto se aplica solo a la cola diaria («Abiertos») y cuando no
         * hay búsqueda: así buscar por código/texto sí encuentra un ticket dormido.
         */
        $snz = $request->query('snoozed', '');
        if ($snz === 'only') {
            $q->whereNotNull('t.snoozed_at')->whereRaw('NOT ' . self::SQL_DESPIERTO);
        } elseif ($snz !== 'all' && $status === 'open' && $s === '') {
            $q->whereRaw(self::SQL_DESPIERTO);
        }
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
        // Filtros de la bandeja (búsqueda, estado, prioridad, categoría, canal, asignado,
        // organización, etiqueta, SLA, sin-responder). Compartidos con el EXPORT para que
        // «lo que ves» y «lo que exportas» sean exactamente lo mismo.
        $this->aplicarFiltros($q, $request, $me);

        // La búsqueda y su modo se necesitan más abajo para resaltar el fragmento
        // encontrado; `aplicarFiltros()` los usa por dentro, así que aquí se recalculan
        // (mismo criterio) para tenerlos en el ámbito de list().
        $s     = trim((string) $request->query('q', ''));
        $donde = $request->query('search_in') === 'messages' ? 'messages' : 'ficha';

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
            // Quién tiene el ticket abierto AHORA (presencia «alguien está viendo esto»).
            // El join va aquí y no en baseQuery para no cargar el contador con él.
            ->leftJoin('users as lk', 'lk.id', '=', 't.locked_by')
            // ORDEN: por ÚLTIMA ACTIVIDAD, no por fecha de creación. Un ticket de hace un
            // mes con una respuesta de hace un minuto tiene que salir el primero.
            ->orderByDesc('t.last_message_at')
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
                't.contact_id', 't.merged_into_id', 't.category_id',
                'c.name as contact_name', 'c.email as contact_email', 'c.wa_id as contact_wa',
                'cat.name as category_name', 'cat.color as category_color',
                'cat.sla_response_hours', 'cat.sla_resolve_hours', 't.sla_paused_minutes', 't.sla_paused_since',
                'pri.sla_response_mins as pri_response_mins', 'pri.sla_resolve_mins as pri_resolve_mins',
                'u.name as agent_name', 'u.email as agent_email',
                't.last_direction',
                // Posponer: el chip «💤 hasta …» y su motivo.
                't.snoozed_until', 't.snooze_wake_on_reply', 't.snoozed_by', 't.snooze_reason',
                // Presencia: quién lo tiene abierto y desde cuándo (vigencia la calcula el front).
                't.locked_by', 't.locked_at', 'lk.name as locked_name',
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

        // Etiquetas de todos los tickets de la página, en UNA consulta (no una por fila).
        $labels = $this->labelsFor($rows->pluck('id')->all());
        foreach ($rows as $t) $t->labels = $labels[$t->id] ?? [];

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
            unset($t->first_response_at, $t->resolved_at, $t->opened_at, $t->sla_response_hours, $t->sla_resolve_hours, $t->sla_paused_minutes, $t->sla_paused_since, $t->pri_response_mins, $t->pri_resolve_mins);
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
        /*
         * Se CACHEA 15 s por usuario. Los contadores son agregaciones sobre todo el
         * alcance (para un encargado con view_all, un barrido de ~50k) y se recalculaban
         * en CADA carga/filtro/refresco. Con la caché se cuentan como mucho una vez cada
         * 15 s: un correo/mensaje nuevo se refleja en el siguiente recálculo (≤15 s) y las
         * propias acciones también. Desfase asumido a cambio de no recontar sin parar.
         */
        return Cache::remember("tk.counts.{$me->id}", 15, fn () => $this->calcularCounts($me));
    }

    protected function calcularCounts(User $me): array
    {
        // «Despierto» = NO dormido. Un ticket pospuesto está fuera de tu plato: no cuenta
        // en Activos/Pendientes/Míos/Sin asignar (igual que sale de la cola por defecto).
        $despierto = self::SQL_DESPIERTO;
        $abiertos  = "(t.status IN ('" . implode("','", TicketService::OPEN_STATUSES) . "') AND $despierto)";

        // Fuera de plazo: o se pasó la resolución, o se pasó la respuesta sin contestar.
        // Un ticket con el reloj EN PAUSA (sla_paused_since puesto: esperando cliente,
        // resuelto o cerrado) no está «vencido»: su due_at guardado está congelado y no
        // incluye la pausa en curso. Se excluye, igual que hace el cron sla:check.
        $vencido = SlaService::activo()
            ? "(t.sla_paused_since IS NULL AND (t.sla_resolve_due_at < NOW()
                OR (t.sla_response_due_at < NOW() AND t.first_response_at IS NULL)))"
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
             SUM(NOT ($despierto)) AS pospuestos,
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
            'snoozed'    => (int) ($r->pospuestos ?? 0),   // dormidos (vista «Pospuestos»)
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
            ->orderByDesc('t.last_message_at')
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
            'urgent'    => (clone $this->baseQuery($me))->where('t.priority', TicketService::topPriorityKey() ?? 'urgente')
                                ->whereIn('t.status', TicketService::OPEN_STATUSES)->count(),
            'by_status' => $byStatus,
            'recent'    => $recent,
        ]);
    }

    /** Catálogos para los filtros y los selectores. */
    protected function meta()
    {
        // Van TODOS (para resolver el nombre de un asignado que ya no está), con el flag
        // `active`: el frontend solo ofrece los activos en «asignar», pero sigue mostrando
        // el nombre del inactivo en los tickets históricos.
        $users = User::with('roles.permissions', 'permissions')->orderByRaw('name IS NULL, name ASC, email ASC')->get()
            ->filter(fn ($u) => $u->can('helpdesk.access'))
            ->map(fn ($u) => ['id' => (int) $u->id, 'name' => $u->name ?: $u->email, 'active' => (bool) $u->active])
            ->values();

        return response()->json([
            'ok'         => true,
            'statuses'   => TicketService::STATUSES,
            'status_meta' => TicketService::statusMeta(),   // etiqueta + color por estado
            'priorities' => TicketService::priorities(),
            // Con su color, para pintar las etiquetas sin quemarlos en el CSS.
            'priority_meta' => TicketService::prioritiesMeta(),
            'categories' => DB::table('ticket_categories')->where('active', 1)->orderBy('position')->get(),
            'labels'     => \App\Models\TicketLabel::activas(),   // catálogo activo (filtro + acción masiva)
            'users'      => $users,   // a quién se puede asignar
            // Minutos que dura el bloqueo: el frontend lo usa para saber si la marca
            // «alguien está viendo esto» de la lista sigue vigente. 0 = función apagada.
            'lock_minutes' => app(TicketLockService::class)->minutes(),
        ]);
    }

    /** Un ticket con su hilo completo (el chat). */
    /**
     * Pone/quita las ETIQUETAS de un ticket (reemplaza el conjunto entero). Cualquier
     * agente que vea el ticket puede etiquetarlo; el catálogo lo gestionan aparte los
     * encargados. Solo se aceptan ids de etiquetas que existan (nada de colar basura).
     */
    protected function setLabels(Request $request)
    {
        $me = $request->user();
        $id = (int) $request->input('id');
        $t  = (clone $this->baseQuery($me))->where('t.id', $id)->first(['t.id']);
        if (!$t) return response()->json(['ok' => false, 'error' => 'Ticket no encontrado'], 404);

        $pedidos = collect($request->input('label_ids', []))->map(fn ($x) => (int) $x)->filter()->unique();
        $validos = \App\Models\TicketLabel::whereIn('id', $pedidos)->pluck('id')->all();

        DB::table('ticket_label_ticket')->where('ticket_id', $id)->delete();
        if ($validos) {
            DB::table('ticket_label_ticket')->insert(
                array_map(fn ($lid) => ['ticket_id' => $id, 'label_id' => $lid], $validos),
            );
        }

        return response()->json(['ok' => true, 'labels' => $this->labelsFor([$id])[$id] ?? []]);
    }

    /** Etiquetas de un conjunto de tickets: [ticketId => [{id,name,color}, …]]. Sin N+1. */
    protected function labelsFor(array $ticketIds): array
    {
        if (!$ticketIds) return [];
        $rows = DB::table('ticket_label_ticket as p')
            ->join('ticket_labels as l', 'l.id', '=', 'p.label_id')
            ->whereIn('p.ticket_id', $ticketIds)
            ->orderBy('l.position')->orderBy('l.id')
            ->get(['p.ticket_id', 'l.id', 'l.name', 'l.color']);

        $out = [];
        foreach ($rows as $r) {
            $out[$r->ticket_id][] = ['id' => (int) $r->id, 'name' => $r->name, 'color' => $r->color];
        }
        return $out;
    }

    protected function detail(Request $request)
    {
        $me = $request->user();
        $id = (int) $request->query('id');

        $t = (clone $this->baseQuery($me))
            ->leftJoin('sedes as sede', 'sede.id', '=', 'c.sede_id')   // nombre de la sede para {{sede}}
            ->where('t.id', $id)->first([
                't.*', 'c.name as contact_name', 'c.email as contact_email', 'c.wa_id as contact_wa', 'c.sede_id as contact_sede_id',
                'sede.name as contact_sede_name',
                'cat.name as category_name', 'cat.color as category_color',
                'cat.sla_response_hours', 'cat.sla_resolve_hours', 't.sla_paused_minutes', 't.sla_paused_since',
                'pri.sla_response_mins as pri_response_mins', 'pri.sla_resolve_mins as pri_resolve_mins',
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
        unset($t->sla_response_hours, $t->sla_resolve_hours, $t->sla_paused_minutes, $t->sla_paused_since, $t->pri_response_mins, $t->pri_resolve_mins);

        // Los tiempos no se envían a quien no puede verlos (ocultarlos en la UI no basta).
        if (!$me->can('tickets.view_times')) {
            unset($t->first_response_at, $t->resolved_at);
        }

        // Valoración del cliente (CSAT), si la dejó. null = sin valorar.
        $t->rating = DB::table('ticket_ratings')->where('ticket_id', $id)
            ->first(['score', 'comment', 'updated_at']);

        // Etiquetas puestas a este ticket.
        $t->labels = $this->labelsFor([$id])[$id] ?? [];

        // Respuestas PROGRAMADAS pendientes (para el bloque «saldrá a las…» del hilo).
        $t->scheduled = app(\App\Services\ScheduledReplyService::class)->pendingFor($id);

        // Campos personalizados (globales) + el valor que tenga este ticket en cada uno.
        $defsCampos = DB::table('ticket_custom_fields')->where('active', 1)
            ->orderBy('position')->orderBy('id')->get(['id', 'key', 'label', 'type', 'options', 'required']);
        $valsCampos = DB::table('ticket_field_values')->where('ticket_id', $id)->pluck('value', 'field_id');
        $t->custom_fields = $defsCampos->map(fn ($d) => [
            'id'       => (int) $d->id,
            'label'    => $d->label,
            'type'     => $d->type,
            'options'  => $d->options ? json_decode($d->options, true) : [],
            'required' => (bool) $d->required,
            'value'    => $valsCampos[$d->id] ?? null,
        ])->values();

        // Mensajes: solo los ÚLTIMOS N. Un ticket de WhatsApp (1 contacto = 1 ticket
        // para siempre) puede acumular miles; cargarlos todos al abrir era el mayor coste.
        // Los anteriores se piden bajo demanda (acción `messages`).
        $totalMsg = DB::table('messages')->where('ticket_id', $id)->count();
        $messages = $this->cargarMensajes($id, null, self::MSG_PAGE);

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
            // ¿Hay mensajes anteriores a la página cargada? (para el botón «cargar más»).
            'messages_more' => $totalMsg > $messages->count(),
            // Copias que ya estaban en la conversación, para proponerlas al responder.
            'cc_sugerido' => $this->ccDelHilo($messages, (string) $t->contact_email),
        ]);
    }

    /**
     * Carga una PÁGINA de mensajes de un ticket (los `$limit` más recientes con id menor
     * que `$before`, o los últimos si `$before` es null), en orden ascendente para pintar.
     * Cuelga adjuntos y el motivo de fallo de entrega. Compartido por detail() y messages().
     */
    protected function cargarMensajes(int $id, ?int $before, int $limit)
    {
        $q = DB::table('messages as m')
            ->leftJoin('users as au', 'au.id', '=', 'm.author_user_id')
            ->where('m.ticket_id', $id);
        if ($before) $q->where('m.id', '<', $before);

        $messages = $q->orderByDesc('m.id')->limit($limit)
            ->get([
                'm.id', 'm.direction', 'm.channel', 'm.type', 'm.body', 'm.is_html', 'm.is_internal_note',
                'm.media_url', 'm.media_mime', 'm.status', 'm.author_user_id', 'm.created_at',
                'm.cc', 'm.bcc', 'm.payload',
                'au.name as author_name', 'au.email as author_email',
            ])
            ->reverse()->values();   // se leyó desc (para el LIMIT); se pinta asc

        $byMessage = $this->attachments->forTicket($id);
        foreach ($messages as $m) {
            $m->attachments = $byMessage[$m->id] ?? [];
            if ($m->payload && ($p = json_decode($m->payload, true)) && !empty($p['delivery_error'])) {
                $m->delivery_error = $p['delivery_error'];
            }
            unset($m->payload);
        }
        return $messages;
    }

    /** Mensajes ANTERIORES a uno dado (paginación hacia atrás del hilo). */
    protected function olderMessages(Request $request)
    {
        $me = $request->user();
        $id = (int) $request->query('id');
        if (!(clone $this->baseQuery($me))->where('t.id', $id)->exists()) {
            return response()->json(['ok' => false, 'error' => 'No tienes acceso a ese ticket'], 403);
        }
        $before   = (int) $request->query('before');
        $messages = $this->cargarMensajes($id, $before ?: null, self::MSG_PAGE);
        $firstId  = $messages->first()->id ?? null;
        $more     = $firstId
            ? DB::table('messages')->where('ticket_id', $id)->where('id', '<', $firstId)->exists()
            : false;

        return response()->json(['ok' => true, 'messages' => $messages, 'more' => $more]);
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
        $t = (clone $this->baseQuery($me))->where('t.id', $id)->first(['t.id', 't.code', 't.contact_id', 'c.wa_id as contact_wa']);
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

        // @menciones: avisa a los agentes citados en la nota (centro de notificaciones).
        // El frontend manda los ids elegidos en el selector; aquí solo se validan como
        // usuarios reales. push() se salta la autonotificación (mencionarte a ti mismo).
        $mentions = array_values(array_unique(array_filter(array_map('intval', (array) $request->input('mentions', [])))));
        if ($mentions) {
            $validos  = DB::table('users')->whereIn('id', $mentions)->pluck('id');
            $extracto = mb_substr(trim(HtmlSanitizer::toText($html)), 0, 140);
            $cuerpo   = "{$me->name} te mencionó en {$t->code}" . ($extracto !== '' ? ": «{$extracto}»" : '');
            foreach ($validos as $uid) {
                app(\App\Services\NotificationService::class)->push((int) $uid, 'mention', $cuerpo, (int) $t->id, (int) $me->id);
            }
        }

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

        $data = $request->validate(['id' => ['required', 'integer']], ['id.required' => 'Falta el ticket']);
        $id = (int) $data['id'];
        $t  = (clone $this->baseQuery($me))->where('t.id', $id)
            ->first(['t.id', 't.code', 't.subject', 't.channel', 't.contact_id',
                     'c.email as contact_email', 'c.name as contact_name', 'c.wa_id as contact_wa',
                     'cat.signature as cat_signature', 'cat.name as category_name']);
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

        $svc = app(\App\Services\TicketReplyService::class);

        // Canal WhatsApp: candado por nivel (necesita número de soporte). El envío lo
        // hace el servicio; el candado y los permisos son de la frontera HTTP (aquí).
        if ($t->channel === 'whatsapp') {
            if ($g = \App\Services\GatingService::guard('wa_ticket_reply')) return $g;
            return $this->respuesta($svc->porWhatsapp($t, $html, (array) $files, $me));
        }

        // De momento el resto solo se responde por CORREO (los demás canales aún no envían).
        if ($t->channel !== 'email') {
            return response()->json(['ok' => false, 'error' => 'El envío para el canal «' . $t->channel . '» aún no está disponible'], 422);
        }

        // COPIAS del hilo que el agente mantiene/edita; el filtrado fino (repetidos,
        // nuestro propio buzón) lo hace sendMail() justo antes de enviar.
        $cc  = $this->direcciones($request->input('cc'));
        $bcc = $this->direcciones($request->input('bcc'));

        // PROGRAMAR el envío (redactar ahora, salir en horario laboral o a una fecha).
        // Solo correo. Si no viene `schedule`, se envía al momento como siempre.
        if (($schedule = (string) $request->input('schedule', '')) !== '') {
            $sendAt = $this->resolverProgramacion($schedule, $request->input('send_at'));
            if (!$sendAt) return response()->json(['ok' => false, 'error' => 'Elige cuándo enviarla'], 400);

            $sid = app(\App\Services\ScheduledReplyService::class)
                ->schedule($id, $html, (array) $files, $cc, $bcc, $sendAt, (int) $me->id);
            return response()->json(['ok' => true, 'scheduled' => true, 'id' => $sid, 'send_at' => $sendAt->toIso8601String()]);
        }

        return $this->respuesta($svc->porCorreo($t, $html, (array) $files, $cc, $bcc, $me));
    }

    /** Traduce el preset de programación a una fecha/hora concreta (o null si no vale). */
    protected function resolverProgramacion(string $preset, $custom): ?\Illuminate\Support\Carbon
    {
        $bh = app(\App\Services\BusinessHoursService::class);
        return match ($preset) {
            'business' => $bh->proximaApertura(now()),                          // próxima apertura
            'tomorrow' => $bh->proximaApertura(now()->addDay()->startOfDay()),  // mañana, al abrir
            'custom'   => (function () use ($custom) {
                try { $d = \Illuminate\Support\Carbon::parse((string) $custom); } catch (\Throwable $e) { return null; }
                return $d->isFuture() ? $d : null;
            })(),
            default => null,
        };
    }

    /** Cancela una respuesta programada (pendiente) del ticket. */
    protected function cancelScheduled(Request $request)
    {
        $me = $request->user();
        if (!$me->can('tickets.reply')) return response()->json(['ok' => false, 'error' => 'Sin permiso'], 403);

        $sid = (int) $request->input('sched_id');
        if (!$sid) return response()->json(['ok' => false, 'error' => 'Falta la programación'], 400);

        return response()->json(['ok' => app(\App\Services\ScheduledReplyService::class)->cancel($sid)]);
    }

    /** Traduce el resultado de TicketReplyService (array, con opcional _status) a JSON. */
    protected function respuesta(array $r)
    {
        $status = $r['_status'] ?? 200;
        unset($r['_status']);
        return response()->json($r, $status);
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

        // Nº de mensajes de todos los candidatos + el principal en UNA consulta (antes
        // era un count() por cada uno: hasta 51 consultas por diálogo de fusión).
        $ids = $otros->pluck('id')->push($t->id)->all();
        $conteos = DB::table('messages')->whereIn('ticket_id', $ids)->where('is_internal_note', 0)
            ->groupBy('ticket_id')->selectRaw('ticket_id, COUNT(*) n')->pluck('n', 'ticket_id');
        foreach ($otros as $o) {
            $o->messages = (int) ($conteos[$o->id] ?? 0);
        }

        return response()->json([
            'ok'      => true,
            'ticket'  => $t,
            'others'  => $otros,
            'messages' => (int) ($conteos[$t->id] ?? 0),
        ]);
    }

    /** Ejecuta la fusión. La comprobación de verdad está en TicketService::merge(). */
    protected function merge(Request $request)
    {
        $me = $request->user();
        if (!$request->isMethod('post')) return response()->json(['ok' => false, 'error' => 'Método no permitido'], 405);
        if (!$me->can('tickets.reply')) return response()->json(['ok' => false, 'error' => 'No tienes permiso'], 403);

        $data = $request->validate([
            'into' => ['required', 'integer'],
            'from' => ['required', 'integer'],
        ], [
            'into.required' => 'Falta el ticket principal',
            'from.required' => 'Falta el ticket a fusionar',
        ]);
        $principal = (int) $data['into'];
        $absorbido = (int) $data['from'];

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

        $data = $request->validate([
            'id'     => ['required', 'integer'],
            'status' => ['required', \Illuminate\Validation\Rule::in(array_keys(TicketService::STATUSES))],
        ], [
            'id.required'     => 'Falta el ticket',
            'status.required' => 'Falta el estado',
            'status.in'       => 'Estado no válido',
        ]);

        $this->tickets->setStatus((int) $data['id'], $data['status'], (int) $me->id);
        return response()->json(['ok' => true]);
    }

    protected function assign(Request $request)
    {
        $me   = $request->user();
        $data = $request->validate([
            'id'      => ['required', 'integer'],
            'user_id' => ['nullable', 'integer'],
        ], ['id.required' => 'Falta el ticket']);
        $id     = (int) $data['id'];
        $target = !empty($data['user_id']) ? (int) $data['user_id'] : null;

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

    /** Cambia la categoría del ticket (o la quita si viene vacía). */
    protected function setCategory(Request $request)
    {
        $me = $request->user();
        if (!$me->can('tickets.categorize')) {
            return response()->json(['ok' => false, 'error' => 'No tienes permiso para cambiar la categoría'], 403);
        }
        $id = (int) $request->input('id');
        if (!$id) return response()->json(['ok' => false, 'error' => 'Falta el ticket'], 400);

        $catId = $request->input('category_id');
        $catId = $catId ? (int) $catId : null;
        if ($catId && !DB::table('ticket_categories')->where('id', $catId)->exists()) {
            return response()->json(['ok' => false, 'error' => 'Categoría no válida'], 400);
        }

        $this->tickets->setCategory($id, $catId, (int) $me->id);
        return response()->json(['ok' => true]);
    }

    /**
     * POSPONER un ticket. Cualquier agente puede posponer un ticket que ve. `preset`
     * fija el «cuándo»; `reply` lo aparta hasta que el cliente conteste; `custom` usa
     * la fecha de `until`. `reason` es un motivo corto opcional.
     */
    protected function snoozeTicket(Request $request)
    {
        $me = $request->user();
        $id = (int) $request->input('id');
        if (!$id) return response()->json(['ok' => false, 'error' => 'Falta el ticket'], 400);
        if (!(clone $this->baseQuery($me))->where('t.id', $id)->exists()) {
            return response()->json(['ok' => false, 'error' => 'No tienes acceso a ese ticket'], 403);
        }

        $preset = (string) $request->input('preset', '');
        $reason = trim((string) $request->input('reason', ''));
        $wakeOnReply = false;
        $until = null;

        if ($preset === 'reply') {
            $wakeOnReply = true;
        } elseif ($preset === 'custom') {
            try { $until = \Illuminate\Support\Carbon::parse((string) $request->input('until', '')); }
            catch (\Throwable $e) { $until = null; }
            if (!$until || $until->isPast()) {
                return response()->json(['ok' => false, 'error' => 'Elige una fecha futura'], 400);
            }
        } else {
            $until = $this->snoozePreset($preset);
            if (!$until) return response()->json(['ok' => false, 'error' => 'Elige cuándo retomarlo'], 400);
        }

        $this->tickets->snooze($id, $until, $wakeOnReply, (int) $me->id, $reason ?: null);
        return response()->json(['ok' => true]);
    }

    /**
     * CAMBIAR EL SOLICITANTE (dueño) del ticket: corrige el correo del cliente (p. ej.
     * un compañero lo escribió mal). Busca un contacto con ese correo o lo crea, y apunta
     * SOLO este ticket a él (el contacto viejo se queda con sus otros tickets). Queda en
     * el historial. Al responder, el correo ya sale al cliente correcto.
     */
    protected function setRequester(Request $request)
    {
        $me = $request->user();
        if (!$me->can('tickets.reply')) return response()->json(['ok' => false, 'error' => 'Sin permiso'], 403);

        $data = $request->validate([
            'id'    => ['required', 'integer'],
            'email' => ['required', 'email'],
            'name'  => ['nullable'],
        ], ['email.required' => 'El correo es obligatorio', 'email.email' => 'El correo no es válido']);

        $id = (int) $data['id'];
        if (!(clone $this->baseQuery($me))->where('t.id', $id)->exists()) {
            return response()->json(['ok' => false, 'error' => 'No tienes acceso a ese ticket'], 403);
        }

        $email = trim($data['email']);
        $name  = trim((string) ($data['name'] ?? ''));

        $actual  = (int) DB::table('tickets')->where('id', $id)->value('contact_id');
        $antiguo = DB::table('contacts')->where('id', $actual)->value('email') ?: '—';

        // Buscar/crear el contacto por correo; no se pisa el nombre de uno que ya existe.
        $nuevo = ChatService::upsertContactByEmail($email, $name ?: null, false);
        if ((int) $nuevo === $actual) {
            return response()->json(['ok' => true, 'unchanged' => true]);
        }

        DB::table('tickets')->where('id', $id)->update(['contact_id' => $nuevo]);
        $this->tickets->event($id, 'requester', $antiguo, $email, (int) $me->id);
        $this->tickets->broadcast('updated', $id);

        return response()->json(['ok' => true]);
    }

    /** Reactivar ahora un ticket dormido (vuelve a la cola). */
    protected function unsnoozeTicket(Request $request)
    {
        $me = $request->user();
        $id = (int) $request->input('id');
        if (!$id) return response()->json(['ok' => false, 'error' => 'Falta el ticket'], 400);
        if (!(clone $this->baseQuery($me))->where('t.id', $id)->exists()) {
            return response()->json(['ok' => false, 'error' => 'No tienes acceso a ese ticket'], 403);
        }

        $this->tickets->wake($id, 'manual');
        return response()->json(['ok' => true]);
    }

    /**
     * ¿Este cliente (por email o teléfono) ya tiene incidencias ABIERTAS? Lo usa el
     * formulario de «Nuevo ticket» para avisar antes de duplicar. Devuelve las abiertas
     * del contacto (código, asunto, agente) o lista vacía si no hay contacto/abiertas.
     */
    protected function contactOpen(Request $request)
    {
        $me = $request->user();
        if (!$me->can('tickets.create')) return response()->json(['ok' => false], 403);

        $email = trim((string) $request->query('email', ''));
        $phone = preg_replace('/\D+/', '', (string) $request->query('phone', ''));
        if ($email === '' && $phone === '') return response()->json(['ok' => true, 'tickets' => []]);

        $cid = DB::table('contacts')
            ->when($email !== '', fn ($q) => $q->whereRaw('LOWER(email) = ?', [mb_strtolower($email)]),
                fn ($q) => $q->where('wa_id', $phone))
            ->value('id');
        if (!$cid) return response()->json(['ok' => true, 'tickets' => []]);

        // baseQuery aplica scope(): solo avisa de las incidencias que este usuario PUEDE
        // ver (sus áreas / asignadas). Sin esto se filtraban las de otros departamentos.
        $tickets = (clone $this->baseQuery($me))
            ->where('t.contact_id', $cid)
            ->whereIn('t.status', TicketService::OPEN_STATUSES)
            ->whereNull('t.merged_into_id')
            ->orderByDesc('t.last_message_at')->limit(10)
            ->get(['t.id', 't.code', 't.subject', 't.status', 'u.name as agent_name', 't.created_at']);

        return response()->json(['ok' => true, 'tickets' => $tickets]);
    }

    /** Traduce un preset («el lunes», «mañana»…) a una fecha/hora concreta. */
    protected function snoozePreset(string $preset): ?\Illuminate\Support\Carbon
    {
        $now = now();
        return match ($preset) {
            // «Por la mañana» = 8:00 (como Teams). Si aún no son las 16:00, esta tarde; si ya pasó, mañana a primera hora.
            'later_today' => $now->hour < 16 ? $now->copy()->setTime(16, 0) : $now->copy()->addDay()->setTime(8, 0),
            'tomorrow'    => $now->copy()->addDay()->setTime(8, 0),
            'monday'      => $now->copy()->next(\Carbon\Carbon::MONDAY)->setTime(8, 0),
            'week'        => $now->copy()->addWeek()->setTime(8, 0),
            default       => null,
        };
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

        } elseif ($op === 'label') {
            // Poner o quitar UNA etiqueta a los seleccionados (etiquetar = cualquier agente).
            $labelId = (int) $request->input('label_id');
            $quitar  = $request->input('mode') === 'remove';
            if (!\App\Models\TicketLabel::where('id', $labelId)->exists()) {
                return response()->json(['ok' => false, 'error' => 'Etiqueta no válida'], 400);
            }
            foreach ($visible as $tid) {
                if ($quitar) {
                    DB::table('ticket_label_ticket')->where('ticket_id', $tid)->where('label_id', $labelId)->delete();
                } else {
                    DB::table('ticket_label_ticket')->insertOrIgnore(['ticket_id' => $tid, 'label_id' => $labelId]);
                }
                $n++;
            }

        } elseif ($op === 'restore') {
            // DESHACER un cambio de estado en lote: cada ticket vuelve a SU estado previo
            // (heterogéneo). `states` = [{id, status}, …] que capturó el frontend.
            if (!$me->can('tickets.close')) return response()->json(['ok' => false, 'error' => 'Sin permiso'], 403);
            foreach ((array) $request->input('states', []) as $s) {
                $tid = (int) ($s['id'] ?? 0);
                $st  = (string) ($s['status'] ?? '');
                if ($tid && array_key_exists($st, TicketService::STATUSES)
                    && in_array($tid, $visible, true)
                    && $this->tickets->setStatus($tid, $st, (int) $me->id)) $n++;
            }

        } elseif ($op === 'priority') {
            if (!$me->can('tickets.categorize')) return response()->json(['ok' => false, 'error' => 'Sin permiso'], 403);
            $pr = (string) $request->input('priority');
            if (!array_key_exists($pr, TicketService::priorities())) {
                return response()->json(['ok' => false, 'error' => 'Prioridad no válida'], 400);
            }
            foreach ($visible as $tid) { if ($this->tickets->setPriority($tid, $pr, (int) $me->id)) $n++; }

        } elseif ($op === 'category') {
            if (!$me->can('tickets.categorize')) return response()->json(['ok' => false, 'error' => 'Sin permiso'], 403);
            $catId = $request->input('category_id');
            $catId = $catId ? (int) $catId : null;
            if ($catId && !DB::table('ticket_categories')->where('id', $catId)->exists()) {
                return response()->json(['ok' => false, 'error' => 'Categoría no válida'], 400);
            }
            foreach ($visible as $tid) { $this->tickets->setCategory($tid, $catId, (int) $me->id); $n++; }

        } elseif ($op === 'merge') {
            // Fusión en lote: todos los seleccionados en UNO. Deben ser del MISMO cliente
            // (como la fusión individual) y el principal es el más antiguo (id menor).
            if (!$me->can('tickets.reply')) return response()->json(['ok' => false, 'error' => 'Sin permiso'], 403);
            if (count($visible) < 2) return response()->json(['ok' => false, 'error' => 'Selecciona al menos dos tickets'], 400);

            $motivo = trim((string) $request->input('reason', ''));
            if ($motivo === '') return response()->json(['ok' => false, 'error' => 'Escribe el motivo de la fusión'], 400);

            $contactos = DB::table('tickets')->whereIn('id', $visible)->distinct()->pluck('contact_id');
            if ($contactos->count() > 1) {
                return response()->json(['ok' => false, 'error' => 'Solo se pueden fusionar tickets del mismo cliente'], 400);
            }

            sort($visible);                          // el principal = el más antiguo
            $principal = array_shift($visible);
            $errores = [];
            foreach ($visible as $tid) {
                [$ok, $err] = $this->tickets->merge($principal, $tid, (int) $me->id, $motivo);
                if ($ok) $n++; else $errores[] = $err;
            }
            if ($n === 0) return response()->json(['ok' => false, 'error' => $errores[0] ?? 'No se pudo fusionar'], 400);
            return response()->json(['ok' => true, 'affected' => $n, 'principal' => $principal, 'warnings' => $errores]);

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

        // El CORREO es obligatorio: es por donde se responde al cliente. El teléfono es
        // opcional (dato extra). El nombre y el asunto, obligatorios.
        $request->validate([
            'name'    => ['required'],
            'email'   => ['required', 'email'],
            'phone'   => ['nullable'],
            'subject' => ['required'],
        ], [
            'name.required'    => 'El nombre es obligatorio',
            'subject.required' => 'El asunto es obligatorio',
            'email.required'   => 'El correo del cliente es obligatorio',
            'email.email'      => 'El email no es válido',
        ]);

        $name    = trim((string) $request->input('name'));
        $email   = trim((string) $request->input('email'));
        $phone   = preg_replace('/\D+/', '', (string) $request->input('phone'));
        $subject = trim((string) $request->input('subject'));

        // La descripción llega como HTML del editor: se SANEA por lista blanca.
        $body  = HtmlSanitizer::clean($request->input('description'));
        $plain = HtmlSanitizer::toText($body);
        $files = $request->file('files', []);

        // Cross-field que no es una regla simple: vale con TEXTO o ADJUNTOS (a veces una
        // captura lo dice todo).
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

        // Canal según el dato de contacto: si hay CORREO, el ticket es de canal 'email'
        // y el agente puede responderle por SMTP (reply() envía a contact_email). Si solo
        // hay teléfono, queda 'web' (interno, sin salida por correo).
        $channel = $email !== '' ? 'email' : 'web';

        $ticketId = $this->tickets->create([
            'contact_id'  => $contactId,
            'channel'     => $channel,
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
            'channel'    => $channel,
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
        // Prioridad más alta (configurable): la clave viene de la BD, se escapa igualmente.
        $top = DB::getPdo()->quote(TicketService::topPriorityKey() ?? 'urgente');

        $stats = DB::table('tickets')
            ->whereNotNull('assigned_to')
            ->groupBy('assigned_to')
            ->get([
                'assigned_to',
                DB::raw('COUNT(*) AS total'),
                DB::raw("SUM(status IN ($open)) AS open_n"),
                DB::raw("SUM(status IN ('resuelto','cerrado')) AS resolved_n"),
                DB::raw("SUM(priority = $top AND status IN ($open)) AS urgent_n"),
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
                    'active'       => (bool) $u->active,   // false = ya no está (ex-empleado / histórico)
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
