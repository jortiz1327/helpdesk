<?php

namespace App\Http\Controllers;

use App\Models\EmailAccount;
use App\Models\Setting;
use App\Services\HtmlSanitizer;
use App\Services\MailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Webklex\PHPIMAP\ClientManager;

/**
 * Config del CANAL CORREO (buzón de soporte). Vive en «Configuración de soporte».
 * De momento se gestiona UN buzón (el primero). Las contraseñas NO se devuelven al
 * frontend (solo un flag de si están puestas). Requiere support.config.
 */
class EmailAccountsController extends Controller
{
    public function handle(Request $request)
    {
        $action = (string) $request->query('action', '');
        if ($request->isMethod('post')) {
            return match ($action) {
                'test'               => $this->test($request),
                'send_test'          => $this->sendTest($request),
                'quarantine_discard' => $this->quarantineDiscard($request),
                'quarantine_retry'   => $this->quarantineRetry($request),
                default              => $this->save($request),
            };
        }
        if ($action === 'quarantine') return response()->json(['ok' => true, 'items' => $this->quarantineRows()]);
        return $this->get($request);
    }

    /** Cuenta que se gestiona: 'soporte' (buzón de tickets) o 'campanas' (remitente de campañas). */
    protected function funcion(Request $request): string
    {
        return $request->query('funcion') === 'campanas' ? 'campanas' : 'soporte';
    }

    protected function cuenta(string $funcion): ?EmailAccount
    {
        return EmailAccount::where('funcion', $funcion)->orderBy('id')->first();
    }

