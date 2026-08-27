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
        {--limit=0 : Procesa solo N tickets (0=todos), para pruebas}';

    protected $description = 'Importa el histórico de Faveo (tickets de correo + contactos), separando los crones';

    private array $codeSeq = [];
    private array $agentIds = [];
    private array $agentMap = [];      // id de agente en Faveo => id del mismo agente HOY (match por email)
    private array $nameToAgent = [];   // nombre completo (lower) del agente Faveo => id HOY (para notas de sistema)
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

        if ($this->option('fresh') && $apply) {
            $ids = DB::table('tickets')->where('source', $source)->pluck('id');
            DB::table('cron_alerts')->whereIn('ticket_id', $ids)->delete();
            $n = DB::table('tickets')->where('source', $source)->delete();   // messages caen por FK
            $this->warn("Borrados $n tickets previos (source=$source).");
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
            if ($mail === 'jfajardo@aemegroup.com') $this->defaultAgente = (int) $u->id;   // el que Faveo asignaba por defecto
            if (isset($hoy[$mail])) {
                $this->agentMap[(int) $u->id] = $hoy[$mail];
                if ($nombre !== '') $this->nameToAgent[mb_strtolower($nombre)] = $hoy[$mail];
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

        // Tickets a mirar: todo menos Deleted (status 5) — es 98% papelera de crones.
        $q = $fav->table('tickets as t')
            ->join('users as u', 'u.id', '=', 't.user_id')
            ->where('t.status', '!=', 5)
            ->orderBy('t.id')
            ->select('t.*', 'u.email', 'u.first_name', 'u.last_name', 'u.user_name', 'u.phone_number', 'u.mobile', 'u.country_code');
        if ($limit > 0) $q->limit($limit);
        $tickets = $q->get();

        $this->info(($apply ? '🖊  APLICANDO' : '👀 DRY-RUN') . ' · ' . $tickets->count() . ' tickets a clasificar (Deleted excluidos)');

        $nReal = 0; $nCron = 0; $nBounce = 0; $nSinCorreo = 0;
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
        $this->info("REALES: $nReal · CRONES: $nCron · Rebotes: $nBounce · Sin correo válido: $nSinCorreo");
        if (!$apply) $this->warn('DRY-RUN: no se ha escrito nada. Añade --apply para importar. (Adjuntos: Bloque 2, aún no)');
        return self::SUCCESS;
    }

    /** Crea el ticket real + su hilo de mensajes. */
    private function crearReal($t, $threads, string $email, ?string $nombre, string $asunto, string $source, $catDefault): void
    {
        $contactId = ChatService::upsertContactByEmail($email, $nombre);
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

        $filas = [];
        foreach ($threads as $th) {
            $cuerpo = self::texto((string) $th->body);
            if ($cuerpo === '') continue;
            if ($th->is_internal && self::esNotaSistema($cuerpo)) continue;   // ruido de auditoría de Faveo
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
                'is_internal_note' => $th->is_internal ? 1 : 0,
                'status'           => 'sent',
                'wamid'            => 'fav:' . $th->id,   // enlace al hilo de Faveo (para colgar adjuntos, Bloque 2)
                'created_at'       => self::fecha($th->created_at) ?? $ini,
            ];
        }
        foreach (array_chunk($filas, 300) as $lote) DB::table('messages')->insert($lote);
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
