<?php

namespace App\Services;

use App\Models\EmailAccount;
use App\Models\EmailTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Avisos automáticos por correo a partir de PLANTILLAS (ticket creado, cerrado,
 * asignado). Cada plantilla se puede activar o desactivar; si está desactivada,
 * no se manda nada.
 *
 * REGLA DE ORO: un aviso NUNCA puede tumbar la operación que lo dispara. Si algo
 * falla (sin buzón, SMTP caído, destinatario sin correo), se registra y se sigue:
 * el ticket ya se creó/cerró/asignó, que es lo importante.
 */
class NotifyService
{
    public function __construct(protected MailService $mail) {}

    /**
     * Resuelve a QUIÉNES hay que avisar según la plantilla. Devuelve correo => nombre,
     * sin repetidos (si alguien cae por dos vías, recibe un solo correo).
     */
    protected function destinatarios(EmailTemplate $tpl, object $t, int $ticketId): array
    {
        $r = $tpl->recipients ?: ['client' => true];
        $out = [];

        if (!empty($r['client']) && $t->contact_email) {
            $out[mb_strtolower($t->contact_email)] = $t->contact_name;
        }
        if (!empty($r['agent']) && $t->agent_email) {
            $out[mb_strtolower($t->agent_email)] = $t->agent_name;
        }
        // Agentes del ÁREA del ticket (los «miembros del departamento» de osTicket).
        if (!empty($r['category'])) {
            $catId = DB::table('tickets')->where('id', $ticketId)->value('category_id');
            if ($catId) {
                $agentes = DB::table('user_ticket_categories as uc')
                    ->join('users as u', 'u.id', '=', 'uc.user_id')
                    ->where('uc.category_id', $catId)
                    ->whereNotNull('u.email')
                    ->get(['u.email', 'u.name']);
                foreach ($agentes as $a) $out[mb_strtolower($a->email)] = $a->name;
            }
        }
        // Administradores = quienes pueden configurar el soporte.
        if (!empty($r['admins'])) {
            foreach (User::all() as $u) {
                if ($u->email && $u->can('support.config')) $out[mb_strtolower($u->email)] = $u->name;
            }
        }

        return $out;
    }

    /**
     * Envía el aviso de un evento sobre un ticket. Devuelve true si se llegó a enviar.
     * $key: ticket_created | ticket_closed | ticket_assigned
     */
    public function ticket(string $key, int $ticketId): bool
    {
        try {
            $tpl = EmailTemplate::where('key', $key)->where('active', true)->first();
            if (!$tpl) return false;   // desactivada o inexistente: no se avisa

            $t = DB::table('tickets as t')
                ->leftJoin('contacts as c', 'c.id', '=', 't.contact_id')
                ->leftJoin('users as u', 'u.id', '=', 't.assigned_to')
                ->where('t.id', $ticketId)
                ->first(['t.code', 't.subject', 't.status', 'c.name as contact_name', 'c.email as contact_email',
                         'u.name as agent_name', 'u.email as agent_email']);
            if (!$t) return false;

            // ¿A quién se avisa? Lo dice la plantilla (cliente, agente, área, admins).
            $destinos = $this->destinatarios($tpl, $t, $ticketId);
            if (!$destinos) return false;   // nadie a quien avisar (p. ej. contacto solo de WhatsApp)

            $acc = EmailAccount::where('active', true)->whereNotNull('smtp_host')->orderBy('id')->first();
            if (!$acc) return false;

            $vars = [
                '{{codigo}}'  => (string) $t->code,
                '{{asunto}}'  => (string) $t->subject,
                '{{cliente}}' => (string) ($t->contact_name ?: 'cliente'),
                '{{agente}}'  => (string) ($t->agent_name ?: 'equipo'),
                '{{estado}}'  => (string) (TicketService::STATUSES[$t->status] ?? $t->status),
                '{{soporte}}' => (string) ($acc->from_name ?: $acc->email),
            ];
            $subject = strtr($tpl->subject, $vars);
            $body    = strtr($tpl->body, $vars);

            // El código en el asunto mantiene el hilo: si el cliente responde al aviso,
            // su respuesta vuelve a ESTE ticket (lo casa MailService::ticketByCode).
            if (stripos($subject, (string) $t->code) === false) $subject .= ' [' . $t->code . ']';

            // Un correo por destinatario: cada uno recibe el suyo, sin ver a los demás.
            $enviados = 0;
            foreach ($destinos as $email => $nombre) {
                try {
                    $this->mail->sendMail($acc, $email, $nombre, $subject, $body);
                    $enviados++;
                } catch (\Throwable $e) {
                    Log::warning('NotifyService: destinatario falló', ['key' => $key, 'to' => $email, 'error' => $e->getMessage()]);
                }
            }
            return $enviados > 0;
        } catch (\Throwable $e) {
            Log::warning('NotifyService: no se pudo enviar el aviso', ['key' => $key, 'ticket' => $ticketId, 'error' => $e->getMessage()]);
            return false;
        }
    }
}
