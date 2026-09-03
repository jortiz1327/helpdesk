<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ChatService;
use App\Services\CronAlertService;
use App\Services\HtmlSanitizer;
use App\Services\TicketService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Importa el histórico del helpdesk anterior (Faveo) como tickets del nuevo sistema.
 *
 * Lee de una BD `faveo_old` (donde se ha cargado el dump de Faveo) y escribe en el
 * helpdesk. Clasifica cada ticket:
 *   · CRON  → correos automáticos (noreply@…, «Cron Job Execution Log»…): van al
 *             apartado «Crones», AGRUPADOS por cron y CERRADOS (no son de cliente).
 *   · REAL  → correspondencia real de cliente: ticket de canal «email» con su hilo.
 *   · RUIDO → rebotes (MAILER-DAEMON) y papelera (Deleted): se descartan.
 *
 * Todo con source='import-faveo' (reversible con --fresh) y dry-run por defecto.
 *   php artisan faveo:import                 (previsualiza)
 *   php artisan faveo:import --apply          (importa)
 *   php artisan faveo:import --apply --fresh  (borra antes lo ya importado)
 */
class ImportFaveo extends Command
{
    protected $signature = 'faveo:import
        {--apply : Escribe (por defecto solo previsualiza)}
        {--db=faveo_old : BD con el dump de Faveo (si usas BD aparte)}
        {--prefix= : Prefijo de las tablas de Faveo si están en la MISMA BD del helpdesk (p. ej. fav_) — sin BD puente}
        {--source=import-faveo : Marca en tickets.source, para revertir}
        {--fresh : Borra antes lo ya importado de ese source}
        {--wipe : Borra TODOS los tickets y contactos actuales antes de importar (no solo lo del source)}
        {--extras : Importa también FAQs (kb_article) y respuestas predefinidas (canned_response), sin borrar las que ya hay}
        {--only-extras : Importa SOLO los extras (FAQs + respuestas), sin tocar tickets ni contactos}
        {--limit=0 : Procesa solo N tickets (0=todos), para pruebas}';

    protected $description = 'Importa el histórico de Faveo (tickets de correo + contactos), separando los crones';

    private array $codeSeq = [];
    private array $agentIds = [];
    private array $agentMap = [];          // id de agente en Faveo => id del mismo agente HOY (match por email)
    private array $nameToAgent = [];       // nombre completo (lower) del agente Faveo => id HOY (para eventos de sistema)
    private array $userNameToAgent = [];   // user_name (lower) del agente Faveo => id HOY («now belongs to aportales»)
    private array $agentEmails = [];       // correos de agente (lower) => true: NO se crean como contacto
    private ?int $internoContactId = null; // contacto único para tickets cuyo solicitante es un agente
    private ?int $defaultAgente = null; // agente al que Faveo asignaba POR DEFECTO (jfajardo): sus tickets se reatribuyen
    private array $topicToCat = [];
    private array $priMap = [1 => 'baja', 2 => 'media', 3 => 'alta', 4 => 'urgente'];

