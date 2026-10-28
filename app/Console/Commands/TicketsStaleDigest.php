<?php

namespace App\Console\Commands;

use App\Models\EmailAccount;
use App\Models\Setting;
use App\Models\User;
use App\Services\BusinessHoursService;
use App\Services\MailService;
use App\Services\ShiftService;
use App\Services\StaleTickets;
use App\Services\TicketService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * CORREO DIARIO DE TICKETS SIN CONTESTAR a quien está de turno de soporte.
 *
 * Un solo correo con el listado (no uno por ticket), los días laborables a partir de
 * la hora configurada (07:30 por defecto). Ni sábados, ni domingos, ni festivos (los
 * de «Horario de atención»). Se repite cada día mientras quede alguno; si no hay
 * ninguno, no se manda nada.
 *
 * Destinatarios: todos los que cubren el turno en ese momento (titulares y
 * sustitutos, según el cuadrante). Si nadie lo cubre, los administradores de soporte.
 * Los pospuestos no entran: alguien ya decidió apartarlos hasta una fecha.
 */
class TicketsStaleDigest extends Command
{
    protected $signature = 'tickets:stale-digest
        {--dry : Enseña a quién iría y qué tickets, sin enviar}
        {--force : Ignora la hora y el «ya enviado hoy» (no el fin de semana ni los festivos)}';

    protected $description = 'Correo diario al turno de soporte con los tickets que esperan respuesta hace X días';

    /** Tope de filas en el correo (la idea es que haya pocos; esto es solo una red). */
    protected const MAX_FILAS = 100;

    public function handle(ShiftService $turnos, BusinessHoursService $horario, MailService $mail): int
    {
        if ((string) Setting::get('stale_alert_active', '1') !== '1') {
            $this->line('Aviso de tickets sin contestar apagado.');
            return self::SUCCESS;
        }

        $ahora = now();
        $hoy   = $ahora->format('Y-m-d');
        if ($ahora->isWeekend() || isset($horario->festivos()[$hoy])) {
            $this->line('Hoy no es laborable: no se avisa.');
            return self::SUCCESS;
        }
        if (!$this->option('force')) {
            if ($ahora->format('H:i') < self::hora()) return self::SUCCESS;            // aún no es la hora
            if (Setting::get('stale_alert_last') === $hoy) return self::SUCCESS;       // ya salió hoy
        }

        $dias = StaleTickets::dias();
        $q = DB::table('tickets as t')
            ->leftJoin('contacts as c', 'c.id', '=', 't.contact_id')
            ->leftJoin('users as u', 'u.id', '=', 't.assigned_to')
            ->whereRaw(\App\Http\Controllers\TicketsController::SQL_DESPIERTO);
        StaleTickets::filtrar($q);
        $tickets = $q->get(['t.id', 't.code', 't.subject', 't.status', 't.channel', 't.source',
            'c.name as contact_name', 'c.email as contact_email', 'c.wa_id as contact_wa', 'u.name as agent_name']);

        if ($tickets->isEmpty()) {
            $this->line('Ningún ticket lleva más de ' . $dias . ' día(s) sin contestar.');
            if (!$this->option('dry')) Setting::put('stale_alert_last', $hoy);
            return self::SUCCESS;
        }

        // Del que más espera al que menos.
        $desde = StaleTickets::esperandoDesde($tickets->pluck('id')->all());
        $tickets = $tickets->each(fn ($t) => $t->desde = $desde[$t->id] ?? null)
            ->sortBy(fn ($t) => $t->desde?->getTimestamp() ?? 0)->values();

        $destinos = $this->destinatarios($turnos);
        if (!$destinos) {
            $this->warn('No hay nadie de turno ni administradores con correo.');
            return self::SUCCESS;
        }

        if ($this->option('dry')) {
            $this->info($tickets->count() . ' ticket(s) para: ' . implode(', ', array_keys($destinos)));
            foreach ($tickets as $t) $this->line("  {$t->code} · " . (int) floor($t->desde?->diffInDays($ahora, true) ?? 0) . " días · {$t->subject}");
            return self::SUCCESS;
        }

        $acc = EmailAccount::where('funcion', 'soporte')->where('active', true)->whereNotNull('smtp_host')->orderBy('id')->first();
        if (!$acc) {
            $this->warn('No hay buzón de soporte para enviar.');
            return self::SUCCESS;
        }

        $n = $tickets->count();
        $asunto = $n === 1 ? '1 ticket lleva más de ' . $dias . ' días sin contestar'
                            : "{$n} tickets llevan más de {$dias} días sin contestar";
        $base = rtrim((string) config('app.url'), '/');
        $marco = [
            'heading' => 'Tickets esperando respuesta',
            'meta'    => $asunto,
            'note'    => '',
        ];

        $enviados = 0;
        foreach ($destinos as $email => $nombre) {
            try {
                $mail->sendMail($acc, $email, $nombre, $asunto, $this->cuerpo($tickets, $dias, $nombre, $base), [], null, [], [], [], null, $marco);
                $enviados++;
            } catch (\Throwable $e) {
                Log::warning('tickets:stale-digest: destinatario falló', ['to' => $email, 'error' => $e->getMessage()]);
            }
        }
        // Si no llegó a nadie, se reintenta en la próxima pasada.
        if ($enviados) Setting::put('stale_alert_last', $hoy);

        $this->info("Aviso de {$n} ticket(s) sin contestar enviado a {$enviados} persona(s).");
        return self::SUCCESS;
    }

