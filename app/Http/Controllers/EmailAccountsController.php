<?php

namespace App\Http\Controllers;

use App\Models\EmailAccount;
use App\Models\Setting;
use App\Services\HtmlSanitizer;
use App\Services\MailService;
use Illuminate\Http\Request;
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
        if ($request->isMethod('post') && $request->query('action') === 'test') return $this->test($request);
        if ($request->isMethod('post') && $request->query('action') === 'send_test') return $this->sendTest($request);
        if ($request->isMethod('post')) return $this->save($request);
        return $this->get();
    }

    /**
     * DIAGNÓSTICO: envía un correo de prueba real con el buzón configurado.
     * «Probar conexión» solo comprueba que el SMTP acepta la contraseña; esto
     * confirma que un correo SALE y LLEGA de verdad al destinatario.
     */
    protected function sendTest(Request $request)
    {
        $acc = EmailAccount::query()->whereNotNull('smtp_host')->orderBy('id')->first();
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

    protected function get()
    {
        $a = EmailAccount::query()->orderBy('id')->first();
        return response()->json(['account' => $a ? [
            'id' => $a->id, 'email' => $a->email, 'from_name' => $a->from_name, 'active' => (bool) $a->active,
            'imap_host' => $a->imap_host, 'imap_port' => $a->imap_port, 'imap_encryption' => $a->imap_encryption, 'imap_user' => $a->imap_user,
            'smtp_host' => $a->smtp_host, 'smtp_port' => $a->smtp_port, 'smtp_encryption' => $a->smtp_encryption, 'smtp_user' => $a->smtp_user,
            'has_imap_password' => (bool) $a->imap_password,   // no se envía la contraseña, solo si existe
            'has_smtp_password' => (bool) $a->smtp_password,
            'last_check_at' => $a->last_check_at,
        ] : null,
            // Pie que se añade a los correos que salen (respuestas y avisos).
            'footer' => [
                'active' => (string) Setting::get('email_footer_active', '0') === '1',
                'html'   => (string) Setting::get('email_footer', ''),
            ],
        ]);
    }

    protected function save(Request $request)
    {
        $email = trim((string) $request->input('email'));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['ok' => false, 'error' => 'La dirección de correo no es válida'], 400);
        }

        $a = EmailAccount::query()->orderBy('id')->first() ?: new EmailAccount();
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
        $a = EmailAccount::query()->orderBy('id')->first() ?: new EmailAccount();
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
