<?php

namespace App\Http\Controllers;

use App\Models\EmailAccount;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * PANEL DE FUNCIONES (solo superadmin). Un único sitio para ver y encender/apagar las
 * funciones que hoy están repartidas entre varios ajustes, y saber POR QUÉ cada una
 * está apagada: un interruptor, una credencial que falta, o un buzón sin dar de alta.
 *
 * Solo se pueden FLIPAR los ajustes de la lista blanca TOGGLES (nada de escribir claves
 * de valor arbitrario desde aquí). Las credenciales (clave de IA, buzón) se configuran
 * en su propio apartado; aquí solo se informa de si están puestas.
 */
class FeaturesController extends Controller
{
    /** Interruptores que este panel puede cambiar, con su tipo. */
    protected const TOGGLES = [
        'sla_active'            => 'bool',
        'sla_alerts_active'     => 'bool',
        'sla_escalate_active'   => 'bool',
        'csat_active'           => 'bool',
        'ticket_autoclose_days' => 'int',
        'ticket_lock_minutes'   => 'int',
    ];

    public function handle(Request $request)
    {
        if (!$request->user()->isSuperAdmin()) {
            return response()->json(['ok' => false, 'error' => 'Solo el superadministrador'], 403);
        }
        return match ($request->query('action', 'list')) {
            'set'   => $this->set($request),
            default => $this->list(),
        };
    }

    protected function list()
    {
        $g = fn (string $k, string $d = '0') => (string) Setting::get($k, $d);

        $buzon = EmailAccount::where('active', true)->whereNotNull('smtp_host')->exists();

        // SALUD DE LA RECOGIDA (IMAP). El correo es el canal del helpdesk: aquí se ve de un
        // vistazo si de verdad está entrando. La señal es `last_check_at` (lo actualiza
        // email:fetch cada minuto). Si lleva rato parada, casi siempre es que el planificador
        // (schedule:run) no corre en el servidor — el fallo más típico y silencioso.
        $imapActivas = EmailAccount::where('active', true)->whereNotNull('imap_host')->count();
        $ultimaRaw   = EmailAccount::where('active', true)->whereNotNull('imap_host')->max('last_check_at');
        $intake = $this->saludRecogida($imapActivas, $ultimaRaw);

        // El pie de los correos (texto + on/off) se configura en «Correo → Buzón y envío»,
        // junto a su contenido; no se duplica aquí como interruptor suelto.
        $correo = array_values(array_filter([
            // Informativo: no es un interruptor, es «dar de alta el buzón».
            ['key' => 'email_channel', 'type' => 'info', 'label' => 'Canal de correo',
             'desc' => 'Convierte los correos entrantes en tickets y permite responder por email.',
             'value' => $buzon, 'note' => $buzon ? null : 'Falta dar de alta el buzón — ve a «Correo → Buzón y envío».'],
            $intake,
        ]));

        $grupos = [
            ['grupo' => 'SLA', 'items' => [
                $this->flag('sla_active', 'bool', 'SLA', 'Relojes de primera respuesta y de resolución.', $g('sla_active', '1') === '1'),
                $this->flag('sla_alerts_active', 'bool', 'Avisos de SLA por correo', 'Correos «por vencer» y «vencido».', $g('sla_alerts_active', '1') === '1'),
                $this->flag('sla_escalate_active', 'bool', 'Escalado automático', 'Al vencer: sube la prioridad y reasigna al agente de guardia.', $g('sla_escalate_active') === '1'),
            ]],
            ['grupo' => 'Tickets automáticos', 'items' => [
                $this->flag('csat_active', 'bool', 'Encuesta de satisfacción (CSAT)', 'Valoración 1-5★ en el portal y por correo al resolver.', $g('csat_active', '1') === '1'),
                $this->flag('ticket_autoclose_days', 'int', 'Auto-cierre de resueltos', 'Cierra los tickets tras N días resueltos sin actividad (0 = apagado).', (int) $g('ticket_autoclose_days', '0')),
                $this->flag('ticket_lock_minutes', 'int', 'Bloqueo por colisión', 'Minutos que un agente «toma» un ticket para que otro no escriba encima (0 = apagado).', (int) $g('ticket_lock_minutes', '2')),
            ]],
            ['grupo' => 'Correo', 'items' => $correo],
        ];

        return response()->json(['ok' => true, 'grupos' => $grupos]);
    }

    /**
     * Estado de la recogida IMAP como ítem «health» (verde reciente / rojo parada /
     * ámbar aún sin recoger). Devuelve null si no hay ningún buzón IMAP dado de alta
     * (en ese caso ya lo dice el ítem «Canal de correo»).
     */
    protected function saludRecogida(int $imapActivas, $ultimaRaw): ?array
    {
        if ($imapActivas === 0) {
            return null;
        }
        $base = ['key' => 'email_intake', 'type' => 'health', 'label' => 'Recogida de correo',
                 'desc' => 'Cada minuto se leen los buzones IMAP y los correos nuevos entran como tickets.'];

        if (!$ultimaRaw) {
            return $base + ['state' => 'wait', 'value' => 'aún sin recoger',
                'note' => 'Todavía no se ha recogido correo. Si acabas de configurar el buzón, espera un minuto; si no, revisa que el planificador (schedule:run) esté activo en el servidor.'];
        }

        $mins = (int) Carbon::parse($ultimaRaw)->diffInMinutes(now());
        $hace = 'hace ' . $this->humano($mins);
        if ($mins <= 5) {
            return $base + ['state' => 'ok', 'value' => $hace, 'note' => null];
        }
        return $base + ['state' => 'stale', 'value' => $hace,
            'note' => 'La última recogida fue ' . $hace . '. El correo no está entrando: probablemente el planificador (schedule:run) no corre en el servidor.'];
    }

    /** Minutos → texto humano corto («8 min», «3 h», «2 días»). */
    protected function humano(int $mins): string
    {
        if ($mins < 1)    return 'un momento';
        if ($mins < 60)   return $mins . ' min';
        if ($mins < 1440) return intdiv($mins, 60) . ' h';
        $d = intdiv($mins, 1440);
        return $d . ($d === 1 ? ' día' : ' días');
    }

    protected function flag(string $key, string $type, string $label, string $desc, $value, ?string $note = null): array
    {
        return compact('key', 'type', 'label', 'desc', 'value', 'note');
    }

    protected function set(Request $request)
    {
        $key = (string) $request->input('key');
        if (!array_key_exists($key, self::TOGGLES)) {
            return response()->json(['ok' => false, 'error' => 'Ajuste no permitido desde aquí'], 400);
        }

        $val = self::TOGGLES[$key] === 'bool'
            ? ($request->boolean('value') ? '1' : '0')
            : (string) max(0, (int) $request->input('value', 0));

        Setting::put($key, $val);
        return response()->json(['ok' => true, 'value' => $val]);
    }
}
