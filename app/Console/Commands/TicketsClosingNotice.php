<?php

namespace App\Console\Commands;

use App\Models\EmailAccount;
use App\Models\Setting;
use App\Models\User;
use App\Services\MailService;
use App\Services\ShiftService;
use App\Services\UpcomingSends;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AVISO AL AGENTE 24 H ANTES DEL CIERRE POR FALTA DE RESPUESTA.
 *
 * Los tickets que `tickets:followup` va a cerrar (avisando al cliente) en menos de 24 h
 * se le mandan por correo a su agente asignado, todos los de esa pasada en un solo
 * correo. Así puede pasarlo a Planificado o volver a escribir al cliente antes de que
 * se cierre. Los que no tienen agente van a quien esté de turno (o a los
 * administradores de soporte si no hay nadie).
 *
 * Cada ticket se avisa UNA vez por plazo: se guarda en `closing_notice_sent`
 * [ticket_id => fecha de cierre]. Si el agente contesta y el plazo se mueve, el nuevo
 * plazo se vuelve a avisar.
 */
class TicketsClosingNotice extends Command
{
    protected $signature = 'tickets:closing-notice
        {--dry : Enseña a quién iría y qué tickets, sin enviar}
        {--to= : Envía una prueba a este correo con los próximos cierres (no marca nada)}';

    protected $description = 'Correo al agente asignado con los tickets que se cerrarán por falta de respuesta en 24 h';

    protected const HORAS = 24;

    public function handle(UpcomingSends $envios, ShiftService $turnos, MailService $mail): int
    {
        $cierres = $envios->cierres();
        $avisados = json_decode((string) Setting::get('closing_notice_sent', '[]'), true) ?: [];
        $limite = now()->addHours(self::HORAS);

        // Los que ya no se van a cerrar (contestó el cliente, se planificó…) salen de la lista.
        $avisados = array_intersect_key($avisados, $cierres);

        $toca = [];
        foreach ($cierres as $id => $cuando) {
            if ($cuando->gt($limite)) continue;
            if (($avisados[$id] ?? null) === $cuando->format('Y-m-d H:i:s')) continue;
            $toca[$id] = $cuando;
        }

        // Prueba: los próximos cierres (los 24 h o, si no hay, los 5 más cercanos).
        if ($prueba = $this->option('to')) {
            if (!$toca) $toca = collect($cierres)->sort()->take(5)->all();
            // Sin cierres previstos (p. ej. en local): dos tickets abiertos de muestra.
            if (!$toca) {
                $toca = DB::table('tickets')->where('status', 'abierto')->orderByDesc('id')->limit(2)->pluck('id')
                    ->mapWithKeys(fn ($id) => [$id => now()->addHours(self::HORAS)])->all();
            }
            if (!$toca) {
                $this->warn('No hay ningún cierre previsto para la prueba.');
                return self::SUCCESS;
            }
            $tickets = $this->tickets($toca);
            $acc = $this->buzon();
            if (!$acc) return self::SUCCESS;
            $mail->sendMail($acc, $prueba, null, $this->asunto($tickets->count()), $this->cuerpo($tickets, null), [], null, [], [], [], null, $this->marco($tickets->count()));
            $this->info('Prueba con ' . $tickets->count() . " ticket(s) enviada a {$prueba}.");
            return self::SUCCESS;
        }

        if (!$toca) {
            if (!$this->option('dry')) Setting::put('closing_notice_sent', json_encode($avisados));
            $this->line('Ningún ticket se cierra en las próximas ' . self::HORAS . ' h sin avisar.');
            return self::SUCCESS;
        }

        $tickets = $this->tickets($toca);
        $grupos = $tickets->groupBy(fn ($t) => (int) ($t->assigned_to ?? 0));
        $guardia = null;

        $acc = $this->option('dry') ? null : $this->buzon();
        if (!$this->option('dry') && !$acc) return self::SUCCESS;

        foreach ($grupos as $agente => $lista) {
            $destinos = $agente ? $this->usuarios([$agente]) : ($guardia ??= $this->guardia($turnos));
            if (!$destinos) {
                $this->warn('Sin destinatario para: ' . $lista->pluck('code')->implode(', '));
                continue;
            }
            if ($this->option('dry')) {
                $this->info($lista->count() . ' ticket(s) para: ' . implode(', ', array_keys($destinos)));
                foreach ($lista as $t) $this->line("  {$t->code} · cierra {$t->cierre->format('d/m H:i')} · {$t->subject}");
                continue;
            }

            $enviado = false;
            foreach ($destinos as $email => $nombre) {
                try {
                    $mail->sendMail($acc, $email, $nombre, $this->asunto($lista->count()), $this->cuerpo($lista, $nombre), [], null, [], [], [], null, $this->marco($lista->count()));
                    $enviado = true;
                } catch (\Throwable $e) {
                    Log::warning('tickets:closing-notice: destinatario falló', ['to' => $email, 'error' => $e->getMessage()]);
                }
            }
            // Si no salió a nadie, se reintenta en la próxima pasada.
            if ($enviado) foreach ($lista as $t) $avisados[$t->id] = $t->cierre->format('Y-m-d H:i:s');
        }

        if (!$this->option('dry')) Setting::put('closing_notice_sent', json_encode($avisados));
        return self::SUCCESS;
    }

