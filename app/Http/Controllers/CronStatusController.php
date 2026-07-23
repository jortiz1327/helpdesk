<?php

namespace App\Http\Controllers;

use App\Models\EmailAccount;
use App\Models\Setting;
use Illuminate\Http\Request;

/**
 * Estado del PLANIFICADOR (equivale a «Configuración de Cron» de osTicket).
 *
 * Es informativo: enseña el comando que hay que dejar puesto en el cron del
 * servidor y si de verdad se está ejecutando. Es el fallo más silencioso del
 * despliegue: no salta ningún error, simplemente deja de entrar el correo.
 */
class CronStatusController extends Controller
{
    public function handle(Request $request)
    {
        $ultimo = Setting::get('cron_last_run');
        $hace   = $ultimo ? (int) round(max(0, now()->diffInSeconds($ultimo, true))) : null;

        // El planificador corre cada minuto: si lleva más de 5 sin dar señales, algo pasa.
        $vivo = $hace !== null && $hace < 300;

        $acc = EmailAccount::query()->orderBy('id')->first(['last_check_at']);

        return response()->json([
            'command'   => 'php ' . base_path('artisan') . ' schedule:run',
            'cron_line' => '* * * * * cd ' . base_path() . ' && php artisan schedule:run >> /dev/null 2>&1',
            'last_run'  => $ultimo,
            'seconds_ago' => $hace,
            'alive'     => $vivo,
            'tasks'     => [
                ['name' => 'Sondeo del buzón (correo → tickets)', 'schedule' => 'Cada minuto', 'last' => $acc?->last_check_at],
                ['name' => 'Automatizaciones y bots',             'schedule' => 'Cada minuto', 'last' => null],
                ['name' => 'Envío de campañas',                   'schedule' => 'Cada minuto', 'last' => null],
                ['name' => 'Cierre automático de tickets',        'schedule' => 'Cada día a las 03:30',
                 'last' => null, 'off' => (int) Setting::get('ticket_autoclose_days', '0') === 0],
            ],
        ]);
    }
}
