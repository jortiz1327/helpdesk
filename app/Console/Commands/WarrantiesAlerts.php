<?php

namespace App\Console\Commands;

use App\Models\EmailAccount;
use App\Models\User;
use App\Services\MailService;
use App\Services\WarrantyAlertNotices;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * AVISOS DE ALERTAS DE GARANTÍAS (cada 15 minutos): a la campana, las garantías que
 * acaban de pasar a crítico; y, si está encendido en Configuración, el correo diario
 * a cada responsable con las suyas. Ver App\Services\WarrantyAlertNotices.
 */
class WarrantiesAlerts extends Command
{
    protected $signature = 'warranties:alerts
        {--dry : Enseña qué se avisaría, sin avisar ni enviar}
        {--force : Manda ya el correo (si está encendido y es laborable), aunque no sea la hora o ya saliera hoy}';

    protected $description = 'Avisa en la campana de las garantías que pasan a crítico y manda el resumen diario por correo';

    protected const MAX_FILAS = 60;

    public function handle(MailService $mail): int
    {
        [$avisos, $nuevas] = WarrantyAlertNotices::check((bool) $this->option('dry'));
        $this->line($nuevas ? count($nuevas) . ' garantía(s) nuevas en crítico (' . implode(', ', $nuevas) . "): {$avisos} aviso(s)." : 'Ninguna garantía nueva en crítico.');

        if (! WarrantyAlertNotices::digestDue((bool) $this->option('force'))) return self::SUCCESS;

        $plan = WarrantyAlertNotices::digestPlan();
        $usuarios = User::whereIn('id', array_keys($plan))->whereNotNull('email')->where('email', '<>', '')->get(['id', 'name', 'email'])->keyBy('id');
        if ($this->option('dry')) {
            foreach ($plan as $uid => $l) $this->line('  ' . ($usuarios[$uid]->email ?? "#{$uid} sin correo") . ': ' . implode(', ', array_column($l, 'code')));
            return self::SUCCESS;
        }
        if (! $plan) { WarrantyAlertNotices::digestSent(); return self::SUCCESS; }

        $acc = EmailAccount::where('funcion', 'soporte')->where('active', true)->whereNotNull('smtp_host')->orderBy('id')->first();
        if (! $acc) { $this->warn('No hay buzón de soporte para enviar.'); return self::SUCCESS; }

        $base = rtrim((string) config('app.url'), '/');
        $enviados = 0;
        foreach ($plan as $uid => $filas) {
            $u = $usuarios[$uid] ?? null;
            if (! $u) continue;
            $n = count($filas);
            $crit = count(array_filter($filas, fn ($f) => $f['level'] === 'crit'));
            $asunto = $n === 1 ? '1 garantía necesita atención' : "{$n} garantías necesitan atención";
            if ($crit) $asunto .= $crit === $n ? ($crit === 1 ? ' (crítica)' : ' (críticas)') : " ({$crit} críticas)";
            try {
                $mail->sendMail($acc, $u->email, $u->name, $asunto, $this->cuerpo($filas, $u->name, $base), [], null, [], [], [], null,
                    ['heading' => 'Garantías con alertas', 'meta' => $asunto, 'note' => '']);
                $enviados++;
            } catch (\Throwable $e) {
                Log::warning('warranties:alerts: destinatario falló', ['to' => $u->email, 'error' => $e->getMessage()]);
            }
        }
        // Si no llegó a nadie, se reintenta en la próxima pasada.
        if ($enviados) WarrantyAlertNotices::digestSent();
        $this->info("Resumen de alertas enviado a {$enviados} persona(s).");
        return self::SUCCESS;
    }

    /** Saludo y la tabla de garantías (enlace directo a cada ficha). */
    protected function cuerpo(array $filas, string $nombre, string $base): string
    {
        $th = 'style="text-align:left;padding:6px 8px;font-size:12px;color:#8a94a6;font-weight:700;border-bottom:1px solid #e6e9f0;white-space:nowrap"';
        $td = 'style="padding:8px;font-size:13px;color:#20272f;border-bottom:1px solid #eef1f5;vertical-align:top"';
        $html = '';
        foreach (array_slice($filas, 0, self::MAX_FILAS) as $f) {
            $url = $base . '/agentes/garantias/' . $f['id'];
            $color = $f['level'] === 'crit' ? '#b42318' : '#b54708';
            $html .= '<tr>'
                . "<td $td><a href=\"" . e($url) . '" style="color:#2563eb;font-weight:700;text-decoration:none;white-space:nowrap">' . e($f['code']) . '</a>'
                . ($f['unassigned'] ? '<div style="color:#8a94a6;font-size:12px">Sin responsable</div>' : '') . '</td>'
                . "<td $td>" . e($f['client'] ?: '—') . ($f['project'] ? '<div style="color:#8a94a6;font-size:12px">' . e($f['project']) . '</div>' : '') . '</td>'
                . "<td $td><b style=\"color:{$color}\">" . e(implode(' · ', $f['alerts'])) . '</b></td>'
                . '</tr>';
        }
        $resto = count($filas) - self::MAX_FILAS;
        $mas = $resto > 0 ? '<p style="margin:10px 0 0;font-size:13px;color:#8a94a6">Y ' . $resto . ' más: míralas en Garantías → Más filtros → Con alertas.</p>' : '';
        return '<p style="margin:0 0 10px">Hola ' . e(explode(' ', trim($nombre))[0]) . ',</p>'
            . '<p style="margin:0 0 14px">Estas garantías tuyas tienen alertas. Revísalas, por favor: muévelas, apunta lo que falta o ciérralas si ya están resueltas.</p>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;background:#ffffff;border-radius:8px">'
            . "<tr><th $th>Garantía</th><th $th>Cliente</th><th $th>Alertas</th></tr>" . $html . '</table>' . $mas;
    }
}