    /** Datos de los tickets, del cierre más cercano al más lejano. */
    protected function tickets(array $toca)
    {
        return DB::table('tickets as t')->leftJoin('contacts as c', 'c.id', '=', 't.contact_id')
            ->whereIn('t.id', array_keys($toca))
            ->get(['t.id', 't.code', 't.subject', 't.assigned_to', 'c.name as contact_name', 'c.email as contact_email'])
            ->each(fn ($t) => $t->cierre = $toca[$t->id])
            ->sortBy(fn ($t) => $t->cierre->getTimestamp())->values();
    }

    protected function buzon(): ?EmailAccount
    {
        $acc = EmailAccount::where('funcion', 'soporte')->where('active', true)->whereNotNull('smtp_host')->orderBy('id')->first();
        if (!$acc) $this->warn('No hay buzón de soporte para enviar.');
        return $acc;
    }

    /** correo => nombre de esos usuarios (solo los que tienen correo). */
    protected function usuarios(array $ids): array
    {
        $out = [];
        foreach (User::whereIn('id', $ids)->whereNotNull('email')->where('email', '<>', '')->get(['email', 'name']) as $u) {
            $out[mb_strtolower($u->email)] = $u->name;
        }
        return $out;
    }

    /** Sin agente: quien cubre el turno ahora; si nadie, los administradores de soporte. */
    protected function guardia(ShiftService $turnos): array
    {
        $ids = array_column($turnos->deGuardia()['equipo'] ?? [], 'user_id');
        if (!$ids) {
            $ids = User::role(config('rbac.super_role', 'superadmin'))->pluck('id')
                ->merge(User::permission('support.config')->pluck('id'))->unique()->all();
        }
        return $this->usuarios($ids);
    }

    protected function asunto(int $n): string
    {
        return $n === 1 ? 'Un ticket tuyo se cerrará en 24 h por falta de respuesta'
                        : "{$n} tickets tuyos se cerrarán en 24 h por falta de respuesta";
    }

    protected function marco(int $n): array
    {
        return ['heading' => 'Cierre por falta de respuesta', 'meta' => $this->asunto($n), 'note' => ''];
    }

    /** Cuerpo: qué va a pasar, qué puede hacer y la tabla (enlace directo a cada ticket). */
    protected function cuerpo($tickets, ?string $nombre): string
    {
        $base = rtrim((string) config('app.url'), '/');
        $uno = $tickets->count() === 1;
        $th = 'style="text-align:left;padding:6px 8px;font-size:12px;color:#8a94a6;font-weight:700;border-bottom:1px solid #e6e9f0;white-space:nowrap"';
        $td = 'style="padding:8px;font-size:13px;color:#20272f;border-bottom:1px solid #eef1f5;vertical-align:top"';

        $filas = '';
        foreach ($tickets as $t) {
            $url = $base . '/agentes/tickets/' . rawurlencode((string) $t->code);
            $cliente = $t->contact_name ?: ($t->contact_email ?: '—');
            $filas .= '<tr>'
                . "<td $td><a href=\"" . e($url) . '" style="color:#2563eb;font-weight:700;text-decoration:none;white-space:nowrap">' . e((string) $t->code) . '</a>'
                . '<div style="color:#3a4453;margin-top:2px">' . e(mb_strimwidth((string) $t->subject, 0, 90, '…')) . '</div></td>'
                . "<td $td>" . e((string) $cliente) . '</td>'
                . "<td $td><b style=\"color:#b42318;white-space:nowrap\">" . e($t->cierre->isPast() ? 'Ya' : $t->cierre->format('d/m H:i')) . '</b></td>'
                . '</tr>';
        }

        $hola = $nombre ? 'Hola ' . e(explode(' ', trim($nombre))[0]) . ',' : 'Hola,';

        return '<p style="margin:0 0 10px">' . $hola . '</p>'
            . '<p style="margin:0 0 10px">' . ($uno
                ? 'Este ticket <b>se cerrará en 24 h</b> por falta de respuesta y <b>se le notificará al cliente</b>.'
                : 'Estos tickets <b>se cerrarán en 24 h</b> por falta de respuesta y <b>se les notificará a los clientes</b>.') . '</p>'
            . '<p style="margin:0 0 14px">Si ' . ($uno ? 'sigue' : 'alguno sigue') . ' pendiente, pásalo a <b>Planificado</b> o <b>vuelve a escribirle al cliente</b> para recordárselo. Si no haces nada, se cerrará solo.</p>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;background:#ffffff;border-radius:8px">'
            . "<tr><th $th>Ticket</th><th $th>Cliente</th><th $th>Se cierra</th></tr>"
            . $filas . '</table>';
    }
}
