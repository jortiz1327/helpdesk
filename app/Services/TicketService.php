<?php

namespace App\Services;

use App\Events\TicketActivity;
use App\Models\Setting;
use App\Models\TicketPriority;
use Illuminate\Support\Facades\DB;

/**
 * Lógica central del ticket: creación, código legible, router de mensajes
 * entrantes, cambios de estado y auditoría.
 */
class TicketService
{
    /** Los 6 estados del ciclo de vida (en orden), con su etiqueta visible. */
    public const STATUSES = [
        'nuevo'               => 'Nuevo',
        'abierto'             => 'Abierto',
        'en_progreso'         => 'En progreso',
        'esperando_respuesta' => 'Esperando respuesta',
        'resuelto'            => 'Resuelto',
        'cerrado'             => 'Cerrado',
    ];

    /**
     * Color de cada estado (fuente ÚNICA). El chip lo pinta en línea desde aquí, en vez
     * de depender del nombre de clase CSS —así un estado sin `.chip.clave` no se queda
     * sin color, igual que ya hacen las prioridades—. El día que los estados sean una
     * tabla configurable, esto pasa a la BD sin tocar el frontend.
     */
    public const STATUS_COLORS = [
        'nuevo'               => '#2563eb',
        'abierto'             => '#10b981',
        'en_progreso'         => '#f59e0b',
        'esperando_respuesta' => '#f97316',
        'resuelto'            => '#8b5cf6',
        'cerrado'             => '#94a3b8',
    ];

    /** Estados con etiqueta + color, para el frontend (como priority_meta). */
    public static function statusMeta(): array
    {
        $out = [];
        foreach (self::STATUSES as $key => $name) {
            $out[$key] = ['name' => $name, 'color' => self::STATUS_COLORS[$key] ?? '#64748b'];
        }
        return $out;
    }

    /** Estados en los que un ticket sigue VIVO (y por tanto admite mensajes nuevos). */
    public const OPEN_STATUSES = ['nuevo', 'abierto', 'en_progreso', 'esperando_respuesta'];

    /**
     * Estados en los que el RELOJ DEL SLA se para: la pelota no está en nuestro
     * tejado. Un ticket esperando al cliente tres días no es un incumplimiento
     * nuestro, y contarlo como tal vuelve inservible la vista de vencidos.
     */
    public const SLA_PAUSED_STATUSES = ['esperando_respuesta', 'resuelto', 'cerrado'];

    /**
     * Prioridades: ya NO son una lista fija, se configuran en «Configuración de
     * soporte» (tabla ticket_priorities). Devuelve clave => etiqueta, como antes,
     * para que todo lo que las pintaba siga funcionando igual.
     */
    public static function priorities(): array
    {
        return array_map(fn ($p) => $p['name'], TicketPriority::activas());
    }

    /** Igual que priorities() pero con el color de cada una (para pintar las etiquetas). */
    public static function prioritiesMeta(): array
    {
        return TicketPriority::activas();
    }

    /** Estado con el que nace un ticket (configurable en Ajustes de tickets). */
    public static function defaultStatus(): string
    {
        $s = (string) Setting::get('ticket_default_status', 'nuevo');
        return array_key_exists($s, self::STATUSES) ? $s : 'nuevo';
    }