    /**
     * DIAGNÓSTICO: envía un correo de prueba real con el buzón configurado.
     * «Probar conexión» solo comprueba que el SMTP acepta la contraseña; esto
     * confirma que un correo SALE y LLEGA de verdad al destinatario.
     */
    protected function sendTest(Request $request)
    {
        $acc = EmailAccount::where('funcion', $this->funcion($request))->whereNotNull('smtp_host')->orderBy('id')->first();
        if (!$acc || !$acc->smtp_host) {
            return response()->json(['ok' => false, 'error' => 'Configura y guarda antes el servidor SMTP'], 422);
        }

        $to = trim((string) $request->input('to'));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['ok' => false, 'error' => 'Indica un destinatario válido'], 400);
        }

        $subject = trim((string) $request->input('subject')) ?: 'Correo de prueba del helpdesk';

        // El mensaje llega como texto plano: se escapa y se respetan los saltos de línea.
        $raw  = trim((string) $request->input('body'));
        $body = $raw !== ''
            ? '<p>' . nl2br(e($raw)) . '</p>'
            : '<p>Este es un correo de prueba para comprobar la configuración de correo saliente del helpdesk.</p>';

        try {
            $msgId = app(MailService::class)->sendMail($acc, $to, null, $subject, $body);
            return response()->json(['ok' => true, 'to' => $to, 'message_id' => $msgId]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'No se pudo enviar: ' . $this->shortErr($e)], 502);
        }
    }

    protected function get(Request $request)
    {
        $funcion = $this->funcion($request);
        $a = $this->cuenta($funcion);
        $esSoporte = $funcion === 'soporte';
        return response()->json(['account' => $a ? [
            'id' => $a->id, 'email' => $a->email, 'from_name' => $a->from_name, 'active' => (bool) $a->active,
            'imap_host' => $a->imap_host, 'imap_port' => $a->imap_port, 'imap_encryption' => $a->imap_encryption, 'imap_user' => $a->imap_user,
            'smtp_host' => $a->smtp_host, 'smtp_port' => $a->smtp_port, 'smtp_encryption' => $a->smtp_encryption, 'smtp_user' => $a->smtp_user,
            'has_imap_password' => (bool) $a->imap_password,   // no se envía la contraseña, solo si existe
            'has_smtp_password' => (bool) $a->smtp_password,
            'last_check_at' => $a->last_check_at,
        ] : null,
            // Pie, cuarentena e IMAP son cosa del buzón de SOPORTE. El de campañas es solo SMTP.
            'footer' => $esSoporte ? [
                'active' => (string) Setting::get('email_footer_active', '0') === '1',
                'html'   => (string) Setting::get('email_footer', ''),
            ] : null,
            'quarantine' => $esSoporte ? $this->quarantineRows() : [],
        ]);
    }

    /** Correos EN cuarentena (sin resolver), con el buzón al que pertenecen. */
    protected function quarantineRows()
    {
        return DB::table('email_quarantine as q')
            ->leftJoin('email_accounts as a', 'a.id', '=', 'q.email_account_id')
            ->whereNull('q.resolved_at')
            ->orderByDesc('q.id')
            ->limit(200)
            ->get(['q.id', 'q.uid', 'q.from_email', 'q.from_name', 'q.subject', 'q.error',
                   'q.body_preview', 'q.received_at', 'q.created_at', 'a.email as account_email']);
    }

    /** Descarta un correo en cuarentena (se da por perdido; sigue en el servidor IMAP). */
    protected function quarantineDiscard(Request $request)
    {
        $id = (int) $request->input('id');
        if (!$id) return response()->json(['ok' => false, 'error' => 'Falta el id'], 400);
        DB::table('email_quarantine')->where('id', $id)->whereNull('resolved_at')->update([
            'resolved_at' => now(), 'resolved_by' => $request->user()->id, 'resolution' => 'discarded',
        ]);
        return response()->json(['ok' => true]);
    }

    /** Reintenta procesar un correo en cuarentena (lo vuelve a bajar por IMAP). */
    protected function quarantineRetry(Request $request)
    {
        $id = (int) $request->input('id');
        if (!$id) return response()->json(['ok' => false, 'error' => 'Falta el id'], 400);
        [$ok, $err] = app(MailService::class)->reintentar($id, (int) $request->user()->id);
        return $ok
            ? response()->json(['ok' => true])
            : response()->json(['ok' => false, 'error' => $err], 422);
    }

    protected function save(Request $request)
    {
        $email = trim((string) $request->input('email'));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['ok' => false, 'error' => 'La dirección de correo no es válida'], 400);
        }

        $funcion = $this->funcion($request);
        $a = $this->cuenta($funcion) ?: new EmailAccount();
        $a->funcion    = $funcion;
        $a->email      = $email;
        $a->from_name  = trim((string) $request->input('from_name')) ?: null;
        $a->active     = filter_var($request->input('active', true), FILTER_VALIDATE_BOOLEAN);

        foreach (['imap', 'smtp'] as $p) {
            $a->{"{$p}_host"}       = trim((string) $request->input("{$p}_host")) ?: null;
            $a->{"{$p}_port"}       = (int) $request->input("{$p}_port", $p === 'imap' ? 993 : 465);
            $a->{"{$p}_encryption"} = in_array($request->input("{$p}_encryption"), ['ssl', 'tls', 'none'], true) ? $request->input("{$p}_encryption") : 'ssl';
            $a->{"{$p}_user"}       = trim((string) $request->input("{$p}_user")) ?: null;
            // La contraseña solo se cambia si viene una nueva (vacío = se conserva la que hay).
            $pw = (string) $request->input("{$p}_password");
            if ($pw !== '') $a->{"{$p}_password"} = $pw;
        }
        $a->save();

        // Pie de los correos salientes: se SANEA por lista blanca (acaba en un correo).
        if ($request->has('footer_html')) {
            Setting::put('email_footer', HtmlSanitizer::clean((string) $request->input('footer_html')));
        }
        if ($request->has('footer_active')) {
            Setting::put('email_footer_active', filter_var($request->input('footer_active'), FILTER_VALIDATE_BOOLEAN) ? '1' : '0');
        }

        return response()->json(['ok' => true]);
    }

    /** Prueba de conexión: intenta IMAP y SMTP con lo que hay (o lo que llega en la petición). */
    protected function test(Request $request)
    {
        $a = $this->cuenta($this->funcion($request)) ?: new EmailAccount();
        // Permite probar con datos aún sin guardar; la contraseña, si no viene, usa la guardada.
        $get = fn ($k, $def = null) => $request->filled($k) ? $request->input($k) : ($a->{$k} ?? $def);
        $imapPass = (string) ($request->input('imap_password') ?: $a->imap_password);
        $smtpPass = (string) ($request->input('smtp_password') ?: $a->smtp_password);

        $imap = $this->testImap((string) $get('imap_host'), (int) $get('imap_port', 993), (string) $get('imap_encryption', 'ssl'), (string) $get('imap_user'), $imapPass);
        $smtp = $this->testSmtp((string) $get('smtp_host'), (int) $get('smtp_port', 465), (string) $get('smtp_encryption', 'ssl'), (string) $get('smtp_user'), $smtpPass);

        return response()->json(['ok' => true, 'imap' => $imap, 'smtp' => $smtp]);
    }

    protected function testImap(string $host, int $port, string $enc, string $user, string $pass): array
    {
        if ($host === '' || $user === '') return ['ok' => false, 'error' => 'Faltan host o usuario de IMAP'];
        try {
            $client = (new ClientManager())->make([
                'host' => $host, 'port' => $port,
                'encryption' => $enc === 'none' ? false : $enc,   // 'ssl' | 'tls' | false
                'validate_cert' => false,
                'username' => $user, 'password' => $pass,
                'protocol' => 'imap', 'timeout' => 15,
            ]);
            $client->connect();
            $client->disconnect();
            return ['ok' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $this->shortErr($e)];
        }
    }

    protected function testSmtp(string $host, int $port, string $enc, string $user, string $pass): array
    {
        if ($host === '') return ['ok' => false, 'error' => 'Falta host de SMTP'];
        try {
            // tls=true => TLS implícito (SSL, típico 465); null => STARTTLS/auto (587); false => sin cifrar.
            $tls = $enc === 'ssl' ? true : ($enc === 'none' ? false : null);
            $transport = new EsmtpTransport($host, $port, $tls);
            if ($user !== '') { $transport->setUsername($user); $transport->setPassword($pass); }
            $transport->start();   // conecta + EHLO + (STARTTLS) + AUTH
            $transport->stop();
            return ['ok' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $this->shortErr($e)];
        }
    }

    /** Mensaje de error corto y legible (sin volcar toda la traza). */
    protected function shortErr(\Throwable $e): string
    {
        $m = $e->getMessage();
        return mb_strlen($m) > 160 ? mb_substr($m, 0, 160) . '…' : $m;
    }
}
