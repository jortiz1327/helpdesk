<?php

namespace App\Services;

use App\Models\EmailAccount;
use App\Models\Warranty;
use App\Models\WarrantyDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Avisos al cliente cuando su garantía cambia de fase: un correo corto con el enlace al
 * portal, solo en las fases que le importan (recibida, presupuesto, enviada, resuelta).
 *   · Una vez por garantía y fase (si se reabre y se vuelve a resolver, no se repite).
 *   · Al correo del cliente de la garantía o, si no lo tiene, al del ticket cuando es de
 *     correo. A un cliente de WhatsApp sin correo no se le manda nada.
 *   · Sale después de responder (no hace esperar a quien cambia el estado) y del buzón
 *     de soporte. Fuera de producción no se manda (services.warranty_notices.send).
 * Cada fase se enciende o apaga en Configuración → Avisos al cliente.
 */
class WarrantyClientNotices
{
    // fase => [nombre, cuándo se manda]
    public const PHASES = [
        'recibida'    => ['Recibida', 'Al dar de alta la garantía'],
        'presupuesto' => ['Pendiente de presupuesto', 'Al pasar a «Pte. presupuesto»'],
        'enviada'     => ['Enviada', 'Al pasar a «Enviada»'],
        'resuelta'    => ['Resuelta', 'Al pasar a «Resuelta» o «Cerrada»'],
    ];
    public const DEFAULTS = ['recibida' => true, 'presupuesto' => true, 'enviada' => true, 'resuelta' => true];
    private const CAMPO = 'client_notice';   // en warranty_events: from_value = fase

    public static function settings(): array
    {
        $v = WarrantyDocument::setting('client_notices');
        return array_map('boolval', array_replace(self::DEFAULTS, array_intersect_key($v, self::DEFAULTS)));
    }

    public static function clean(array $in): array
    {
        return array_map(fn ($k) => (bool) ($in[$k] ?? false), array_combine(array_keys(self::DEFAULTS), array_keys(self::DEFAULTS)));
    }

    /** La fase que toca avisar por este alta o cambio de estado, o null. */
    public static function phaseFor(Warranty $w, bool $nueva): ?string
    {
        // Una garantía que se da de alta ya cerrada (apuntada a posteriori) no avisa de nada.
        if ($nueva) return $w->isOpen() ? 'recibida' : null;
        return match ((string) $w->status) {
            'pte_presupuesto' => 'presupuesto',
            'enviada' => 'enviada',
            'resuelta', Warranty::STATUS_CLOSED => 'resuelta',
            default => null,
        };
    }

    /** Lo llama el modelo al crearse o cambiar de estado: el correo sale tras la respuesta. */
    public static function queue(Warranty $w, bool $nueva): void
    {
        $fase = self::phaseFor($w, $nueva);
        if (! $fase || ! (self::settings()[$fase] ?? false)) return;
        $id = (int) $w->id;
        dispatch(fn () => self::send($id, $fase))->afterResponse();
    }

    /** Manda el aviso de esa fase si sigue tocando y no se mandó ya. true si salió. */
    public static function send(int $warrantyId, string $fase): bool
    {
        try {
            if (! config('services.warranty_notices.send')) return false;
            $w = Warranty::find($warrantyId);
            // Si en el mismo rato se movió a otro estado, ya no toca (p. ej. un clic por error).
            if (! $w || self::phaseFor($w, $fase === 'recibida') !== $fase) return false;
            if (DB::table('warranty_events')->where('warranty_id', $w->id)->where('field', self::CAMPO)->where('from_value', $fase)->exists()) return false;

            [$email, $ticket] = self::destinatario($w);
            if (! $email) return false;
            $acc = EmailAccount::where('funcion', 'soporte')->where('active', true)->whereNotNull('smtp_host')->orderBy('id')->first();
            if (! $acc) return false;

            [$asunto, $titular, $texto] = self::textos($w, $fase);
            // Con el código del ticket en el asunto, si el cliente responde vuelve a su ticket.
            if ($ticket) $asunto .= " [{$ticket}]";
            $url = rtrim((string) config('app.url'), '/') . '/garantias?g=' . rawurlencode((string) $w->code);
            $html = '<p>' . $texto . '</p><p style="color:#667085;font-size:13px">En el portal de soporte puedes ver cómo va en cualquier momento: '
                . 'te pediremos un código que llega a este correo.</p>';
            app(MailService::class)->sendMail($acc, $email, null, $asunto, $html, [], null, [], [], [], null,
                ['heading' => $titular, 'meta' => (string) $w->code, 'cta' => ['url' => $url, 'label' => 'Ver mi garantía']]);

            DB::table('warranty_events')->insert(['warranty_id' => $w->id, 'user_id' => null, 'field' => self::CAMPO, 'from_value' => $fase,
                'to_value' => mb_substr('Aviso al cliente «' . self::PHASES[$fase][0] . "» enviado a {$email}", 0, 500), 'created_at' => now()]);
            return true;
        } catch (Throwable $e) {
            Log::warning("Garantía {$warrantyId}: no se pudo mandar el aviso «{$fase}» al cliente: " . $e->getMessage());
            return false;
        }
    }

    /** [correo, código del ticket] a quien avisar. */
    private static function destinatario(Warranty $w): array
    {
        $t = $w->ticket_id ? DB::table('tickets as t')->leftJoin('contacts as c', 'c.id', '=', 't.contact_id')
            ->where('t.id', $w->ticket_id)->first(['t.code', 't.channel', 'c.email']) : null;
        $email = mb_strtolower(trim((string) $w->customer_email));
        if ($email === '' && $t && $t->channel !== 'whatsapp') $email = mb_strtolower(trim((string) $t->email));
        return [filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null, $t?->code];
    }

    /** [asunto, titular, texto en HTML] de cada fase. */
    public static function textos(Warranty $w, string $fase): array
    {
        $c = e((string) $w->code);
        $donde = ($l = trim((string) ($w->project_name ?: $w->client_name))) !== '' ? ' (' . e($l) . ')' : '';
        return match ($fase) {
            'recibida' => ["Hemos recibido tu garantía {$w->code}", 'Hemos recibido tu garantía',
                "Ya tenemos tu garantía <b>{$c}</b>{$donde}. En breve empezamos a revisar los dispositivos uno por uno."],
            'presupuesto' => ["Tu garantía {$w->code} está pendiente de presupuesto", 'Tu garantía está pendiente de presupuesto',
                "Hemos revisado los dispositivos de tu garantía <b>{$c}</b>{$donde} y parte de la reparación no la cubre la garantía. Te mandaremos el presupuesto para que nos digas si seguimos."],
            'enviada' => ["Tu garantía {$w->code} está enviada", 'Tu garantía va de vuelta',
                "Ya hemos enviado el material de tu garantía <b>{$c}</b>{$donde}. Te llegará en los próximos días."],
            default => ["Tu garantía {$w->code} está resuelta", 'Tu garantía está resuelta',
                "Hemos terminado con tu garantía <b>{$c}</b>{$donde}. En el portal tienes el resultado de cada dispositivo."],
        };
    }
}