    /** Hora del envío «HH:MM» (07:30 por defecto). */
    public static function hora(): string
    {
        $h = (string) Setting::get('stale_alert_hour', '07:30');
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $h) ? $h : '07:30';
    }

    /**
     * Quien cubre el turno AHORA (todo el equipo del turno que manda, titulares y
     * sustitutos). Si no hay nadie, los administradores de soporte. correo => nombre.
     */
    protected function destinatarios(ShiftService $turnos): array
    {
        $ids = array_column($turnos->deGuardia()['equipo'] ?? [], 'user_id');
        if (!$ids) {
            $ids = User::role(config('rbac.super_role', 'superadmin'))->pluck('id')
                ->merge(User::permission('support.config')->pluck('id'))->unique()->all();
        }

        $out = [];
        foreach (User::whereIn('id', $ids)->whereNotNull('email')->where('email', '<>', '')->get(['email', 'name']) as $u) {
            $out[mb_strtolower($u->email)] = $u->name;
        }
        return $out;
    }

    /** Cuerpo: saludo, qué se pide y la tabla de tickets (enlace directo a cada uno). */
    protected function cuerpo($tickets, int $dias, ?string $nombre, string $base): string
    {
        $ahora = now();
        $estados = TicketService::STATUSES;
        $th = 'style="text-align:left;padding:6px 8px;font-size:12px;color:#8a94a6;font-weight:700;border-bottom:1px solid #e6e9f0;white-space:nowrap"';
        $td = 'style="padding:8px;font-size:13px;color:#20272f;border-bottom:1px solid #eef1f5;vertical-align:top"';

        $filas = '';
        foreach ($tickets->take(self::MAX_FILAS) as $t) {
            $url = $base . '/agentes/tickets/' . rawurlencode((string) $t->code);
            $dd  = $t->desde ? (int) floor($t->desde->diffInDays($ahora, true)) : $dias;
            $cliente = $t->contact_name ?: ($t->contact_email ?: ($t->contact_wa ? '+' . $t->contact_wa : '—'));
            $filas .= '<tr>'
                . "<td $td><a href=\"" . e($url) . '" style="color:#2563eb;font-weight:700;text-decoration:none;white-space:nowrap">' . e((string) $t->code) . '</a>'
                . '<div style="color:#3a4453;margin-top:2px">' . e(mb_strimwidth((string) $t->subject, 0, 90, '…')) . '</div></td>'
                . "<td $td>" . e((string) $cliente) . '<div style="color:#8a94a6;font-size:12px">' . e($this->canal($t)) . '</div></td>'
                . "<td $td>" . e((string) ($estados[$t->status] ?? $t->status)) . '<div style="color:#8a94a6;font-size:12px">' . e((string) ($t->agent_name ?: 'Sin asignar')) . '</div></td>'
                . "<td $td><b style=\"color:#b42318;white-space:nowrap\">" . $dd . ' días</b></td>'
                . '</tr>';
        }
        $resto = $tickets->count() - self::MAX_FILAS;
        $mas = $resto > 0 ? '<p style="margin:10px 0 0;font-size:13px;color:#8a94a6">Y ' . $resto . ' más, que saldrán en el próximo aviso.</p>' : '';

        $hola = $nombre ? 'Hola ' . e(explode(' ', trim($nombre))[0]) . ',' : 'Hola,';

        return '<p style="margin:0 0 10px">' . $hola . '</p>'
            . '<p style="margin:0 0 10px">Estos tickets tienen la <b>última respuesta del cliente</b> y llevan más de <b>' . $dias . ' días</b> sin que le contestemos.</p>'
            . '<p style="margin:0 0 14px">Revísalos, por favor: contesta al cliente, <b>resuélvelo o ciérralo</b> si ya está atendido, o pásalo a <b>Planificado</b> si está pendiente de algo con fecha.</p>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;background:#ffffff;border-radius:8px">'
            . "<tr><th $th>Ticket</th><th $th>Cliente</th><th $th>Estado</th><th $th>Esperando</th></tr>"
            . $filas . '</table>' . $mas;
    }

    /** Canal como lo enseña el badge de la app. */
    protected function canal(object $t): string
    {
        if ($t->channel === 'whatsapp') return 'WhatsApp';
        if ($t->channel === 'web' || in_array($t->source, ['portal', 'manual'], true)) return 'Web';
        return 'Correo';
    }
}
