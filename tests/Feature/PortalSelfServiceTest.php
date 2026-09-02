<?php

namespace Tests\Feature;

use App\Models\EmailAccount;
use App\Services\MailService;
use App\Services\PortalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Autoservicio del portal: descargar el hilo en PDF (siempre anónimo) como constancia,
 * y reenviar al correo dueño el enlace de 24h si el cliente pierde el acceso.
 */
class PortalSelfServiceTest extends TestCase
{
    use RefreshDatabase;

    private function ticket(string $email, string $code): void
    {
        $cid = DB::table('contacts')->insertGetId(['name' => 'C', 'email' => $email]);
        DB::table('tickets')->insert([
            'code' => $code, 'subject' => 'S', 'status' => 'abierto', 'priority' => 'media',
            'channel' => 'email', 'contact_id' => $cid, 'opened_at' => now(), 'last_message_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_pdf_del_portal_exige_acceso_y_genera_pdf(): void
    {
        $email = 'cli@x.com';
        $code = 'TK-PDF1';
        $this->ticket($email, $code);

        // Sin token → 401 (la UI pediría el código).
        $this->getJson("/api/portal.php?action=pdf&code=$code")->assertStatus(401);

        // Con el token del ticket → 200 application/pdf.
        $token = app(PortalService::class)->makeTicketToken($email, $code);
        $r = $this->withHeader('X-Ticket-Token', $token)->get("/api/portal.php?action=pdf&code=$code");
        $r->assertOk();
        $this->assertStringStartsWith('application/pdf', (string) $r->headers->get('content-type'));
    }

    public function test_resend_link_valida_la_entrada_y_limita(): void
    {
        Cache::flush();

        // Formato inválido → 400.
        $this->postJson('/api/portal.php?action=resend-link', ['email' => 'malo', 'code' => ''])->assertStatus(400);

        // Válido → siempre ok (no revela si el ticket existe). 5 permitidos por hora.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/portal.php?action=resend-link', ['email' => 'a@b.com', 'code' => 'TK-X'])->assertOk();
        }
        // El 6º supera el límite → 429.
        $this->postJson('/api/portal.php?action=resend-link', ['email' => 'a@b.com', 'code' => 'TK-X'])->assertStatus(429);
    }

    public function test_el_correo_de_reenvio_lleva_un_enlace_ver_con_token(): void
    {
        $capt = null;
        $this->mock(MailService::class, function ($m) use (&$capt) {
            $m->shouldReceive('sendMail')->andReturnUsing(function (...$a) use (&$capt) { $capt = $a; return 'mid'; });
        });

        $acc = EmailAccount::create(['email' => 'sop@x.com', 'smtp_host' => 'smtp.x.com']);
        $svc = app(PortalService::class);
        $m = new \ReflectionMethod($svc, 'enviarEnlace');
        $m->setAccessible(true);
        $m->invoke($svc, $acc, 'cli@x.com', 'TK-9', 'https://portal.example/?ver=TK-9&t=abc.def');

        $this->assertNotNull($capt, 'no se llamó a sendMail');
        // sendMail(acc, to, name, subject, html): asunto con el código, cuerpo con el enlace.
        $this->assertStringContainsString('TK-9', (string) $capt[3]);
        $this->assertStringContainsString('ver=TK-9', (string) $capt[4]);
    }
}
