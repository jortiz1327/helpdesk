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
        // Administradores = quienes pueden configurar el soporte. Se resuelven por
        // rol/permiso en una consulta, sin recorrer TODOS los usuarios llamando a can().
        if (!empty($r['admins'])) {
            foreach (User::whereIn('id', $this->adminUserIds())->whereNotNull('email')->get(['email', 'name']) as $u) {
                $out[mb_strtolower($u->email)] = $u->name;
            }
        }

        return $out;
    }

    /**
     * IDs de los usuarios que pueden configurar el soporte, en una consulta por
     * rol/permiso: los que tienen el permiso `support.config` (directo o por rol) MÁS
     * los superadministradores (que lo tienen por bypass de Gate, sin permiso explícito,
     * así que `permission('support.config')` no los incluiría). Evita el `User::all()`
     * + `can()` uno a uno en cada aviso.
     */
    protected function adminUserIds(): \Illuminate\Support\Collection
    {
        $super = config('rbac.super_role', 'superadmin');

        return User::role($super)->pluck('id')
            ->merge(User::permission('support.config')->pluck('id'))
            ->unique()->values();
    }

    /**
     * Avisos que NO deben invitar a «responder a este correo»: los internos (a
     * agentes/admins) y el CSAT (se valora con las estrellas, no respondiendo; un «3»
     * de respuesta reabriría la incidencia resuelta).
     */
    protected const SIN_NOTA_RESPONDER = ['sla_warning', 'sla_breach', 'ticket_assigned', 'csat_survey'];

    /** Titular de la plantilla de marca según el tipo de aviso (envuelve el cuerpo). */
    protected function encabezado(string $key): string
    {
        return [
            'ticket_created'  => 'Hemos recibido tu incidencia',
            'ticket_closed'   => 'Tu incidencia se ha cerrado',
            'ticket_assigned' => 'Se te ha asignado un ticket',
            'sla_warning'     => 'Un ticket está por vencer',
            'sla_breach'      => 'Un ticket ha vencido su SLA',
            'csat_survey'     => '¿Cómo lo hemos hecho?',
        ][$key] ?? 'Aviso de soporte';
    }

    /** Datos de la plantilla de marca para un aviso: titular + «código · asunto». */
    protected function marco(string $key, object $t): array
    {
        $m = [
            'heading' => $this->encabezado($key),
            'meta'    => trim(((string) $t->code) . ($t->subject ? ' · ' . (string) $t->subject : '')),
        ];
        if (in_array($key, self::SIN_NOTA_RESPONDER, true)) $m['note'] = '';
        return $m;
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
            foreach (User::whereIn('id', $this->adminUserIds())
                ->where('notify_sla', true)->whereNotNull('email')->get(['email', 'name']) as $u) {
                $out[mb_strtolower($u->email)] = $u->name;
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

            $marco = $this->marco($key, $t);
            $enviados = 0;
            foreach ($destinos as $email => $nombre) {
                try {
                    $this->mail->sendMail($acc, $email, $nombre, $subject, $body, [], null, [], [], [], null, $marco);
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
     * Encuesta de satisfacción (CSAT) al resolver/cerrar un ticket. Los tickets son de
     * correo/web, así que la encuesta va por CORREO (estrellas clicables). Mismo almacén
     * de valoración (ticket_ratings): sale igual en la ficha y en Informes.
     */
    public function csat(int $ticketId): bool
    {
        return $this->csatEmail($ticketId);
    }

    /**
     * CSAT por correo: manda un correo al cliente con 5 estrellas clicables (enlace
     * FIRMADO, 1-clic, sin login). Solo si: plantilla csat_survey activa + csat_active +
     * el cliente tiene correo + el ticket está resuelto/cerrado + aún NO se ha valorado.
     * Devuelve true si se envió.
     */
    private function csatEmail(int $ticketId): bool
    {
        try {
            if ((string) \App\Models\Setting::get('csat_active', '1') !== '1') return false;

            $tpl = EmailTemplate::where('key', 'csat_survey')->where('active', true)->first();
            if (!$tpl) return false;

            $t = DB::table('tickets as t')
                ->leftJoin('contacts as c', 'c.id', '=', 't.contact_id')
                ->where('t.id', $ticketId)
                ->first(['t.code', 't.subject', 't.status', 'c.name as contact_name', 'c.email as contact_email']);
            if (!$t || !$t->contact_email) return false;
            if (!in_array($t->status, ['resuelto', 'cerrado'], true)) return false;

            // No repetir si ya valoró.
            if (DB::table('ticket_ratings')->where('ticket_id', $ticketId)->exists()) return false;

            $acc = EmailAccount::where('active', true)->whereNotNull('smtp_host')->orderBy('id')->first();
            if (!$acc) return false;

            $vars = [
                '{{codigo}}'     => (string) $t->code,
                '{{asunto}}'     => (string) $t->subject,
                '{{cliente}}'    => (string) ($t->contact_name ?: 'cliente'),
                '{{soporte}}'    => (string) ($acc->from_name ?: $acc->email),
                '{{valoracion}}' => $this->estrellasCsat($ticketId),
            ];
            $subject = strtr($tpl->subject, $vars);
            $body    = strtr($tpl->body, $vars);

            $this->mail->sendMail($acc, mb_strtolower($t->contact_email), $t->contact_name, $subject, $body,
                [], null, [], [], [], null, $this->marco('csat_survey', $t));
            return true;
        } catch (\Throwable $e) {
            Log::warning('NotifyService CSAT: no se pudo enviar', ['ticket' => $ticketId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /** HTML de las 5 estrellas clicables (cada una un enlace firmado a su nota). */
    private function estrellasCsat(int $ticketId): string
    {
        $base = rtrim((string) config('app.url'), '/');
        $exp  = now()->addDays(30);

        $stars = '<table role="presentation" cellpadding="0" cellspacing="0"><tr>';
        for ($n = 1; $n <= 5; $n++) {
            $url = $base . \Illuminate\Support\Facades\URL::signedRoute('portal.rate', ['ticket' => $ticketId, 'score' => $n], $exp, false);
            $stars .= '<td style="padding:0 4px;"><a href="' . e($url) . '" '
                . 'style="display:inline-block;text-decoration:none;font-size:26px;line-height:1;color:#e0a63a;" '
                . 'title="' . $n . ' de 5">&#9733;</a></td>';
        }
        $stars .= '</tr></table>';
        $stars .= '<div style="font-size:12px;color:#888;margin-top:4px;">1 = muy insatisfecho · 5 = muy satisfecho</div>';
        return $stars;
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
            $marco = $this->marco($key, $t);
            $enviados = 0;
            foreach ($destinos as $email => $nombre) {
                try {
                    $this->mail->sendMail($acc, $email, $nombre, $subject, $body, [], null, [], [], [], null, $marco);
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
