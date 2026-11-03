<?php

namespace Tests\Feature;

use App\Models\Warranty;
use App\Services\PortalService;
use App\Services\PortalWarranties;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Garantías en el portal público: quien entra con su pase ve las de SU EMPRESA (o solo
 * las suyas si su correo no está en el portal AEME), nunca las de otra; y solicitar una
 * crea solo el ticket (categoría Garantías) más la solicitud con sus fotos.
 */
class PortalWarrantiesTest extends TestCase
{
    use RefreshDatabase;

    private const EMPRESA = 9;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.portal_aeme.host' => '']);   // el portal real nunca
        Storage::fake('local');
        // La empresa del correo sale del portal AEME (solo lectura): aquí, fija.
        $m = \Mockery::mock(PortalWarranties::class, [app(PortalService::class)])->makePartial();
        $m->shouldReceive('company')->andReturnUsing(
            fn ($email) => str_ends_with(mb_strtolower($email), '@empresa.com') ? ['id' => self::EMPRESA, 'name' => 'EMPRESA, S.L.'] : null);
        $this->instance(PortalWarranties::class, $m);
    }

    private function pase(string $email): array
    {
        $token = 'pase-' . md5($email);
        DB::table('portal_sessions')->insert(['token_hash' => hash('sha256', $token), 'email' => $email, 'ip' => '127.0.0.1',
            'expires_at' => now()->addDays(30), 'created_at' => now()]);
        return ['X-Portal-Token' => $token];
    }

    private function garantia(string $code, array $extra = []): Warranty
    {
        return Warranty::create(['code' => $code, 'received_at' => '2026-10-20', 'status' => 'no_iniciado',
            'last_activity_at' => now(), 'status_changed_at' => now()] + $extra);
    }

    public function test_sin_pase_no_hay_nada(): void
    {
        $this->getJson('/api/portal.php?action=warranties')->assertStatus(401);
        $this->postJson('/api/portal.php?action=warranty-request', [])->assertStatus(401);
    }

    public function test_ve_las_de_su_empresa_y_nunca_las_de_otra(): void
    {
        $this->garantia('G-2026-0001', ['portal_client_id' => self::EMPRESA, 'client_name' => 'EMPRESA, S.L.']);
        $this->garantia('G-2026-0002', ['portal_client_id' => 77, 'client_name' => 'OTRA']);
        $h = $this->pase('compi@empresa.com');

        $codes = collect($this->getJson('/api/portal.php?action=warranties', $h)->assertOk()->json('warranties'))->pluck('code')->all();
        $this->assertSame(['G-2026-0001'], $codes);

        $this->getJson('/api/portal.php?action=warranty&code=G-2026-0001', $h)->assertOk();
        $this->getJson('/api/portal.php?action=warranty&code=G-2026-0002', $h)->assertNotFound();
        $this->get('/api/portal.php?action=warranty-pdf&code=G-2026-0002&doc=sticker', $h)->assertNotFound();
    }

    public function test_sin_empresa_solo_ve_las_suyas(): void
    {
        $this->garantia('G-2026-0003', ['customer_email' => 'yo@libre.com']);
        $this->garantia('G-2026-0004', ['portal_client_id' => self::EMPRESA]);
        $h = $this->pase('yo@libre.com');

        $codes = collect($this->getJson('/api/portal.php?action=warranties', $h)->assertOk()->json('warranties'))->pluck('code')->all();
        $this->assertSame(['G-2026-0003'], $codes);
    }

    public function test_solicitar_crea_el_ticket_y_la_solicitud_con_sus_fotos(): void
    {
        $cat = DB::table('ticket_categories')->insertGetId(['key' => 'garantias', 'name' => 'Garantías', 'color' => '#888', 'position' => 1, 'active' => 1]);
        $h = $this->pase('compi@empresa.com');
        $base = ['place_other' => 'Hotel Prueba', 'name' => 'Ana', 'address' => 'Calle 1, Marbella'];

        // Un código que no es de etiqueta ni de antena: no se crea nada.
        $this->postJson('/api/portal.php?action=warranty-request', $base + ['items' => [['code' => 'HOLA']]], $h)->assertStatus(422);
        $this->assertSame(0, DB::table('tickets')->count());

        // Sin decir qué le pasa, o sin explicarlo, tampoco.
        $this->postJson('/api/portal.php?action=warranty-request', $base + ['items' => [['code' => '812345679', 'detail' => 'pantalla en blanco desde el lunes']]], $h)->assertStatus(422);
        $this->postJson('/api/portal.php?action=warranty-request', $base + ['items' => [['code' => '812345679', 'issue' => 'no_enciende', 'detail' => 'no va']]], $h)->assertStatus(422);
        $this->assertSame(0, DB::table('tickets')->count());

        $code = $this->postJson('/api/portal.php?action=warranty-request', $base + ['items' => [
            ['code' => '812345679', 'issue' => 'no_enciende', 'detail' => 'en blanco tras cambiar pilas'],
            ['code' => 'aa:bb:cc:dd:ee:ff', 'issue' => 'sin_conexion', 'detail' => 'las etiquetas de su zona no reciben precios'],
        ]], $h)->assertOk()->json('code');

        $t = DB::table('tickets')->where('code', $code)->first();
        $this->assertSame($cat, (int) $t->category_id);
        $this->assertSame(0, Warranty::count());   // la garantía la crea el equipo
        $r = DB::table('warranty_requests')->where('ticket_id', $t->id)->first();
        $this->assertSame(self::EMPRESA, (int) $r->portal_client_id);
        $items = json_decode($r->items, true);
        $this->assertSame(['812345679', 'AABBCCDDEEFF'], array_column($items, 'code'));
        $this->assertSame(['esl', 'ap'], array_column($items, 'kind'));

        // La solicitud sale en su lista (y en la de un compañero) como «solicitada».
        $fila = collect($this->getJson('/api/portal.php?action=warranties', $this->pase('otro@empresa.com'))->json('warranties'))->firstWhere('code', $code);
        $this->assertSame('solicitada', $fila['phase'] ?? null);

        // Fotos: la frontal de la etiqueta vale; una «trasera sin pilas» de antena, no; y otro correo no puede subir.
        $foto = fn () => UploadedFile::fake()->image('f.jpg', 40, 40);
        $this->post('/api/portal.php?action=warranty-photo', ['ticket' => $code, 'code' => '812345679', 'type' => 'front', 'file' => $foto()], $h)->assertOk();
        $this->post('/api/portal.php?action=warranty-photo', ['ticket' => $code, 'code' => 'AABBCCDDEEFF', 'type' => 'back_no_batteries', 'file' => $foto()], $h)->assertStatus(422);
        $this->post('/api/portal.php?action=warranty-photo', ['ticket' => $code, 'code' => '812345679', 'type' => 'back_batteries', 'file' => $foto()], $this->pase('ajeno@libre.com'))->assertStatus(422);

        $items = json_decode(DB::table('warranty_requests')->where('ticket_id', $t->id)->value('items'), true);
        $this->assertSame(['front'], array_keys($items[0]['photos']));
        $this->assertTrue(DB::table('attachments')->where('id', $items[0]['photos']['front'])->where('ticket_id', $t->id)->exists());

        // Lo que ve el equipo al crear la garantía desde el ticket.
        $this->assertSame('Hotel Prueba', PortalWarranties::forTicket($t->id)['project_name']);
    }

    // Sin empresa en el portal, un id de hotel no cuenta (no es suyo): falta el sitio.
    public function test_sin_lugar_valido_no_se_crea(): void
    {
        $this->postJson('/api/portal.php?action=warranty-request', ['place_id' => 12345, 'name' => 'Ana', 'address' => 'X',
            'items' => [['code' => '812345679', 'issue' => 'no_enciende', 'detail' => 'pantalla en blanco desde el lunes']]], $this->pase('libre@libre.com'))->assertStatus(422);
        $this->assertSame(0, DB::table('tickets')->count());
    }
}
