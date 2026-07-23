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
        $id = DB::table('tickets')->insertGetId([
            'code'            => $this->nextCode(),
            'subject'         => mb_substr(trim($data['subject'] ?? '') ?: 'Sin asunto', 0, 200),
            'category_id'     => $data['category_id'] ?? null,
            'status'          => $data['status'] ?? self::defaultStatus(),
            'priority'        => $data['priority'] ?? TicketPriority::porDefecto(),
            'channel'         => $data['channel'] ?? 'whatsapp',
            'contact_id'      => $data['contact_id'],
            'assigned_to'     => $data['assigned_to'] ?? null,
            'opened_at'       => now(),
            'last_message_at' => now(),
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
                ->where('t.id', $ticketId)
                ->first([
                    't.opened_at', 't.created_at', 't.first_response_at', 't.resolved_at', 't.closed_at',
                    't.sla_paused_minutes', 't.sla_paused_since',
                    'c.sla_response_hours', 'c.sla_resolve_hours',
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
        if ($assignee) app(NotifyService::class)->ticket('ticket_assigned', $ticketId);
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
            foreach (['messages', 'attachments', 'ticket_events', 'cron_alerts'] as $tabla) {
                DB::table($tabla)->where('ticket_id', $b->id)->update(['ticket_id' => $a->id]);
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
}
