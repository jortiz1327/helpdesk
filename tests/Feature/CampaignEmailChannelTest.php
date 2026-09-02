<?php

namespace Tests\Feature;

use App\Models\EmailAccount;
use App\Services\CampaignService;
use App\Services\MailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Campañas por CORREO. El envío usa el remitente de FUNCIÓN 'campanas' (SMTP aparte del
 * buzón de soporte). Se comprueba: que envía a los contactos con correo, que respeta las
 * BAJAS al momento del envío, que sin remitente no manda nada (deja pendiente), y que el
 * enlace de baja del pie da de baja al contacto.
 */
class CampaignEmailChannelTest extends TestCase
{
    use RefreshDatabase;

    private function remitenteCampanas(): void
    {
        EmailAccount::create([
            'funcion' => 'campanas', 'email' => 'campanas@x.com', 'from_name' => 'X',
            'active' => 1, 'smtp_host' => 'smtp.x.com', 'smtp_port' => 465,
            'smtp_encryption' => 'ssl', 'smtp_user' => 'campanas@x.com', 'smtp_password' => 'secret',
        ]);
    }

    private function campanaCorreo(array $recipients): int
    {
        $cid = DB::table('campaigns')->insertGetId([
            'channel' => 'email', 'title' => 'Boletín', 'subject' => 'Hola', 'body_html' => '<p>Contenido</p>',
            'template_name' => null, 'language' => 'es', 'components' => '[]',
            'status' => 'sending', 'total' => count($recipients), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('campaign_recipients')->insert(array_map(fn ($r) => [
            'campaign_id' => $cid, 'wa_id' => null, 'email' => $r['email'], 'name' => $r['name'] ?? null, 'status' => 'pending',
        ], $recipients));
        return $cid;
    }

    public function test_envia_por_correo_y_respeta_las_bajas(): void
    {
        $this->remitenteCampanas();
        DB::table('contacts')->insert([
            ['name' => 'Ana',  'wa_id' => '1', 'email' => 'ana@x.com',  'opted_out' => 0],
            ['name' => 'Baja', 'wa_id' => '2', 'email' => 'baja@x.com', 'opted_out' => 1],
        ]);

        $cid = $this->campanaCorreo([
            ['email' => 'ana@x.com', 'name' => 'Ana'],
            ['email' => 'baja@x.com', 'name' => 'Baja'],
        ]);

        $enviados = [];
        $this->mock(MailService::class, function ($m) use (&$enviados) {
            $m->shouldReceive('sendMail')->andReturnUsing(function ($acc, $to) use (&$enviados) {
                $enviados[] = $to;
                return 'mid_' . $to;
            });
        });

        app(CampaignService::class)->process($cid, 30);

        $this->assertContains('ana@x.com', $enviados);
        $this->assertNotContains('baja@x.com', $enviados, 'se envió a un contacto dado de baja');
        $this->assertSame('sent', DB::table('campaign_recipients')->where('email', 'ana@x.com')->value('status'));
        $this->assertSame('skipped', DB::table('campaign_recipients')->where('email', 'baja@x.com')->value('status'));
    }

    public function test_incluye_la_cabecera_list_unsubscribe_y_el_pie_de_baja(): void
    {
        $this->remitenteCampanas();
        $id = DB::table('contacts')->insertGetId(['name' => 'Ana', 'wa_id' => '1', 'email' => 'ana@x.com', 'opted_out' => 0]);
        $cid = $this->campanaCorreo([['email' => 'ana@x.com', 'name' => 'Ana']]);

        $capt = [];
        $this->mock(MailService::class, function ($m) use (&$capt) {
            $m->shouldReceive('sendMail')->andReturnUsing(function (...$args) use (&$capt) {
                $capt['html']  = $args[4] ?? '';
                $capt['extra'] = $args[12] ?? [];   // 13.º posicional = $extraHeaders
                return 'mid';
            });
        });

        app(CampaignService::class)->process($cid, 30);

        // Cabecera List-Unsubscribe con el enlace firmado + one-click.
        $this->assertArrayHasKey('List-Unsubscribe', $capt['extra']);
        $this->assertStringContainsString('/api/campaign_unsubscribe/' . $id, $capt['extra']['List-Unsubscribe']);
        $this->assertSame('List-Unsubscribe=One-Click', $capt['extra']['List-Unsubscribe-Post'] ?? null);
        // Y el pie de baja en el propio cuerpo.
        $this->assertStringContainsString('Cancelar la suscripción', $capt['html']);
    }

    public function test_sin_remitente_de_campanas_no_envia_nada(): void
    {
        // No se crea la cuenta 'campanas'.
        DB::table('contacts')->insert([['name' => 'Ana', 'wa_id' => '1', 'email' => 'ana@x.com', 'opted_out' => 0]]);
        $cid = $this->campanaCorreo([['email' => 'ana@x.com', 'name' => 'Ana']]);

        // No debe intentar enviar (si lo intentara, MailService real fallaría por SMTP inexistente).
        $mock = $this->mock(MailService::class);
        $mock->shouldNotReceive('sendMail');

        [$sent, $failed, $pending] = app(CampaignService::class)->process($cid, 30);

        $this->assertSame(0, $sent);
        $this->assertSame(1, $pending);
        $this->assertSame('pending', DB::table('campaign_recipients')->where('email', 'ana@x.com')->value('status'));
    }

    public function test_no_usa_el_buzon_de_soporte_como_remitente(): void
    {
        // Solo hay cuenta de SOPORTE: una campaña de correo NO debe salir por ella.
        EmailAccount::create([
            'funcion' => 'soporte', 'email' => 'soporte@x.com', 'active' => 1,
            'smtp_host' => 'smtp.x.com', 'smtp_port' => 465, 'smtp_encryption' => 'ssl',
            'smtp_user' => 'soporte@x.com', 'smtp_password' => 'secret',
        ]);
        DB::table('contacts')->insert([['name' => 'Ana', 'wa_id' => '1', 'email' => 'ana@x.com', 'opted_out' => 0]]);
        $cid = $this->campanaCorreo([['email' => 'ana@x.com', 'name' => 'Ana']]);

        $mock = $this->mock(MailService::class);
        $mock->shouldNotReceive('sendMail');

        [$sent, , $pending] = app(CampaignService::class)->process($cid, 30);
        $this->assertSame(0, $sent);
        $this->assertSame(1, $pending);
    }

    public function test_el_enlace_de_baja_da_de_baja_al_contacto(): void
    {
        $id = DB::table('contacts')->insertGetId(['name' => 'Ana', 'wa_id' => '1', 'email' => 'ana@x.com', 'opted_out' => 0]);
        $url = URL::signedRoute('campaign.unsubscribe', ['contact' => $id], null, false);

        $this->get($url)->assertOk()->assertSee('baja', false);

        $this->assertSame(1, (int) DB::table('contacts')->where('id', $id)->value('opted_out'));
    }

    public function test_el_enlace_de_baja_sin_firma_valida_se_rechaza(): void
    {
        $id = DB::table('contacts')->insertGetId(['name' => 'Ana', 'wa_id' => '1', 'email' => 'ana@x.com', 'opted_out' => 0]);
        // Sin firma → 403 (InvalidSignatureException del middleware 'signed').
        $this->get('/api/campaign_unsubscribe/' . $id)->assertForbidden();
        $this->assertSame(0, (int) DB::table('contacts')->where('id', $id)->value('opted_out'));
    }
}