    public function handle(): int
    {
        $apply  = (bool) $this->option('apply');
        $source = (string) $this->option('source');
        $limit  = (int) $this->option('limit');

        // Conexión a los datos de Faveo. Dos formas:
        //  · --prefix=fav_  → las tablas de Faveo están en la MISMA BD del helpdesk con
        //    ese prefijo (sin BD puente): se lee `fav_tickets`, `fav_users`… de la propia BD.
        //  · --db=faveo_old → una BD aparte con el dump cargado.
        $prefix = (string) $this->option('prefix');
        $favCfg = config('database.connections.mysql');
        if ($prefix !== '') {
            $favCfg['prefix'] = $prefix;
            if (($db = (string) $this->option('db')) !== 'faveo_old') $favCfg['database'] = $db;
        } else {
            $favCfg['database'] = (string) $this->option('db');
        }
        config(['database.connections.faveo' => $favCfg]);
        $fav = DB::connection('faveo');
        try { $fav->getPdo(); } catch (\Throwable $e) {
            $this->error('No conecto con la BD «' . $this->option('db') . '»: ' . $e->getMessage());
            return self::FAILURE;
        }

        // SOLO extras: ni wipe, ni agentes, ni tickets. Útil para rehacer FAQs/respuestas.
        if ($this->option('only-extras')) {
            $this->importExtras($fav, $apply);
            return self::SUCCESS;
        }

        // BORRADO TOTAL: deja la BD limpia de tickets y contactos para cargar el histórico
        // desde cero. Los tickets caen en cascada (messages, ticket_events, attachments,
        // cron_alerts por FK). Los contactos se borran aparte (ya sin mensajes que los aten).
        if ($this->option('wipe') && $apply) {
            $nt = DB::table('tickets')->count();
            $nc = DB::table('contacts')->count();
            DB::table('tickets')->delete();          // cascada: messages, ticket_events, attachments, cron_alerts
            DB::table('contact_labels')->delete();   // no tiene FK a contacts: se limpia a mano
            DB::table('contacts')->delete();
            $this->warn("WIPE: borrados $nt tickets y $nc contactos (todo).");
        } elseif ($this->option('wipe')) {
            $this->warn('WIPE en DRY-RUN: se BORRARÍAN ' . DB::table('tickets')->count() . ' tickets y ' . DB::table('contacts')->count() . ' contactos (con --apply).');
        }

        if ($this->option('fresh') && $apply) {
            $ids = DB::table('tickets')->where('source', $source)->pluck('id');
            DB::table('cron_alerts')->whereIn('ticket_id', $ids)->delete();
            $n = DB::table('tickets')->where('source', $source)->delete();   // messages caen por FK
            $this->warn("Borrados $n tickets previos (source=$source).");
        }

        // SLA APAGADO por defecto tras traer el histórico: los tickets viejos (muchos
        // abiertos desde hace años) no deben contar como «SLA vencido» ni disparar avisos.
        // Se reactiva cuando el equipo quiera en Ajustes → Horario y SLA.
        if ($apply) {
            \App\Models\Setting::put('sla_active', '0');
            \App\Models\Setting::put('sla_alerts_active', '0');
            $this->warn('SLA DESACTIVADO por defecto (relojes y avisos). Reactívalo en Ajustes cuando estéis listos.');
        }

        // Mapas: agentes (para dirección), categorías (por nombre, portable), prioridades.
        $this->agentIds = $fav->table('users')->whereIn('role', ['agent', 'admin'])->pluck('id')->all();

        // Agentes de Faveo → usuarios del helpdesk (por email). Los que no existan se CREAN
        // (rol «agente», contraseña inutilizable: es histórico, no harán login) para atribuir
        // sus tickets y respuestas. Los tickets se les asignan.
        $hoy = [];
        foreach (DB::table('users')->get(['id', 'email']) as $u) $hoy[mb_strtolower(trim((string) $u->email))] = (int) $u->id;
        $this->agentMap = [];
        $existentes = 0; $creados = 0;
        foreach ($fav->table('users')->whereIn('role', ['agent', 'admin'])->get(['id', 'email', 'first_name', 'last_name', 'user_name']) as $u) {
            $mail = mb_strtolower(trim((string) $u->email));
            if ($mail === '' || !filter_var($mail, FILTER_VALIDATE_EMAIL)) continue;
            $nombre = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: ($u->user_name ?: $mail);
            $this->agentEmails[$mail] = true;                                    // no se creará como contacto
            $uname = mb_strtolower(trim((string) ($u->user_name ?? '')));
            if ($mail === 'jfajardo@aemegroup.com') $this->defaultAgente = (int) $u->id;   // el que Faveo asignaba por defecto
            if (isset($hoy[$mail])) {
                $this->agentMap[(int) $u->id] = $hoy[$mail];
                if ($nombre !== '') $this->nameToAgent[mb_strtolower($nombre)] = $hoy[$mail];
                if ($uname !== '') $this->userNameToAgent[$uname] = $hoy[$mail];
                $existentes++; continue;
            }
            if (!$apply) { $existentes++; continue; }                          // en dry-run no se crea
            $nuevo = new User();
            $nuevo->name = mb_substr($nombre, 0, 120);
            $nuevo->email = mb_substr($mail, 0, 150);
            $nuevo->password = Str::random(40);                                 // el cast 'hashed' lo cifra
            $nuevo->save();
            $nuevo->syncRoles(['agente']);
            $hoy[$mail] = (int) $nuevo->id;
            $this->agentMap[(int) $u->id] = (int) $nuevo->id;
            if ($nombre !== '') $this->nameToAgent[mb_strtolower($nombre)] = (int) $nuevo->id;
            if ($uname !== '') $this->userNameToAgent[$uname] = (int) $nuevo->id;
            $creados++;
        }
        $this->line("Agentes: $existentes ya existían · $creados creados (histórico, rol agente).");
        $catByName = DB::table('ticket_categories')->pluck('id', 'name');   // nombre => id
        $topicName = [
            1 => 'Soporte', 2 => 'Pedidos y facturas', 3 => 'Garantías',
            4 => 'Pedidos y facturas', 5 => 'Soporte', 6 => 'Soporte',
        ];
        foreach ($topicName as $tid => $name) $this->topicToCat[$tid] = $catByName[$name] ?? $catByName->first();
        $catDefault = $catByName['Soporte'] ?? $catByName->first();

        $cron = app(CronAlertService::class);

        // Tickets a mirar: TODOS, incluidos los Deleted (status 5). Los Deleted son papelera,
        // pero en su mayoría son CRONES: se escanean para llevar esos al apartado «Crones».
        // Un Deleted que NO es cron se descarta (papelera de verdad).
        $q = $fav->table('tickets as t')
            ->join('users as u', 'u.id', '=', 't.user_id')
            ->orderBy('t.id')
            ->select('t.*', 'u.email', 'u.first_name', 'u.last_name', 'u.user_name', 'u.phone_number', 'u.mobile', 'u.country_code');
        if ($limit > 0) $q->limit($limit);
        $tickets = $q->get();

        $this->info(($apply ? '🖊  APLICANDO' : '👀 DRY-RUN') . ' · ' . $tickets->count() . ' tickets a clasificar (Deleted excluidos)');

        $nReal = 0; $nCron = 0; $nBounce = 0; $nSinCorreo = 0; $nPapelera = 0;
        $cronGroups = []; $ejReal = []; $ejCron = [];

        foreach ($tickets as $t) {
            $email = trim((string) $t->email);
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $nSinCorreo++; continue; }

            // Hilos del ticket (ordenados). El 1º con título da el asunto.
            $threads = $fav->table('ticket_thread')->where('ticket_id', $t->id)->orderBy('id')->get();
            $asunto  = '';
            foreach ($threads as $th) { if (trim((string) $th->title) !== '') { $asunto = trim((string) $th->title); break; } }
            $primerCuerpo = self::texto((string) optional($threads->first())->body);

            // ---- Clasificación ----
            if (preg_match('/^MAILER-DAEMON@/i', $email) || preg_match('/^(Undelivered Mail|Returned mail|Mail delivery failed)/i', $asunto)) {
                $nBounce++; continue;                                   // rebote → descartar
            }
            if ($cron->esAviso($asunto, $primerCuerpo, $email)) {
                $nCron++;
                if (count($ejCron) < 8) $ejCron[] = mb_strimwidth($asunto, 0, 60, '…');
                if ($apply) $this->acumularCron($cronGroups, $cron, $t, $threads, $email, $asunto);
                continue;
            }

            // Deleted (status 5) que NO es cron: papelera de Faveo, se descarta.
            if ((int) $t->status === 5) { $nPapelera++; continue; }

            // ---- Ticket REAL de cliente ----
            $nReal++;
            $nombre = trim(($t->first_name ?? '') . ' ' . ($t->last_name ?? '')) ?: ($t->user_name ?: null);
            if (count($ejReal) < 20) $ejReal[] = [self::estadoTxt((int) $t->status), mb_strimwidth($asunto ?: '(sin asunto)', 0, 54, '…')];
            if ($apply) $this->crearReal($t, $threads, $email, $nombre, $asunto, $source, $catDefault);
        }

