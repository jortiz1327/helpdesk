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
            // El aviso de asignación respeta la preferencia del agente (notify_assigned).
            $bloquea = $tpl->key === 'ticket_assigned'
                && property_exists($t, 'agent_notify_assigned') && !$t->agent_notify_assigned;
            if (!$bloquea) $out[mb_strtolower($t->agent_email)] = $t->agent_name;
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
     * Destinatarios de un aviso de SLA. Como los normales, pero (1) NUNCA se avisa al
     * cliente de un retraso interno, y (2) solo a usuarios que aceptan avisos de SLA
     * (`users.notify_sla`). El «escalado» del vencido a los administradores se decide
     * en la propia plantilla (recipients.admins), no aquí.
     */
    protected function destinatariosSla(EmailTemplate $tpl, object $t): array
    {
        $r = $tpl->recipients ?: ['agent' => true];
        $out = [];

        if (!empty($r['agent']) && $t->agent_email && $t->agent_notify) {
            $out[mb_strtolower($t->agent_email)] = $t->agent_name;
        }
        if (!empty($r['category']) && $t->category_id) {
            $agentes = DB::table('user_ticket_categories as uc')
                ->join('users as u', 'u.id', '=', 'uc.user_id')
                ->where('uc.category_id', $t->category_id)
                ->where('u.notify_sla', true)
                ->whereNotNull('u.email')
                ->get(['u.email', 'u.name']);
            foreach ($agentes as $a) $out[mb_strtolower($a->email)] = $a->name;
        }
        if (!empty($r['admins'])) {
            foreach (User::where('notify_sla', true)->whereNotNull('email')->get() as $u) {
                if ($u->can('support.config')) $out[mb_strtolower($u->email)] = $u->name;
            }
        }

        return $out;
    }

    /**
     * Aviso de SLA de un ticket (por vencer / vencido). Mismo mecanismo que ticket(),
     * pero con destinatarios internos filtrados por `notify_sla` y variables propias
     * del reloj: {{reloj}} {{vence}} {{retraso}}.
     * $key: sla_warning | sla_breach
     */
    public function slaAlert(string $key, int $ticketId, array $extraVars = []): bool
    {
        try {
            $tpl = EmailTemplate::where('key', $key)->where('active', true)->first();
            if (!$tpl) return false;   // desactivada: no se avisa

            $t = DB::table('tickets as t')
                ->leftJoin('contacts as c', 'c.id', '=', 't.contact_id')
                ->leftJoin('users as u', 'u.id', '=', 't.assigned_to')
                ->where('t.id', $ticketId)
                ->first(['t.code', 't.subject', 't.status', 't.category_id', 't.assigned_to',
                         'c.name as contact_name', 'u.name as agent_name', 'u.email as agent_email',
                         'u.notify_sla as agent_notify']);
            if (!$t) return false;

            $destinos = $this->destinatariosSla($tpl, $t);
            if (!$destinos) return false;   // nadie que reciba avisos de SLA

            $acc = EmailAccount::where('active', true)->whereNotNull('smtp_host')->orderBy('id')->first();
            if (!$acc) return false;

            $vars = [
                '{{codigo}}'  => (string) $t->code,
                '{{asunto}}'  => (string) $t->subject,
                '{{cliente}}' => (string) ($t->contact_name ?: 'cliente'),
                '{{agente}}'  => (string) ($t->agent_name ?: 'equipo'),
                '{{estado}}'  => (string) (TicketService::STATUSES[$t->status] ?? $t->status),
                '{{soporte}}' => (string) ($acc->from_name ?: $acc->email),
            ] + $extraVars;

            $subject = strtr($tpl->subject, $vars);
            $body    = strtr($tpl->body, $vars);
            if (stripos($subject, (string) $t->code) === false) $subject .= ' [' . $t->code . ']';

            $enviados = 0;
            foreach ($destinos as $email => $nombre) {
                try {
                    $this->mail->sendMail($acc, $email, $nombre, $subject, $body);
                    $enviados++;
                } catch (\Throwable $e) {
                    Log::warning('NotifyService SLA: destinatario falló', ['key' => $key, 'to' => $email, 'error' => $e->getMessage()]);
                }
            }
            return $enviados > 0;
        } catch (\Throwable $e) {
            Log::warning('NotifyService SLA: no se pudo enviar', ['key' => $key, 'ticket' => $ticketId, 'error' => $e->getMessage()]);
            return false;
        }
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
                         'u.name as agent_name', 'u.email as agent_email', 'u.notify_assigned as agent_notify_assigned']);
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
