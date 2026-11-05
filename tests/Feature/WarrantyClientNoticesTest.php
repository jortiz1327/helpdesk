<?php

namespace Tests\Feature;

use App\Models\EmailAccount;
use App\Models\User;
use App\Models\Warranty;
use App\Services\MailService;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Avisos al cliente por fase: un correo (simulado) al darse de alta y al pasar a Pte.
 * presupuesto, Enviada y Resuelta/Cerrada; una vez por fase, nunca a WhatsApp y nunca
 * fuera de producción salvo que se fuerce (aquí se fuerza).
 */
class WarrantyClientNoticesTest extends TestCase
{
    use RefreshDatabase;

    private array $correos = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.warranty_notices.send' => true, 'app.url' => 'https://soporte.test']);
        EmailAccount::create(['email' => 'soporte@x.com', 'imap_host' => 'imap.x.com', 'imap_user' => 'soporte@x.com',
            'smtp_host' => 'smtp.x.com', 'funcion' => 'soporte', 'active' => true]);
        $this->mock(MailService::class, function ($m) {
            $m->shouldReceive('sendMail')->andReturnUsing(function ($acc, $to, $name, $subject, $html, ...$resto) {
                $this->correos[] = ['to' => $to, 'subject' => $subject, 'html' => $html, 'layout' => $resto[6] ?? []];
                return 'id';
            });
        });
    }

    private function garantia(array $extra = [], string $canal = 'email'): Warranty
    {
        $cid = DB::table('contacts')->insertGetId(['name' => 'Hotel Sol', 'email' => 'Recepcion@HotelSol.com', 'wa_id' => '34600111222']);
        $tid = DB::table('tickets')->insertGetId(['code' => 'TK-2611-0001', 'subject' => 'Etiquetas', 'status' => 'abierto', 'priority' => 'media',
            'channel' => $canal, 'contact_id' => $cid, 'opened_at' => now(), 'last_message_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        return Warranty::create($extra + ['code' => 'G-2026-0001', 'status' => 'no_iniciado', 'received_at' => '2026-11-04', 'client_name' => 'Hotel Sol',
            'ticket_id' => $tid, 'last_activity_at' => now(), 'status_changed_at' => now()]);
    }

    /** Lo que sale «tras la respuesta». */
    private function tras(): void
    {
        $this->app->terminate();
    }

    private function estado(Warranty $w, string $s): void
    {
        $w->forceFill(['status' => $s])->save();
        $this->tras();
    }

    public function test_avisa_en_cada_fase_una_sola_vez(): void
    {
        $w = $this->garantia();
        $this->tras();
        $this->assertSame(['recepcion@hotelsol.com', 'Hemos recibido tu garantía G-2026-0001 [TK-2611-0001]'], [$this->correos[0]['to'], $this->correos[0]['subject']]);
        $this->assertSame('https://soporte.test/garantias?g=G-2026-0001', $this->correos[0]['layout']['cta']['url']);
        $this->assertStringContainsString('(Hotel Sol)', $this->correos[0]['html']);

        $this->estado($w, 'en_curso');            // no es de las que importan
        $this->assertCount(1, $this->correos);
        $this->estado($w, 'pte_presupuesto');
        $this->estado($w, 'enviada');
        $this->estado($w, 'resuelta');
        $this->assertSame(['Hemos recibido', 'Tu garantía G-2026-0001 está pendiente de presupuesto', 'Tu garantía G-2026-0001 está enviada', 'Tu garantía G-2026-0001 está resuelta'],
            [substr($this->correos[0]['subject'], 0, 14), ...array_map(fn ($c) => str_replace(' [TK-2611-0001]', '', $c['subject']), array_slice($this->correos, 1))]);

        // Reabierta y cerrada otra vez: «resuelta» ya se mandó.
        $this->estado($w, 'en_curso');
        $this->estado($w, 'cerrada');
        $this->assertCount(4, $this->correos);
        $this->assertSame(4, DB::table('warranty_events')->where('warranty_id', $w->id)->where('field', 'client_notice')->count());
    }

    public function test_correo_de_la_garantia_y_nunca_whatsapp(): void
    {
        $this->garantia([], 'whatsapp');
        $this->tras();
        $this->assertSame([], $this->correos);   // su ficha tiene correo, pero es un chat

        DB::table('warranties')->delete(); DB::table('tickets')->delete(); DB::table('contacts')->delete();
        $this->garantia(['code' => 'G-2026-0002', 'customer_email' => 'compras@hotelsol.com'], 'whatsapp');
        $this->tras();
        $this->assertSame('compras@hotelsol.com', $this->correos[0]['to']);
    }

    public function test_alta_ya_cerrada_o_cambio_rapido_no_avisa(): void
    {
        $this->garantia(['status' => 'cerrada', 'closed_at' => now()]);
        $this->tras();
        $this->assertSame([], $this->correos);

        // Pasa a Enviada y, antes de que salga el correo, a otro estado: ya no toca.
        $w = Warranty::first();
        $w->forceFill(['status' => 'enviada'])->save();
        $w->forceFill(['status' => 'pte_envio'])->save();
        $this->tras();
        $this->assertSame([], $this->correos);
    }

    public function test_apagado_en_configuracion_o_fuera_de_produccion(): void
    {
        $u = User::create(['name' => 'Ana', 'email' => 'a@x.com', 'password' => bcrypt('x'), 'active' => 1]);
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
        $u->givePermissionTo(['warranties.access', 'warranties.config']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $h = ['X-App-Token' => TokenService::make($u)];
        $this->postJson('/api/warranty_settings.php?action=client_notices_save', ['recibida' => false, 'presupuesto' => true, 'enviada' => true, 'resuelta' => true], $h)->assertOk();
        $d = $this->getJson('/api/warranty_settings.php', $h)->assertOk()->json();
        $this->assertSame(['recibida' => false, 'presupuesto' => true, 'enviada' => true, 'resuelta' => true], $d['client_notices']);
        $this->assertCount(4, $d['client_notice_phases']);

        $w = $this->garantia();
        $this->tras();
        $this->assertSame([], $this->correos);

        config(['services.warranty_notices.send' => false]);
        $this->estado($w, 'enviada');
        $this->assertSame([], $this->correos);
    }
}