        // Crones: un ticket CERRADO por cron (agrupado), en el apartado «Crones», con
        // clave propia «faveo:…» para no mezclarse con la monitorización en vivo.
        if ($apply && $cronGroups) {
            $this->volcarCrones($cronGroups, $source);
            $this->line('Crones agrupados en ' . count($cronGroups) . ' tickets cerrados.');
        }

        // Extras opcionales: FAQs y respuestas predefinidas (no dependen de los tickets).
        if ($this->option('extras')) $this->importExtras($fav, $apply);

        // ---- Salida ----
        if ($ejReal) {
            $this->line("\n<info>Muestra de tickets REALES (estado · asunto):</info>");
            $this->table(['Estado', 'Asunto'], $ejReal);
        }
        if ($ejCron) {
            $this->line("\n<comment>Ejemplos clasificados como CRON (→ apartado Crones, cerrados):</comment>");
            foreach ($ejCron as $c) $this->line('  · ' . $c);
        }
        $this->line('');
        $this->info("REALES: $nReal · CRONES: $nCron · Papelera (deleted no-cron): $nPapelera · Rebotes: $nBounce · Sin correo válido: $nSinCorreo");
        if (!$apply) $this->warn('DRY-RUN: no se ha escrito nada. Añade --apply para importar. (Adjuntos: Bloque 2, aún no)');
        return self::SUCCESS;
    }

    /** Crea el ticket real + su hilo de mensajes + su HISTORIAL de cambios (ticket_events). */
    private function crearReal($t, $threads, string $email, ?string $nombre, string $asunto, string $source, $catDefault): void
    {
        // El solicitante NO se duplica si ya es un AGENTE: en ese caso el ticket cuelga de un
        // contacto interno único (nunca un contacto con el mismo correo que un agente).
        $contactId = isset($this->agentEmails[mb_strtolower(trim($email))])
            ? $this->contactoInterno()
            : ChatService::upsertContactByEmail($email, $nombre);
        $ini = self::fecha($t->created_at) ?? now()->toDateTimeString();
        $fin = self::fecha($t->last_message_at) ?? $ini;
        $estado = self::estadoTxt((int) $t->status);
        $cerrado = in_array($estado, ['resuelto', 'cerrado'], true);

        $ticketId = DB::table('tickets')->insertGetId([
            'code'            => $this->nextCode(date('ym', strtotime($ini))),
            'subject'         => mb_substr($asunto !== '' ? $asunto : 'Sin asunto', 0, 200),
            'category_id'     => $this->topicToCat[$t->help_topic_id] ?? $catDefault,
            'status'          => $estado,
            'priority'        => $this->priMap[$t->priority_id] ?? 'media',
            'channel'         => 'email',
            'source'          => $source,
            'contact_id'      => $contactId,
            'assigned_to'     => $this->resolverAgente($t, $threads),   // agente que lo llevó (reatribuye los default de Jesús)
            'opened_at'       => $ini,
            'first_response_at' => $ini,
            'resolved_at'     => $cerrado ? (self::fecha($t->closed_at) ?? $fin) : null,
            'closed_at'       => self::fecha($t->closed_at),
            'last_message_at' => $fin,
            'created_at'      => $ini,
            'updated_at'      => $fin,
        ]);

        // HISTORIAL: el ticket se creó al abrirse.
        DB::table('ticket_events')->insert([
            'ticket_id' => $ticketId, 'user_id' => null, 'type' => 'created',
            'from_value' => null, 'to_value' => null, 'note' => null, 'created_at' => $ini,
        ]);

        $curStatus = 'abierto';   // se va reconstruyendo con los avisos de sistema, en orden
        $curAssignee = null;
        $filas = [];
        foreach ($threads as $th) {
            // AVISO DE SISTEMA (interno + poster NULL): asignación, cierre, reapertura, fusión…
            // Va al HISTORIAL (ticket_events), NO como nota interna.
            if ($th->is_internal && ($th->poster === null || $th->poster === '')) {
                $this->eventoSistema($ticketId, $th, $curStatus, $curAssignee, $ini);
                continue;
            }
            $cuerpo = self::texto((string) $th->body);
            if ($cuerpo === '') continue;
            $esCliente = ($th->poster === 'client');
            $autor = $esCliente ? null : ($this->agentMap[(int) $th->user_id] ?? null);   // quién escribió, si sigue hoy
            $filas[] = [
                'ticket_id'        => $ticketId,
                'contact_id'       => $contactId,
                'author_user_id'   => $autor,
                'sent_by'          => $autor,
                'direction'        => $esCliente ? 'in' : 'out',
                'channel'          => 'email',
                'type'             => 'text',
                'body'             => self::cuerpoLimpio((string) $th->body),
                'is_html'          => 1,
                'is_internal_note' => $th->is_internal ? 1 : 0,   // notas REALES de agente (poster != null)
                'status'           => 'sent',
                'wamid'            => 'fav:' . $th->id,   // enlace al hilo de Faveo (para colgar adjuntos, Bloque 2)
                'created_at'       => self::fecha($th->created_at) ?? $ini,
            ];
        }
        foreach (array_chunk($filas, 300) as $lote) DB::table('messages')->insert($lote);
    }

    /** Contacto único para tickets cuyo solicitante era un agente (no se duplica el correo). */
    private function contactoInterno(): int
    {
        return $this->internoContactId ??= ChatService::upsertContactByEmail('equipo-interno@import.faveo', 'Equipo AEME (interno)');
    }

    /**
     * Convierte un aviso de SISTEMA de Faveo (hilo interno con poster NULL) en un evento del
     * HISTORIAL del ticket. Reconstruye estado y asignado en orden ($curStatus/$curAssignee).
     */
    private function eventoSistema(int $ticketId, $th, string &$curStatus, ?int &$curAssignee, string $ini): void
    {
        $txt  = self::texto((string) $th->body);
        $when = self::fecha($th->created_at) ?? $ini;
        $ev = function (string $type, ?string $from, ?string $to, ?int $uid = null, ?string $nota = null) use ($ticketId, $when) {
            DB::table('ticket_events')->insert([
                'ticket_id' => $ticketId, 'user_id' => $uid, 'type' => $type,
                'from_value' => $from, 'to_value' => $to,
                'note' => $nota !== null ? mb_substr($nota, 0, 300) : null, 'created_at' => $when,
            ]);
        };

        // Asignación: «(ha sido) asignado a X» / «(has been) assigned to X»
        if (preg_match('/(?:asignado a|assigned to)\s+(.+)$/iu', $txt, $m)) {
            if ($to = $this->agentePorNombre($m[1])) { $ev('assign', $curAssignee ? (string) $curAssignee : null, (string) $to, null); $curAssignee = $to; }
            return;
        }
        // «This ticket now belongs to <user_name>»
        if (preg_match('/now belongs to\s+(\S+)/i', $txt, $m)) {
            $to = $this->userNameToAgent[mb_strtolower(trim($m[1]))] ?? null;
            if ($to) { $ev('assign', $curAssignee ? (string) $curAssignee : null, (string) $to, null); $curAssignee = $to; }
            return;
        }
        // Estado: «Ticket have been Closed/Resolved/Reopened by X»
        if (preg_match('/been\s+(Closed|Resolved|Reopened)\s+by\s+(.+)$/iu', $txt, $m)) {
            $nuevo = ['closed' => 'cerrado', 'resolved' => 'resuelto', 'reopened' => 'abierto'][mb_strtolower($m[1])];
            $actor = $this->agentePorNombre($m[2]);
            if ($nuevo !== $curStatus) { $ev('status', $curStatus, $nuevo, $actor); $curStatus = $nuevo; }
            return;
        }
        // Fusión: «Ticket #CODE Se ha fusionado con este ticket. Motivo de la fusión: …»
        if (preg_match('/#([A-Z0-9\-]+).*?fusionado con este ticket\.?\s*(?:Motivo[^:]*:\s*(.*))?$/iu', $txt, $m)) {
            $ev('merge_in', $m[1], null, null, $m[2] ?? null);
            return;
        }
        // Otros avisos de sistema sin patrón conocido: se ignoran (no se pierden datos de cliente).
    }

    /** Resuelve un nombre de agente (de un aviso de sistema) al id de hoy. Tolera dobles espacios. */
    private function agentePorNombre(string $nombre): ?int
    {
        $n = mb_strtolower(trim(preg_replace('/\s+/', ' ', $nombre)));
        return $this->nameToAgent[$n] ?? null;
    }

    /**
     * EXTRAS: base de conocimiento (kb_article → FAQs del portal) y respuestas predefinidas
     * (canned_response → «/»). NO borra las que ya hay: solo añade las que no existan
     * (dedup por pregunta / por título). Las FAQs entran como publicadas si en Faveo lo estaban.
     */
    private function importExtras($fav, bool $apply): void
    {
        // --- FAQs (kb_article → faqs) ---
        // Se REEMPLAZAN: se borran las FAQs que hubiera y quedan SOLO las de Faveo (el
        // usuario no quiere que convivan las antiguas con las importadas). Las respuestas
        // predefinidas (más abajo) SÍ se añaden sin borrar (dedup).
        if ($apply) DB::table('faqs')->delete();
        $faqExist = [];   // tras el borrado no hay ninguna; solo se dedup entre las de Faveo
        $nFaq = 0;
        foreach ($fav->table('kb_article')->orderBy('id')->get(['name', 'description', 'status']) as $a) {
            $q = trim(self::texto((string) $a->name));
            if ($q === '' || isset($faqExist[mb_strtolower($q)])) continue;
            $faqExist[mb_strtolower($q)] = true; $nFaq++;
            if (!$apply) continue;
            DB::table('faqs')->insert([
                'question'   => mb_substr($q, 0, 200),
                // El campo answer se pinta como TEXTO (textarea + {answer}), no HTML: se
                // convierte el HTML de Faveo a texto plano legible (conservando saltos).
                'answer'     => mb_substr(self::htmlAtexto((string) $a->description) ?: $q, 0, 20000),
                'keywords'   => self::keywordsDe($q),
                'category_id' => null,
                'active'     => (int) $a->status === 1 ? 1 : 0,
                'position'   => 100 + $nFaq,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // --- Respuestas predefinidas (canned_response → canned_responses) ---
        $used     = array_flip(DB::table('canned_responses')->pluck('shortcut')->all());
        $titExist = array_flip(DB::table('canned_responses')->pluck('title')->map(fn ($t) => mb_strtolower(trim((string) $t)))->all());
        $nCan = 0;
        foreach ($fav->table('canned_response')->orderBy('id')->get(['title', 'message']) as $c) {
            $tit = trim(self::texto((string) $c->title));
            if ($tit === '' || isset($titExist[mb_strtolower($tit)])) continue;
            $titExist[mb_strtolower($tit)] = true; $nCan++;
            if (!$apply) continue;
            DB::table('canned_responses')->insert([
                'shortcut'   => $this->shortcutUnico($tit, $used),
                'title'      => mb_substr($tit, 0, 120),
                // El body se inserta con execCommand('insertText'): TEXTO plano, no HTML.
                'body'       => self::htmlAtexto((string) $c->message) ?: $tit,
                'position'   => 100 + $nCan, 'active' => 1, 'created_by' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->info("Extras: FAQs +$nFaq · Respuestas predefinidas +$nCan" . ($apply ? '' : ' (dry-run)'));
    }

    /**
     * HTML de Faveo → TEXTO plano legible. Para campos que se pintan/insertan como texto
     * (FAQ answer, canned body): quita la basura de Word/Outlook, convierte los bloques en
     * saltos de línea y decodifica entidades. Sin etiquetas, pero conservando párrafos.
     */
    private static function htmlAtexto(string $html): string
    {
        $html = preg_replace('/<!--.*?-->/s', ' ', $html) ?? $html;                       // comentarios [if mso]
        $html = preg_replace('#<(script|style|xml|head|title)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $html = preg_replace('/<\/?[owvm]:[^>]*>/i', '', $html) ?? $html;                  // etiquetas Office
        $html = preg_replace('#<(br|/p|/div|/li|/tr|/h[1-6])\b[^>]*>#i', "\n", $html) ?? $html;
        $t = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = str_replace("\xC2\xA0", ' ', $t);                                            // &nbsp; residual
        $t = preg_replace('/[ \t]+/', ' ', $t);                                           // espacios repetidos
        $t = preg_replace('/ *\n */', "\n", $t);                                          // limpia alrededor del salto
        $t = preg_replace('/\n{3,}/', "\n\n", $t);                                        // máx una línea en blanco
        return trim((string) $t);
    }

    /** Palabras clave para el buscador del portal, a partir de la pregunta (sin vacías/cortas). */
    private static function keywordsDe(string $q): ?string
    {
        $q = mb_strtolower(strip_tags($q));
        $q = preg_replace('/[¿?¡!.,;:()"\x27—–\-]/u', ' ', $q);
        $stop = ['el', 'la', 'los', 'las', 'un', 'una', 'de', 'del', 'que', 'como', 'cómo', 'en', 'no', 'si', 'sí', 'al', 'por', 'para', 'con', 'una', 'qué', 'han', 'hay', 'está', 'esta', 'este'];
        $words = array_values(array_unique(array_filter(
            preg_split('/\s+/', trim($q)),
            fn ($w) => mb_strlen($w) >= 4 && !in_array($w, $stop, true)
        )));
        return $words ? mb_substr(implode(', ', array_slice($words, 0, 8)), 0, 500) : null;
    }

    /** Atajo «/» único a partir del título (slug, ≤36, sin choques). */
    private function shortcutUnico(string $title, array &$used): string
    {
        $base = mb_substr(Str::slug($title, '-') ?: 'resp', 0, 36);
        $s = $base; $i = 1;
        while (isset($used[$s])) $s = mb_substr($base, 0, 33) . '-' . (++$i);
        $used[$s] = true;
        return $s;
    }

    /** Acumula una ejecución de cron en su grupo (por nombre+params). No escribe aún. */
    private function acumularCron(array &$groups, CronAlertService $cron, $t, $threads, string $email, string $asunto): void
    {
        $body0 = (string) optional($threads->first())->body;
        $datos = $cron->parse(self::texto($body0)) ?: [];
        $name   = $datos['cron_name'] ?? self::cronNombreDeAsunto($asunto);
        $params = (string) ($datos['params'] ?? '');
        $key    = 'faveo:' . mb_substr(mb_strtolower($name . '|' . $params), 0, 150);
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'name' => $name, 'params' => $params,
                'command' => $datos['command'] ?? null, 'expression' => $datos['expression'] ?? null,
                'email' => $email, 'execs' => [],
            ];
        }
        $groups[$key]['execs'][] = [
            'date'   => self::fecha($t->created_at),
            'body'   => self::cuerpoLimpio($body0),
            'exit'   => $datos['exit_code'] ?? null,
            'reason' => $datos['reason'] ?? null,
            'output' => isset($datos['output']) ? mb_substr((string) $datos['output'], 0, 500) : null,
        ];
    }

    /** Crea un ticket CERRADO por grupo de cron (+ fila cron_alerts + un mensaje por ejecución). */
    private function volcarCrones(array $groups, string $source): void
    {
        $contactId = ChatService::upsertContactByEmail('cron@faveo.import', 'Sistema (histórico Faveo)');
        foreach ($groups as $key => $g) {
            $fechas = array_values(array_filter(array_column($g['execs'], 'date')));
            $first  = $fechas ? min($fechas) : now()->toDateTimeString();
            $last   = $fechas ? max($fechas) : $first;

            $ticketId = DB::table('tickets')->insertGetId([
                'code'            => $this->nextCode(date('ym', strtotime($first))),
                'subject'         => mb_substr($g['name'] . ($g['params'] ? ' · ' . $g['params'] : ''), 0, 200),
                'status'          => 'cerrado',
                'priority'        => 'media',
                'channel'         => 'cron',
                'source'          => $source,
                'contact_id'      => $contactId,
                'opened_at'       => $first,
                'closed_at'       => $last,
                'last_message_at' => $last,
                'created_at'      => $first,
                'updated_at'      => $last,
            ]);

            $ult = end($g['execs']) ?: [];
            DB::table('cron_alerts')->insert([
                'ticket_id'      => $ticketId,
                'cron_key'       => mb_substr($key, 0, 191),
                'cron_name'      => mb_substr($g['name'], 0, 190),
                'params'         => $g['params'] !== '' ? mb_substr($g['params'], 0, 190) : null,
                'expression'     => $g['expression'],
                'command'        => $g['command'],
                'fails'          => count($g['execs']),
                'first_at'       => $first,
                'last_at'        => $last,
                'last_exit_code' => $ult['exit'] ?? null,
                'last_reason'    => isset($ult['reason']) ? mb_substr((string) $ult['reason'], 0, 190) : null,
                'last_output'    => $ult['output'] ?? null,
                'created_at'     => $first,
                'updated_at'     => $last,
            ]);

            $filas = [];
            foreach ($g['execs'] as $e) {
                $filas[] = [
                    'ticket_id'        => $ticketId,
                    'contact_id'       => $contactId,
                    'author_user_id'   => null,
                    'direction'        => 'in',
                    'channel'          => 'cron',
                    'type'             => 'text',
                    'body'             => $e['body'],
                    'is_html'          => 1,
                    'is_internal_note' => 0,
                    'status'           => 'received',
                    'created_at'       => $e['date'] ?? $first,
                ];
            }
            foreach (array_chunk($filas, 300) as $lote) DB::table('messages')->insert($lote);
        }
    }

    /**
     * A quién se asigna el ticket. Normalmente el asignado de Faveo (mapeado). PERO Faveo
     * asignaba TODO por defecto a Jesús: si el asignado es él y NO hizo nada (ni respondió
     * ni figura en una nota de acción), se lo damos a quien de verdad actuó (último que
     * respondió, o el actor de la última nota de sistema «… by X»).
     */
    private function resolverAgente($t, $threads): ?int
    {
        $faveoAssignee = (int) $t->assigned_to;
        $mapped = $this->agentMap[$faveoAssignee] ?? null;

        $ultimoReply = null; $ultimaNota = null; $actores = [];
        foreach ($threads as $th) {
            $poster = (string) $th->poster;
            if ($poster !== '' && $poster !== 'client') {                       // respuesta de agente
                if ($a = ($this->agentMap[(int) $th->user_id] ?? null)) { $ultimoReply = $a; $actores[$a] = true; }
            }
            if ($th->is_internal) {                                             // nota de sistema: «… by Nombre»
                $txt = self::texto((string) $th->body);
                if (preg_match('/\b(?:by|assigned to)\s+([\p{L} .\'\-]{3,60})\s*$/iu', $txt, $m)) {
                    if ($a = ($this->nameToAgent[mb_strtolower(trim($m[1]))] ?? null)) { $ultimaNota = $a; $actores[$a] = true; }
                }
            }
        }

        // Solo reatribuimos el DEFAULT (Jesús) cuando él no consta como actor y hay otro que sí.
        if ($mapped !== null && $this->defaultAgente !== null && $faveoAssignee === $this->defaultAgente && !isset($actores[$mapped])) {
            if ($real = ($ultimoReply ?? $ultimaNota)) return $real;
        }
        return $mapped;
    }

    /** Nombre del cron a partir del asunto, si parse() no lo saca. */
    private static function cronNombreDeAsunto(string $asunto): string
    {
        $s = preg_replace('/^(Cron Job Execution Log|Registro de ejecuci[oó]n de tarea programada)\s*\|\s*/iu', '', $asunto);
        return mb_substr(trim((string) $s) ?: $asunto, 0, 190);
    }

    // ---- helpers ----
    private static function texto(string $html): string
    {
        $t = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/', ' ', $t));
    }

    /**
     * HTML del correo listo para guardar: quita la basura de Outlook/Word (comentarios
     * condicionales «[if mso]», bloques <xml>/<style>, etiquetas <w:>/<o:>/<v:>/<m:>),
     * pasa por cleanEmail y CAP de seguridad para no reventar la columna TEXT (65 KB).
     */
    private static function cuerpoLimpio(string $html): string
    {
        $html = preg_replace('/<!--.*?-->/s', '', $html);                       // comentarios (incl. [if mso])
        $html = preg_replace('/<(style|script|xml|head)\b[^>]*>.*?<\/\1>/is', '', $html);
        $html = preg_replace('/<\/?[owvm]:[^>]*>/i', '', $html);                // etiquetas Office sueltas
        $clean = HtmlSanitizer::cleanEmail($html);
        if (mb_strlen($clean) > 60000) $clean = mb_substr($clean, 0, 60000) . '… <em>[mensaje recortado en la importación]</em>';
        return $clean;
    }

    private static function esNotaSistema(string $texto): bool
    {
        return (bool) preg_match('/^(This\s+)?Ticket\s+(has|have)\s+been\s+(Closed|Resolved|Reopened|Archived|Deleted|assigned)/i', $texto)
            || (bool) preg_match('/has\s+been\s+assigned\s+to/i', $texto);
    }

    private static function estadoTxt(int $status): string
    {
        return match ($status) {
            1 => 'abierto',
            2 => 'resuelto',
            3, 4 => 'cerrado',
            default => 'cerrado',
        };
    }

    private static function fecha($v): ?string
    {
        if (!$v || $v === '0000-00-00 00:00:00') return null;
        try { return Carbon::parse($v)->toDateTimeString(); } catch (\Throwable $e) { return null; }
    }

    private function nextCode(string $ym): string
    {
        if (!isset($this->codeSeq[$ym])) {
            $prefix = 'TK-' . $ym . '-';
            $last = DB::table('tickets')->where('code', 'like', $prefix . '%')->orderByDesc('code')->value('code');
            $this->codeSeq[$ym] = $last ? (int) substr($last, -4) : 0;
        }
        $this->codeSeq[$ym]++;
        return 'TK-' . $ym . '-' . str_pad((string) $this->codeSeq[$ym], 4, '0', STR_PAD_LEFT);
    }
}