    /**
     * Genera la referencia visible: TK-AAMM-NNNN (secuencial dentro del mes).
     * Se calcula dentro de una transacción con bloqueo para evitar duplicados
     * si entran dos mensajes a la vez.
     */
    public function nextCode(): string
    {
        $prefix = 'TK-' . date('ym') . '-';

        $last = DB::table('tickets')
            ->where('code', 'like', $prefix . '%')
            ->lockForUpdate()
            ->orderByDesc('code')
            ->value('code');

        $n = $last ? ((int) substr($last, -4)) + 1 : 1;

        return $prefix . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Inserta el ticket reservando su `code` de forma ATÓMICA. Dos altas a la vez
     * (portal + correo) calcularían el mismo secuencial y una chocaría con el
     * unique(code), perdiéndose. Se cubren los DOS modos de fallo de la concurrencia:
     *   1) TRANSACCIÓN con reintentos (2º arg de DB::transaction): dentro de ella el
     *      lockForUpdate de nextCode() serializa, y si el bloqueo de rango provoca un
     *      DEADLOCK de InnoDB (SQLSTATE 40001), Laravel reintenta la transacción sola.
     *   2) REINTENTO propio ante COLISIÓN de code (SQLSTATE 23000 = unique): recalcula
     *      el número y vuelve a intentar. Red de seguridad final.
     * Solo cubre la reserva del código + el INSERT: los correos y avisos quedan FUERA
     * a propósito (nunca se envía nada dentro de una transacción).
     */
    protected function insertarConCodigo(array $fila): int
    {
        for ($intento = 1; ; $intento++) {
            try {
                return DB::transaction(fn () => DB::table('tickets')
                    ->insertGetId(['code' => $this->nextCode()] + $fila), 5);
            } catch (\Illuminate\Database\QueryException $e) {
                if ($intento >= 3 || $e->getCode() !== '23000') throw $e;
                usleep(50_000 * $intento);   // pequeña espera creciente antes de reintentar
            }
        }
    }

    /**
     * EL ROUTER (decisión núcleo del sistema).
     *
     * Llega un mensaje de un contacto por un canal:
     *   ¿tiene un ticket ABIERTO en ese canal?  → se añade a ese ticket
     *   si no                                   → se crea uno nuevo
     *
     * Devuelve el id del ticket al que pertenece el mensaje.
     */
    public function routeIncoming(int $contactId, string $channel, string $preview = ''): int
    {
        // WhatsApp: un contacto = UN ticket (todo el chat junto). Tiene su propia ruta.
        if ($channel === 'whatsapp') {
            return $this->routeWhatsapp($contactId, $preview);
        }

        return DB::transaction(function () use ($contactId, $channel, $preview) {
            $open = DB::table('tickets')
                ->where('contact_id', $contactId)
                ->where('channel', $channel)
                ->whereIn('status', self::OPEN_STATUSES)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($open) {
                DB::table('tickets')->where('id', $open->id)->update(['last_message_at' => now()]);
                return (int) $open->id;
            }

            return $this->create([
                'contact_id' => $contactId,
                'channel'    => $channel,
                'subject'    => $this->subjectFrom($preview),
                'body'       => $preview,   // contexto para las reglas automáticas
            ]);
        });
    }

    /**
     * WhatsApp: el mensaje entrante se pega SIEMPRE al mismo ticket del contacto.
     *
     *  · No tiene ninguno              → se crea (conversación reciente = ahora).
     *  · Tiene uno resuelto/cerrado    → se REABRE y empieza conversación reciente.
     *  · Tiene uno abierto pero llevaba
     *    mucho callado (> N días)      → mismo ticket, pero se marca una
     *                                     conversación reciente nueva (separador).
     *  · Tiene uno abierto y activo    → mismo ticket, misma conversación.
     */
    private function routeWhatsapp(int $contactId, string $preview): int
    {
        return DB::transaction(function () use ($contactId, $preview) {
            $t = DB::table('tickets')
                ->where('contact_id', $contactId)
                ->where('channel', 'whatsapp')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first(['id', 'status', 'last_message_at', 'subject_pending']);

            $conEnjundia = !$this->esSaludo($preview);   // ¿sirve como asunto?

            if (!$t) {
                $id = $this->create([
                    'contact_id'         => $contactId,
                    'channel'            => 'whatsapp',
                    'subject'            => $this->subjectFrom($preview),
                    'body'               => $preview,
                    'conversation_since' => now(),
                ]);
                // Si abrió con un saludo, el asunto real llega en el próximo mensaje.
                if (!$conEnjundia) DB::table('tickets')->where('id', $id)->update(['subject_pending' => 1]);
                return $id;
            }

            // ¿Empieza una conversación NUEVA? Si estaba cerrado/resuelto, o si llevaba
            // más de N días sin actividad (hueco = nuevo asunto, aunque no se cerrara).
            $cerrado    = in_array($t->status, ['resuelto', 'cerrado'], true);
            $gap        = max(1, (int) Setting::get('wa_nueva_conversacion_dias', '7'));
            $silencio   = $t->last_message_at && now()->diffInDays($t->last_message_at) >= $gap;
            $nuevaRacha = $cerrado || $silencio;

            // Reabrir limpiamente (borra resolved/closed, rehace SLA, registra evento).
            if ($cerrado) {
                $this->setStatus((int) $t->id, self::defaultStatus(), null);
            }

            $upd = ['last_message_at' => now()];
            if ($nuevaRacha) {
                $upd['conversation_since'] = now();
                // El asunto pasa a reflejar la conversación NUEVA. Si es un saludo,
                // se deja pendiente y lo rellenará el próximo mensaje con enjundia.
                if ($conEnjundia) { $upd['subject'] = $this->subjectFrom($preview); $upd['subject_pending'] = 0; }
                else              { $upd['subject_pending'] = 1; }
            } elseif ($t->subject_pending && $conEnjundia) {
                // Seguimos en la conversación recién abierta y por fin llega el tema real.
                $upd['subject'] = $this->subjectFrom($preview);
                $upd['subject_pending'] = 0;
            }
            DB::table('tickets')->where('id', $t->id)->update($upd);

            return (int) $t->id;
        });
    }

    /** Id del ticket abierto de un contacto en un canal, o null si no tiene ninguno. */
    public function openTicketId(int $contactId, string $channel = 'whatsapp'): ?int
    {
        $id = DB::table('tickets')
            ->where('contact_id', $contactId)
            ->where('channel', $channel)
            ->whereIn('status', self::OPEN_STATUSES)
            ->orderByDesc('id')
            ->value('id');

        return $id ? (int) $id : null;
    }

    /** Crea un ticket y deja constancia en el historial. */
    public function create(array $data): int
    {
        $id = $this->insertarConCodigo([
            'subject'         => mb_substr(trim($data['subject'] ?? '') ?: 'Sin asunto', 0, 200),
            'category_id'     => $data['category_id'] ?? null,
            'status'          => $data['status'] ?? self::defaultStatus(),
            'priority'        => $data['priority'] ?? TicketPriority::porDefecto(),
            'channel'         => $data['channel'] ?? 'whatsapp',
            'source'          => $data['source'] ?? null,
            'contact_id'      => $data['contact_id'],
            'assigned_to'     => $data['assigned_to'] ?? null,
            'opened_at'       => now(),
            'last_message_at' => now(),
            'conversation_since' => $data['conversation_since'] ?? null,
        ]);

        $this->event($id, 'created', null, $data['channel'] ?? 'whatsapp', $data['user_id'] ?? null);

        /*
         * Los AVISOS DE CRON se quedan aquí: no son un ticket de cliente. Nada de
         * reglas, ni reparto por turno, ni acuse de recibo —le contestaría a un
         * `noreply@`—. Nacen sin asignar a propósito, tal como se pidió.
         */
        if (($data['channel'] ?? '') === 'cron') {
            $this->broadcast('created', $id);
            return $id;
        }

        /*
         * Reglas automáticas ANTES de avisar: así el ticket ya sale asignado y
         * categorizado, y el aviso al cliente refleja el estado final.
         * `body` y `email` solo se usan aquí (no son columnas del ticket).
         */
        app(TicketRuleEngine::class)->apply($id, [
            'subject' => (string) ($data['subject'] ?? ''),
            'body'    => (string) ($data['body'] ?? ''),
            'email'   => (string) ($data['email'] ?? ''),
            'channel' => (string) ($data['channel'] ?? 'whatsapp'),
        ]);

        /*
         * TURNO: reparte al agente de guardia. Va DESPUÉS de las reglas a propósito
         * —lo específico manda sobre lo general— y solo actúa si el ticket sigue sin
         * responsable y su categoría se reparte por turno.
         */
        app(ShiftService::class)->asignarSiProcede($id);

        // Las reglas pueden haberle puesto categoría, y con ella su plazo.
        $this->recalcularSla($id);

        $this->broadcast('created', $id);
        app(NotifyService::class)->ticket('ticket_created', $id);   // acuse de recibo (si está activo)

        return $id;
    }

    /**
     * Cambia la categoría del ticket y lo registra. La categoría trae su propio SLA
     * (plazos de respuesta/resolución), así que se recalcula el vencimiento.
     */
    public function setCategory(int $ticketId, ?int $categoryId, ?int $userId = null): void
    {
        $cur = DB::table('tickets')->where('id', $ticketId)->value('category_id');
        $cur = $cur ? (int) $cur : null;
        if ($cur === $categoryId) return;

        DB::table('tickets')->where('id', $ticketId)->update(['category_id' => $categoryId]);
        $this->recalcularSla($ticketId);
        $this->event($ticketId, 'category', $cur ? (string) $cur : null, $categoryId ? (string) $categoryId : null, $userId);
        $this->broadcast('category', $ticketId);
    }

    /**
     * Cambia el estado y lo registra. Rellena resolved_at / closed_at.
     * Devuelve false si no había cambio real.
     */
    public function setStatus(int $ticketId, string $status, ?int $userId = null, bool $notify = true): bool
    {
        $t = DB::table('tickets')->where('id', $ticketId)
            ->first(['status', 'sla_paused_minutes', 'sla_paused_since']);
        $cur = $t->status ?? null;
        if (!$cur || $cur === $status) return false;

        $upd = ['status' => $status];
        if ($status === 'resuelto') $upd['resolved_at'] = now();
        if ($status === 'cerrado')  $upd['closed_at'] = now();

        /*
         * Si un ticket resuelto o cerrado se REABRE, hay que borrar la marca de
         * cumplimiento: si no, el reloj de resolución se quedaría dado por bueno
         * para siempre y el ticket reabierto nunca podría volver a vencer.
         */
        if (in_array($cur, ['resuelto', 'cerrado'], true) && in_array($status, self::OPEN_STATUSES, true)) {
            $upd['resolved_at'] = null;
            $upd['closed_at']   = null;
            // Y se rearman los avisos de SLA: un ticket reabierto puede volver a vencer.
            $upd['sla_warned_at']   = null;
            $upd['sla_breached_at'] = null;
        }

        $upd += $this->pausaSla($t, $status);

        DB::table('tickets')->where('id', $ticketId)->update($upd);

        // La pausa y la reapertura mueven el vencimiento: hay que rehacerlo.
        $this->recalcularSla($ticketId);

        $this->event($ticketId, 'status', $cur, $status, $userId);
        $this->broadcast('status', $ticketId);

        // Al resolver o cerrar se avisa al cliente (si la plantilla está activa).
        // $notify=false lo silencia: lo usa el cierre AUTOMÁTICO, que tiene su propio ajuste.
        if ($notify && in_array($status, ['resuelto', 'cerrado'], true)) {
            app(NotifyService::class)->ticket('ticket_closed', $ticketId);
            // Encuesta de satisfacción por correo (si está activa y la plantilla también).
            app(NotifyService::class)->csat($ticketId);
        }

        return true;
    }

    /**
     * Recalcula y GUARDA las fechas de vencimiento del SLA de un ticket.
     *
     * Hace falta guardarlas para poder filtrar y contar los vencidos: calcularlas al
     * vuelo sirve para pintar un ticket, no para preguntarle a la base de datos
     * «¿cuántos van fuera de plazo?».
     *
     * Se llama al crear, al cambiar de categoría y en cada entrada o salida de pausa,
     * porque la pausa corre el vencimiento.
     */
    public function recalcularSla(int $ticketId): void
    {
        try {
            $t = DB::table('tickets as t')->leftJoin('ticket_categories as c', 'c.id', '=', 't.category_id')
                ->leftJoin('ticket_priorities as p', 'p.key', '=', 't.priority')
                ->where('t.id', $ticketId)
                ->first([
                    't.opened_at', 't.created_at', 't.first_response_at', 't.resolved_at', 't.closed_at',
                    't.sla_paused_minutes', 't.sla_paused_since',
                    'c.sla_response_hours', 'c.sla_resolve_hours',
                    'p.sla_response_mins as pri_response_mins', 'p.sla_resolve_mins as pri_resolve_mins',
                ]);
            if (!$t) return;

            $sla = app(SlaService::class)->forTicket($t);

            /*
             * A formato de la base de datos: `due` viene en ISO con zona horaria
             * («…+02:00») y la columna es un timestamp, que lo rechaza.
             */
            $fecha = fn ($iso) => $iso ? \Illuminate\Support\Carbon::parse($iso)->format('Y-m-d H:i:s') : null;

            DB::table('tickets')->where('id', $ticketId)->update([
                'sla_response_due_at' => $fecha($sla['response']['due'] ?? null),
                'sla_resolve_due_at'  => $fecha($sla['resolve']['due'] ?? null),
            ]);
        } catch (\Throwable $e) {
            // Un fallo aquí no puede impedir que el ticket se guarde o cambie de estado.
            report($e);
        }
    }

    /**
     * Arranca o detiene la pausa del SLA al cambiar de estado.
     *
     * Al ENTRAR en un estado en pausa se apunta desde cuándo; al SALIR se suma lo
     * que ha durado —en minutos LABORABLES, no naturales: si el ticket estuvo
     * esperando toda la noche, esa noche no contaba de todas formas—.
     * Devuelve los campos a actualizar (vacío si no hay nada que tocar).
     */
    protected function pausaSla(object $t, string $nuevo): array
    {
        $estaba = $t->sla_paused_since !== null;
        $estara = in_array($nuevo, self::SLA_PAUSED_STATUSES, true);

        if ($estara === $estaba) return [];   // sigue igual: nada que hacer

        if ($estara) return ['sla_paused_since' => now()];

        $desde = \Illuminate\Support\Carbon::parse($t->sla_paused_since);
        $mins  = app(BusinessHoursService::class)->minutosEntre($desde, now());

        return [
            'sla_paused_minutes' => (int) $t->sla_paused_minutes + max(0, $mins),
            'sla_paused_since'   => null,
        ];
    }

    /** Asigna el ticket a un usuario de soporte (o lo deja sin asignar con null). */
    public function assign(int $ticketId, ?int $assignee, ?int $userId = null): void
    {
        $cur = DB::table('tickets')->where('id', $ticketId)->value('assigned_to');
        if ((int) $cur === (int) $assignee) return;

        DB::table('tickets')->where('id', $ticketId)->update(['assigned_to' => $assignee]);
        $this->event($ticketId, 'assign', $cur ? (string) $cur : null, $assignee ? (string) $assignee : null, $userId);
        $this->broadcast('assigned', $ticketId, $assignee);

        // Solo cuando se asigna a alguien (al desasignar no hay a quién avisar).
        if ($assignee) {
            app(NotifyService::class)->ticket('ticket_assigned', $ticketId);   // correo
            // Aviso in-app al nuevo responsable (push se salta si se autoasignó).
            $inf    = DB::table('tickets')->where('id', $ticketId)->first(['code', 'subject']);
            $quien  = $userId ? DB::table('users')->where('id', $userId)->value('name') : null;
            $cuerpo = ($quien ? "{$quien} te asignó" : 'Te han asignado') . " el ticket {$inf->code}"
                . ($inf->subject ? ": «" . mb_substr($inf->subject, 0, 120) . "»" : '');
            app(NotificationService::class)->push((int) $assignee, 'assigned', $cuerpo, $ticketId, $userId);
        }
    }

    /**
     * ESCALADO al vencer el SLA. Lo llama el cron `sla:check` UNA sola vez por ticket
     * (protegido por `sla_breached_at`). Si el escalado está activo:
     *   1) sube la prioridad a la de escalado (por defecto la ACTIVA más alta),
     *   2) reasigna al agente de GUARDIA del turno,
     *   3) deja una nota interna de auditoría.
     * Devuelve un resumen de lo que cambió (para registro). Nunca lanza: un fallo aquí
     * no debe cortar el barrido del cron ni el aviso por correo.
     */
    public function escalarPorSla(int $ticketId, string $reloj): array
    {
        try {
            if ((string) Setting::get('sla_escalate_active', '0') !== '1') return [];

            $t = DB::table('tickets')->where('id', $ticketId)->first(['priority', 'assigned_to']);
            if (!$t) return [];

            $cambios = [];

            // 1) Subir prioridad, solo si el destino es MÁS urgente que la actual.
            if ($destino = $this->prioridadEscalado()) {
                $posActual = (int) DB::table('ticket_priorities')->where('key', $t->priority)->value('position');
                if ($destino['position'] > $posActual) {
                    DB::table('tickets')->where('id', $ticketId)->update(['priority' => $destino['key']]);
                    $this->event($ticketId, 'priority', (string) $t->priority, (string) $destino['key'], null, 'Escalado automático por SLA vencido');
                    $cambios['prioridad'] = $destino['name'];
                }
            }

            // 2) Reasignar al agente de guardia (si hay turno cubierto y no es ya el asignado).
            $guardia = app(ShiftService::class)->deGuardia();
            if ($guardia && (int) $guardia['user_id'] !== (int) $t->assigned_to) {
                $this->assign($ticketId, (int) $guardia['user_id']);   // registra evento + aviso al nuevo
                $cambios['asignado'] = $guardia['name'];
            }

            // 3) Nota interna de auditoría (y refresco en la ficha).
            if ($cambios) {
                $partes = [];
                if (isset($cambios['prioridad'])) $partes[] = 'prioridad → <b>' . e($cambios['prioridad']) . '</b>';
                if (isset($cambios['asignado']))  $partes[] = 'reasignado a <b>' . e($cambios['asignado']) . '</b>';
                $this->nota($ticketId, null, '⏱️ <b>Escalado automático por SLA vencido</b> (' . e($reloj) . '): ' . implode(', ', $partes) . '.');
                $this->broadcast('message', $ticketId);
            }

            return $cambios;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Escalado SLA falló', ['ticket' => $ticketId, 'error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Prioridad de destino del escalado: la configurada en `sla_escalate_priority` o,
     * si no hay o no vale, la ACTIVA más alta (mayor `position`). Devuelve
     * ['key','name','position'] o null si no hay prioridades.
     */
    protected function prioridadEscalado(): ?array
    {
        $key  = trim((string) Setting::get('sla_escalate_priority', ''));
        $base = DB::table('ticket_priorities')->where('active', 1);
        $p = $key ? (clone $base)->where('key', $key)->first(['key', 'name', 'position']) : null;
        $p ??= $base->orderByDesc('position')->first(['key', 'name', 'position']);
        return $p ? (array) $p : null;
    }

    /** Cuánto se deja de intentar el aviso tras un fallo (segundos). */
    protected const SOCKET_PAUSA = 60;

    /**
     * Avisa por websocket de que este ticket se ha movido.
     *
     * Nunca debe romper NI FRENAR la operación: si el socket está caído, el ticket se
     * guarda igual y el cliente se entera en el siguiente refresco. Por eso, además
     * de tragarse el error, si falla se deja de intentar durante un minuto: sin ese
     * cortacircuitos, cada acción volvía a esperar a que la conexión expirase y una
     * acción en lote se convertía en una eternidad.
     */
    public function broadcast(string $action, int $ticketId, ?int $assignedTo = null): void
    {
        if (cache()->get('socket_caido')) return;   // falló hace nada: no insistir

        try {
            $t = DB::table('tickets')->where('id', $ticketId)->first(['code', 'subject', 'assigned_to', 'channel']);
            if (!$t) return;

            /*
             * Los avisos de CRON no salen en la bandeja, así que avisar por websocket
             * haría que todos los clientes recargasen la lista para nada. Y no es
             * gratis: si el servidor de websockets no responde, cada aviso se queda
             * ~2 s esperando a que expire la conexión, y resolver 10 crones de golpe
             * se convierte en 20 segundos.
             */
            if ($t->channel === 'cron') return;

            TicketActivity::dispatch(
                $action,
                $ticketId,
                (string) $t->code,
                (string) $t->subject,
                $assignedTo ?? ($t->assigned_to ? (int) $t->assigned_to : null),
            );
        } catch (\Throwable $e) {
            /*
             * El tiempo real es una comodidad, no un requisito para operar. Se apunta
             * que está caído para no volver a intentarlo (y volver a esperar) en cada
             * acción durante el próximo minuto.
             */
            cache()->put('socket_caido', true, self::SOCKET_PAUSA);
            report($e);
        }
    }

    /**
     * Marca la primera respuesta de soporte (para el SLA). Solo la primera cuenta.
     * Además, un ticket 'nuevo' pasa a 'en_proceso' en cuanto alguien contesta.
     */
    public function markFirstResponse(int $ticketId, ?int $userId = null): void
    {
        $t = DB::table('tickets')->where('id', $ticketId)->first(['status', 'first_response_at']);
        if (!$t) return;

        if ($t->first_response_at === null) {
            DB::table('tickets')->where('id', $ticketId)->update(['first_response_at' => now()]);
        }
        if (in_array($t->status, ['nuevo', 'abierto'], true)) {
            $this->setStatus($ticketId, 'en_progreso', $userId);
        }
    }

    /** Toca la marca de último mensaje (ordena la bandeja). */
    public function touch(int $ticketId): void
    {
        DB::table('tickets')->where('id', $ticketId)->update(['last_message_at' => now()]);
    }

    /**
     * A DÓNDE APUNTA un ticket fusionado. Si no lo está, devuelve el mismo id.
     *
     * Sigue la cadena (A fusionado en B, y B en C → C) con tope de saltos: una
     * referencia circular por un dato mal metido dejaría el proceso girando para
     * siempre, y esto se llama al importar CADA correo que entra.
     */
    public function ticketFinal(int $ticketId): int
    {
        for ($i = 0; $i < 10; $i++) {
            $destino = DB::table('tickets')->where('id', $ticketId)->value('merged_into_id');
            if (!$destino || (int) $destino === $ticketId) break;
            $ticketId = (int) $destino;
        }
        return $ticketId;
    }

    /**
     * FUSIONA dos tickets del MISMO contacto: `$absorbido` se vuelca en `$principal`.
     *
     * Devuelve [ok, error]. Las comprobaciones se hacen aquí y no solo en la pantalla:
     * es una operación que reescribe historial y no debe poder colarse por la API.
     */
    public function merge(int $principal, int $absorbido, ?int $userId = null, string $motivo = ''): array
    {
        if ($principal === $absorbido) return [false, 'Es el mismo ticket'];

        /*
         * El MOTIVO es obligatorio. Una fusión reescribe el historial y no se puede
         * deshacer: quien lo mire dentro de seis meses tiene que poder saber por qué
         * dos conversaciones son ahora una sola.
         */
        $motivo = mb_substr(trim($motivo), 0, 300);
        if ($motivo === '') return [false, 'Escribe el motivo de la fusión'];

        $a = DB::table('tickets')->where('id', $principal)->first(['id', 'code', 'contact_id', 'channel', 'merged_into_id']);
        $b = DB::table('tickets')->where('id', $absorbido)->first(['id', 'code', 'subject', 'contact_id', 'channel', 'merged_into_id']);

        if (!$a || !$b)                       return [false, 'Alguno de los tickets ya no existe'];
        if ($a->merged_into_id || $b->merged_into_id) return [false, 'Uno de los dos ya está fusionado en otro ticket'];
        if ((int) $a->contact_id !== (int) $b->contact_id) return [false, 'Solo se pueden fusionar tickets del mismo cliente'];
        // Los avisos de cron no son una conversación: se agrupan por su propia clave.
        if ($a->channel === 'cron' || $b->channel === 'cron') return [false, 'Los avisos de crones no se fusionan'];

        DB::transaction(function () use ($a, $b, $userId, $motivo) {
            // Tablas 1-a-N sin clave única por ticket: se re-apuntan en bloque.
            foreach (['messages', 'attachments', 'ticket_events', 'cron_alerts'] as $tabla) {
                DB::table($tabla)->where('ticket_id', $b->id)->update(['ticket_id' => $a->id]);
            }

            /*
             * Tablas con clave ÚNICA por ticket (etiquetas, campos personalizados,
             * valoración): un UPDATE a secas chocaría si el principal ya tiene ese
             * mismo registro. Se trasladan solo los que el principal NO tenga; en caso
             * de conflicto, manda lo del principal y se descarta el duplicado del
             * absorbido. Sin esto, esos datos quedaban huérfanos tras la fusión.
             */
            // Etiquetas (pivote ticket_id+label_id).
            $labelsA = DB::table('ticket_label_ticket')->where('ticket_id', $a->id)->pluck('label_id')->all();
            DB::table('ticket_label_ticket')->where('ticket_id', $b->id)
                ->when($labelsA, fn ($q) => $q->whereNotIn('label_id', $labelsA))
                ->update(['ticket_id' => $a->id]);
            DB::table('ticket_label_ticket')->where('ticket_id', $b->id)->delete();

            // Campos personalizados (unique ticket_id+field_id).
            $camposA = DB::table('ticket_field_values')->where('ticket_id', $a->id)->pluck('field_id')->all();
            DB::table('ticket_field_values')->where('ticket_id', $b->id)
                ->when($camposA, fn ($q) => $q->whereNotIn('field_id', $camposA))
                ->update(['ticket_id' => $a->id]);
            DB::table('ticket_field_values')->where('ticket_id', $b->id)->delete();

            // Valoración CSAT (una por ticket): solo se trae si el principal no tiene.
            if (DB::table('ticket_ratings')->where('ticket_id', $a->id)->exists()) {
                DB::table('ticket_ratings')->where('ticket_id', $b->id)->delete();
            } else {
                DB::table('ticket_ratings')->where('ticket_id', $b->id)->update(['ticket_id' => $a->id]);
            }

            /*
             * Los mensajes se leen por fecha, así que el hilo queda intercalado solo.
             * Lo que SÍ hay que rehacer son las marcas del ticket, que son copias
             * para la bandeja: si no, el principal seguiría diciendo que su último
             * mensaje es de antes de la fusión y se ordenaría mal en la lista.
             */
            $ult = DB::table('messages')->where('ticket_id', $a->id)->where('is_internal_note', 0)
                ->orderByDesc('created_at')->orderByDesc('id')->first(['direction', 'created_at']);
            if ($ult) {
                DB::table('tickets')->where('id', $a->id)->update([
                    'last_message_at' => $ult->created_at,
                    'last_direction'  => $ult->direction,
                ]);
            }

            // Rastro en el principal: dentro de seis meses nadie se acuerda de esto.
            $this->event($a->id, 'merge_in', $b->code, $a->code, $userId, $motivo);
            $this->nota($a->id, $userId, sprintf(
                'Se fusionó aquí el ticket <b>%s</b> — «%s».<br>Motivo: <b>%s</b>',
                e($b->code), e((string) $b->subject), e($motivo),
            ));

            /*
             * El absorbido se queda SIN mensajes (se han movido todos), así que se le
             * deja esta nota: abrirlo y ver un hilo vacío sin explicación es peor que
             * no poder abrirlo.
             */
            $this->event($b->id, 'merge_out', $b->code, $a->code, $userId, $motivo);
            $this->nota($b->id, $userId, sprintf(
                'Este ticket se fusionó en <b>%s</b> y sus mensajes están allí.<br>Motivo: <b>%s</b>',
                e($a->code), e($motivo),
            ));
            DB::table('tickets')->where('id', $b->id)->update([
                'status'         => 'cerrado',
                'closed_at'      => now(),
                'merged_into_id' => $a->id,
                'merged_at'      => now(),
                'updated_at'     => now(),
            ]);
        });

        $this->broadcast('merged', $principal);

        return [true, null];
    }

    /** Nota interna del sistema (sin autor humano). */
    protected function nota(int $ticketId, ?int $userId, string $html): void
    {
        DB::table('messages')->insert([
            'ticket_id'        => $ticketId,
            'contact_id'       => DB::table('tickets')->where('id', $ticketId)->value('contact_id'),
            'direction'        => 'out',
            'type'             => 'note',
            'channel'          => 'web',
            'body'             => $html,
            'is_html'          => 1,
            'is_internal_note' => 1,
            'author_user_id'   => $userId,
            'status'           => 'merge',
            'created_at'       => now(),   // `messages` no tiene updated_at
        ]);
    }

    /**
     * Registra un evento en el historial del ticket.
     *
     * `$nota` es para lo que no cabe en «de X a Y»: el MOTIVO. Hoy lo usa la
     * fusión; cualquier otro evento que necesite explicarse ya tiene dónde.
     */
    public function event(int $ticketId, string $type, ?string $from, ?string $to, ?int $userId = null, ?string $nota = null): void
    {
        DB::table('ticket_events')->insert([
            'ticket_id'  => $ticketId,
            'user_id'    => $userId,
            'type'       => $type,
            'from_value' => $from,
            'to_value'   => $to,
            'note'       => $nota,
        ]);
    }

    /** Asunto provisional a partir del primer mensaje (el usuario podrá editarlo). */
    protected function subjectFrom(string $text): string
    {
        $t = trim(preg_replace('/\s+/', ' ', $text));
        if ($t === '') return 'Nueva conversación';
        return mb_substr($t, 0, 80) . (mb_strlen($t) > 80 ? '…' : '');
    }

    /**
     * ¿El mensaje es un saludo/relleno sin sustancia? (No sirve como asunto.)
     * Se usa para no dejar «Hola» de asunto cuando una conversación nueva abre así.
     */
    protected function esSaludo(string $text): bool
    {
        $t = trim(preg_replace('/\s+/', ' ', mb_strtolower($text)));
        // Sin emojis, para medir de verdad.
        $limpio = trim(preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}]/u', '', $t));
        if (mb_strlen($limpio) < 12) return true;
        return (bool) preg_match('/^(hola|buenas|buenos d[ií]as|buenas tardes|buenas noches|hey|hi|ola|bon dia|bones|gr[àa]cies|gracias|ok|vale|perfecto|adi[oó]s|hasta luego)[\s!¡.,:;)\-]*$/u', $t);
    }
}
