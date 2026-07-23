<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\TicketService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Ajustes generales del ticket (equivale a «Ticket settings» de osTicket):
 * con qué estado nace un ticket y el bloqueo para evitar colisión de agentes.
 * Requiere support.config.
 */
class TicketSettingsController extends Controller
{
    public function handle(Request $request)
    {
        if ($request->isMethod('post')) return $this->save($request);

        return response()->json([
            'settings' => [
                'ticket_default_status'   => TicketService::defaultStatus(),
                'ticket_lock_minutes'     => (int) Setting::get('ticket_lock_minutes', '2'),
                'ticket_autoclose_days'   => (int) Setting::get('ticket_autoclose_days', '0'),
                'ticket_autoclose_notify' => (string) Setting::get('ticket_autoclose_notify', '0') === '1',
                // Seguridad del acceso (fuerza bruta)
                'login_max_user'     => (int) Setting::get('login_max_user', '7'),
                'login_max_ip'       => (int) Setting::get('login_max_ip', '25'),
                'login_lock_minutes' => (int) Setting::get('login_lock_minutes', '5'),
                'login_lock_message' => (string) Setting::get('login_lock_message', ''),
            ],
            'statuses' => TicketService::STATUSES,
            // Cuántos se cerrarían HOY con el ajuste actual: da confianza antes de guardar.
            'autoclose_pending' => $this->pendientesAutocierre(),
        ]);
    }

    /**
     * Guarda SOLO los ajustes que vengan en la petición. Es importante: la pantalla
     * está partida en secciones (comportamiento, seguridad…) y cada una guarda por
     * su cuenta; si aquí se rellenaran los ausentes con su valor por defecto, guardar
     * una sección pisaría los ajustes de las otras.
     */
    protected function save(Request $request)
    {
        if ($request->has('ticket_default_status')) {
            $status = (string) $request->input('ticket_default_status');
            if (!array_key_exists($status, TicketService::STATUSES)) {
                return response()->json(['ok' => false, 'error' => 'Ese estado no existe'], 400);
            }
            // Un ticket no puede nacer resuelto o cerrado: no tendría sentido.
            if (in_array($status, ['resuelto', 'cerrado'], true)) {
                return response()->json(['ok' => false, 'error' => 'Un ticket nuevo no puede nacer resuelto ni cerrado'], 400);
            }
            Setting::put('ticket_default_status', $status);
        }

        // 0 = desactivado. Se acota para que nadie deje un ticket tomado media hora.
        if ($request->has('ticket_lock_minutes')) {
            Setting::put('ticket_lock_minutes', (string) max(0, min(60, (int) $request->input('ticket_lock_minutes'))));
        }
        // 0 = auto-cierre apagado. Se acota a 3650 (10 años) por si acaso.
        if ($request->has('ticket_autoclose_days')) {
            Setting::put('ticket_autoclose_days', (string) max(0, min(3650, (int) $request->input('ticket_autoclose_days'))));
        }
        if ($request->has('ticket_autoclose_notify')) {
            Setting::put('ticket_autoclose_notify', filter_var($request->input('ticket_autoclose_notify'), FILTER_VALIDATE_BOOLEAN) ? '1' : '0');
        }

        // Seguridad del acceso. Se acotan para no dejar la puerta abierta ni
        // bloquear a todo el mundo por un dedazo.
        if ($request->has('login_max_user'))     Setting::put('login_max_user',     (string) max(1, min(50, (int) $request->input('login_max_user'))));
        if ($request->has('login_max_ip'))       Setting::put('login_max_ip',       (string) max(1, min(200, (int) $request->input('login_max_ip'))));
        if ($request->has('login_lock_minutes')) Setting::put('login_lock_minutes', (string) max(1, min(120, (int) $request->input('login_lock_minutes'))));
        if ($request->has('login_lock_message')) Setting::put('login_lock_message', mb_substr(trim((string) $request->input('login_lock_message')), 0, 300));

        return response()->json(['ok' => true, 'autoclose_pending' => $this->pendientesAutocierre()]);
    }

    /** Cuántos tickets se cerrarían ahora mismo con el ajuste guardado. */
    protected function pendientesAutocierre(): int
    {
        $dias = (int) Setting::get('ticket_autoclose_days', '0');
        if ($dias <= 0) return 0;

        return DB::table('tickets')
            ->where('status', 'resuelto')
            ->whereRaw('COALESCE(last_message_at, resolved_at, opened_at) < ?', [now()->subDays($dias)])
            ->count();
    }
}
