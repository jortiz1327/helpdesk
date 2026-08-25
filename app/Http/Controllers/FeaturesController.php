<?php

namespace App\Http\Controllers;

use App\Models\EmailAccount;
use App\Models\Setting;
use Illuminate\Http\Request;

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
        'ia_activa'             => 'bool',
        'soporteqa_activo'      => 'bool',
        'sla_active'            => 'bool',
        'sla_alerts_active'     => 'bool',
        'sla_escalate_active'   => 'bool',
        'csat_active'           => 'bool',
        'email_footer_active'   => 'bool',
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

        $iaKey = trim($g('ia_api_key', '')) !== '';
        $qaKey = trim($g('soporteqa_key', '')) !== '';
        $buzon = EmailAccount::where('active', true)->whereNotNull('smtp_host')->exists();

        $grupos = [
            ['grupo' => 'Inteligencia artificial', 'items' => [
                $this->flag('ia_activa', 'bool', 'Agente de IA', 'Claude redacta borradores con FAQs, historial y plantillas.',
                    $g('ia_activa') === '1', $iaKey ? null : 'Falta la clave de API — ponla en «Agente IA → Ajustes».'),
                $this->flag('soporteqa_activo', 'bool', 'Lector de fotos (soporteQA)', 'Lee el código de la etiqueta en la foto del cliente.',
                    $g('soporteqa_activo') === '1', $qaKey ? null : 'Falta la clave de soporteQA.'),
            ]],
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
            ['grupo' => 'Correo', 'items' => [
                $this->flag('email_footer_active', 'bool', 'Pie de correos', 'Añade un pie fijo a los correos salientes.', $g('email_footer_active') === '1'),
                // Informativo: no es un interruptor, es «dar de alta el buzón».
                ['key' => 'email_channel', 'type' => 'info', 'label' => 'Canal de correo',
                 'desc' => 'Convierte los correos entrantes en tickets y permite responder por email.',
                 'value' => $buzon, 'note' => $buzon ? null : 'Falta dar de alta el buzón — ve a «Correo → Buzón y envío».'],
            ]],
        ];

        return response()->json(['ok' => true, 'grupos' => $grupos]);
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
