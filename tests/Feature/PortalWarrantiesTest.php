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

    public function test_ya_probo_a_reiniciarla_queda_apuntado(): void
    {
        DB::table('ticket_categories')->insert(['key' => 'garantias', 'name' => 'Garantías', 'color' => '#888', 'position' => 1, 'active' => 1]);
        $zk = \Mockery::mock(\App\Services\WarrantyLabels::class);
        $zk->shouldReceive('existence')->andReturnUsing(fn ($codes) => array_fill_keys($codes, true));
        $this->instance(\App\Services\WarrantyLabels::class, $zk);
        $code = $this->postJson('/api/portal.php?action=warranty-request', ['place_other' => 'Hotel Prueba', 'name' => 'Ana', 'address' => 'Calle 1', 'items' => [
            ['code' => '812345679', 'issue' => 'bateria', 'tried' => true],
            ['code' => '812345680', 'issue' => 'no_enciende'],
            ['code' => '812345681', 'issue' => 'pantalla', 'tried' => true],          // no es de las que se prueban
            ['code' => 'aa:bb:cc:dd:ee:ff', 'issue' => 'no_enciende', 'tried' => true], // una antena no lleva pilas
        ]], $h = $this->pase('compi@empresa.com'))->assertOk()->json('code');

        $tid = DB::table('tickets')->where('code', $code)->value('id');
        $cuerpo = DB::table('messages')->where('ticket_id', $tid)->value('body');
        $this->assertStringContainsString('812345679: Batería (ya probó a reiniciarla y con otras pilas)', $cuerpo);
        $this->assertSame(1, substr_count($cuerpo, 'ya probó'));
        $items = collect(json_decode(DB::table('warranty_requests')->where('ticket_id', $tid)->value('items'), true))->keyBy('code');
        $this->assertSame([true, false, false, false], $items->pluck('tried')->values()->all());

        // La próxima vez, el formulario propone dónde pidió (la más reciente primero, sin repetir).
        $otra = fn ($sitio, $code) => $this->postJson('/api/portal.php?action=warranty-request', ['place_other' => $sitio, 'name' => 'Ana', 'address' => 'Calle 1',
            'items' => [['code' => $code, 'issue' => 'sin_conexion']]], $h)->assertOk();
        $otra('Hotel Luna', '812345690');
        $otra('Hotel Prueba', '812345691');
        $this->assertSame([['id' => null, 'name' => 'Hotel Prueba'], ['id' => null, 'name' => 'Hotel Luna']],
            $this->getJson('/api/portal.php?action=warranty-places', $h)->assertOk()->json('recent'));
        $this->assertSame([], $this->getJson('/api/portal.php?action=warranty-places', $this->pase('nueva@empresa.com'))->json('recent'));
    }

    public function test_lo_que_ya_esta_en_curso_no_se_pide_otra_vez(): void
    {
        DB::table('ticket_categories')->insert(['key' => 'garantias', 'name' => 'Garantías', 'color' => '#888', 'position' => 1, 'active' => 1]);
        $zk = \Mockery::mock(\App\Services\WarrantyLabels::class);
        $zk->shouldReceive('existence')->andReturnUsing(fn ($codes) => array_fill_keys($codes, true));
        $this->instance(\App\Services\WarrantyLabels::class, $zk);
        $item = fn ($w, $c) => \App\Models\WarrantyItem::create(['warranty_id' => $w->id, 'code' => $c, 'kind' => 'esl', 'lookup_state' => 'found']);
        $item($this->garantia('G-2026-0001', ['portal_client_id' => self::EMPRESA]), '812345601');   // suya, abierta
        $item($this->garantia('G-2026-0002', ['portal_client_id' => 77]), '812345602');              // de otra empresa
        $item(tap($this->garantia('G-2026-0003', ['portal_client_id' => self::EMPRESA]))->update(['status' => Warranty::STATUS_CLOSED]), '812345603'); // cerrada
        $h = $this->pase('compi@empresa.com');

        // Una solicitud suya, aún sin pasar a garantía.
        $tk = $this->postJson('/api/portal.php?action=warranty-request', ['place_other' => 'Hotel Prueba', 'name' => 'Ana', 'address' => 'Calle 1',
            'items' => [['code' => '812345604', 'issue' => 'bateria', 'tried' => true]]], $h)->assertOk()->json('code');

        $this->postJson('/api/portal.php?action=warranty-check', ['codes' => ['812345601', '812345602', '812345603', '812345604', '812345605']], $h)->assertOk()
            ->assertJsonPath('busy', fn ($b) => $b == ['812345601' => 'G-2026-0001', '812345602' => '', '812345604' => $tk]);

        $pedir = fn ($c) => $this->postJson('/api/portal.php?action=warranty-request', ['place_other' => 'Hotel Prueba', 'name' => 'Ana', 'address' => 'Calle 1',
            'items' => [['code' => $c, 'issue' => 'sin_conexion']]], $h);
        $pedir('812345601')->assertStatus(422)->assertJsonPath('error', '«812345601» ya está en G-2026-0001, que sigue en curso: quítalo de esta solicitud');
        $pedir('812345602')->assertStatus(422)->assertJsonPath('error', '«812345602» ya está en otra garantía en curso: quítalo de esta solicitud');
        $pedir('812345604')->assertStatus(422);
        $this->assertSame(1, DB::table('warranty_requests')->count());

        // Cerrada la garantía o resuelta la solicitud, se puede volver a pedir.
        $pedir('812345603')->assertOk();
        DB::table('tickets')->where('code', $tk)->update(['status' => 'resuelto']);
        $pedir('812345604')->assertOk();
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

    /**
     * Al cliente solo se le dice la batería, y solo si en su última conexión estaba por debajo
     * del 90 %: sale de lo que se acaba de comprobar (en caché), sin otra consulta a ZKONG.
     */
    public function test_el_portal_avisa_de_la_bateria_baja(): void
    {
        $zk = \Mockery::mock(\App\Services\WarrantyLabels::class);
        $zk->shouldReceive('existence')->andReturnUsing(function ($codes) {
            $bat = ['812345601' => 72, '812345602' => 95, '812345603' => 8];
            foreach ($codes as $c) {
                \Illuminate\Support\Facades\Cache::put("warranty_label:{$c}", ['lookup_state' => 'found', 'kind' => 'esl', 'battery' => $bat[$c] ?? null], 60);
            }
            return array_fill_keys($codes, true);
        });
        $this->instance(\App\Services\WarrantyLabels::class, $zk);
        $h = $this->pase('compi@empresa.com');

        $this->postJson('/api/portal.php?action=warranty-check', ['codes' => ['812345601', '812345602', '812345603', '812345604']], $h)->assertOk()
            ->assertJsonPath('battery', ['812345601' => 72, '812345603' => 8]);   // 95 % y sin dato: nada
    }

    public function test_un_codigo_que_no_esta_en_los_servidores_hay_que_confirmarlo(): void
    {
        DB::table('ticket_categories')->insert(['key' => 'garantias', 'name' => 'Garantías', 'color' => '#888', 'position' => 1, 'active' => 1]);
        // Los servidores ZKONG: 812345679 existe, 999999999 no.
        $zk = \Mockery::mock(\App\Services\WarrantyLabels::class);
        $zk->shouldReceive('existence')->andReturnUsing(fn ($codes) => collect($codes)->mapWithKeys(fn ($c) => [$c => $c === '812345679'])->all());
        $this->instance(\App\Services\WarrantyLabels::class, $zk);
        $h = $this->pase('compi@empresa.com');

        $this->postJson('/api/portal.php?action=warranty-check', ['codes' => ['812345679', '999999999', 'HOLA']], $h)->assertOk()
            ->assertExactJson(['ok' => true, 'exists' => ['812345679' => true, '999999999' => false], 'busy' => [], 'battery' => []]);

        $base = ['place_other' => 'Hotel Prueba', 'name' => 'Ana', 'address' => 'Calle 1, Marbella'];
        $item = ['code' => '999999999', 'issue' => 'no_enciende'];
        // Sin confirmar, no entra.
        $this->postJson('/api/portal.php?action=warranty-request', $base + ['items' => [$item]], $h)->assertStatus(422);
        $this->assertSame(0, DB::table('tickets')->count());

        // Confirmado («Añadir igualmente»): entra, y el equipo lo ve marcado.
        $code = $this->postJson('/api/portal.php?action=warranty-request', $base + ['items' => [$item + ['forced' => true], ['code' => '812345679', 'issue' => 'bateria']]], $h)
            ->assertOk()->json('code');
        $tid = DB::table('tickets')->where('code', $code)->value('id');
        $cuerpo = DB::table('messages')->where('ticket_id', $tid)->value('body');
        $this->assertStringContainsString('999999999: No enciende / pantalla en blanco [NO APARECE EN LOS SERVIDORES', $cuerpo);
        $this->assertStringNotContainsString('812345679: Batería [', $cuerpo);
        $items = collect(json_decode(DB::table('warranty_requests')->where('ticket_id', $tid)->value('items'), true))->keyBy('code');
        $this->assertFalse($items['999999999']['exists']);
        $this->assertTrue($items['812345679']['exists']);

        // Mientras está abierta: Solicitada → Recibida → En revisión → Resuelta (sin fabricante).
        $fila = fn () => collect($this->getJson('/api/portal.php?action=warranties', $h)->json('warranties'))->firstWhere('code', $code);
        $this->assertSame(['solicitada', 'recibida', 'revision', 'resuelta'], array_column($fila()['steps'], 'key'));
        // Resuelta sin pedir el material (p. ej. cambiar pilas): de Solicitada a Resuelta.
        DB::table('tickets')->where('id', $tid)->update(['status' => 'resuelto']);
        $this->assertSame('resuelta', $fila()['phase']);
        $this->assertSame(['solicitada', 'resuelta'], array_column($fila()['steps'], 'key'));
        $this->assertSame('resuelta', $this->getJson('/api/portal.php?action=warranty&code=' . $code, $h)->json('warranty.phase'));
    }

    // Comprobar códigos pregunta a ZKONG: lo ya sabido no cuenta, lo nuevo tiene cupo.
    public function test_comprobar_codigos_tiene_cupo(): void
    {
        $zk = \Mockery::mock(\App\Services\WarrantyLabels::class);
        $zk->shouldReceive('existence')->andReturnUsing(fn ($codes) => array_fill_keys($codes, true));
        $this->instance(\App\Services\WarrantyLabels::class, $zk);
        \Illuminate\Support\Facades\Cache::put('warranty_label:812345679', ['lookup_state' => 'found'], 60);
        $h = $this->pase('compi@empresa.com');

        \Illuminate\Support\Facades\RateLimiter::increment('wcheck-m:' . sha1('compi@empresa.com'), 60, 120);
        $this->postJson('/api/portal.php?action=warranty-check', ['codes' => ['812345679', '812345680']], $h)->assertStatus(429)
            ->assertJsonPath('exists.812345679', true)->assertJsonPath('exists.812345680', null);
        // Lo sabido sigue contestando sin gastar cupo.
        $this->postJson('/api/portal.php?action=warranty-check', ['codes' => ['812345679']], $h)->assertOk();
    }

    // Un «no aparece» se recuerda: el mismo código no vuelve a preguntar a los servidores.
    public function test_no_aparece_se_recuerda_y_no_repite(): void
    {
        $dev = \Mockery::mock(\App\Services\Zkong\ZkongDevices::class);
        $dev->shouldReceive('servers')->andReturn(collect([1]));
        $dev->shouldReceive('labels')->once()->andReturn(['labels' => [], 'failed' => false]);
        $this->instance(\App\Services\Zkong\ZkongDevices::class, $dev);
        $l = app(\App\Services\WarrantyLabels::class);

        $this->assertSame(['999999999' => false], $l->existence(['999999999']));
        $this->assertSame(['999999999' => false], $l->existence(['999999999']));
        $this->assertFalse(\App\Services\WarrantyLabels::known('999999999'));
    }

    public function test_solicitar_crea_el_ticket_y_la_solicitud_con_sus_fotos(): void
    {
        $cat = DB::table('ticket_categories')->insertGetId(['key' => 'garantias', 'name' => 'Garantías', 'color' => '#888', 'position' => 1, 'active' => 1]);
        $h = $this->pase('compi@empresa.com');
        $base = ['place_other' => 'Hotel Prueba', 'name' => 'Ana', 'address' => 'Calle 1, Marbella'];

        // Un código que no es de etiqueta ni de antena: no se crea nada.
        $this->postJson('/api/portal.php?action=warranty-request', $base + ['items' => [['code' => 'HOLA']]], $h)->assertStatus(422);
        $this->assertSame(0, DB::table('tickets')->count());

        // Sin decir qué le pasa, o con «Otro» sin explicarlo, tampoco.
        $this->postJson('/api/portal.php?action=warranty-request', $base + ['items' => [['code' => '812345679', 'detail' => 'pantalla en blanco desde el lunes']]], $h)->assertStatus(422);
        $this->postJson('/api/portal.php?action=warranty-request', $base + ['items' => [['code' => '812345679', 'issue' => 'otro', 'detail' => 'no va']]], $h)->assertStatus(422);
        $this->assertSame(0, DB::table('tickets')->count());

        // Con un fallo de la lista el detalle es opcional.
        $code = $this->postJson('/api/portal.php?action=warranty-request', $base + ['items' => [
            ['code' => '812345679', 'issue' => 'no_enciende'],
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

        // Volver a subir la frontal la sustituye: la anterior se borra (no se acumulan).
        $antes = $items[0]['photos']['front'];
        $n = DB::table('attachments')->where('ticket_id', $t->id)->count();
        $this->post('/api/portal.php?action=warranty-photo', ['ticket' => $code, 'code' => '812345679', 'type' => 'front', 'file' => $foto()], $h)->assertOk();
        $items = json_decode(DB::table('warranty_requests')->where('ticket_id', $t->id)->value('items'), true);
        $this->assertNotSame($antes, $items[0]['photos']['front']);
        $this->assertFalse(DB::table('attachments')->where('id', $antes)->exists());
        $this->assertSame($n, DB::table('attachments')->where('ticket_id', $t->id)->count());

        // El nombre y el tipo salen de lo que es la imagen, no del nombre que manda el navegador.
        $png = UploadedFile::fake()->image('trasera.pdf', 40, 40);
        $this->post('/api/portal.php?action=warranty-photo', ['ticket' => $code, 'code' => '812345679', 'type' => 'back_batteries', 'file' => $png], $h)->assertOk();
        $items = json_decode(DB::table('warranty_requests')->where('ticket_id', $t->id)->value('items'), true);
        $adj = DB::table('attachments')->find($items[0]['photos']['back_batteries']);
        $this->assertSame(['812345679-back_batteries.jpg', 'image/jpeg'], [$adj->name, $adj->mime]);
        $this->assertStringEndsWith('.jpg', $adj->path);

        // Datos mal formados: 422, nunca un 500.
        $this->post('/api/portal.php?action=warranty-photo', ['ticket' => ['x'], 'code' => ['y'], 'type' => 'front', 'file' => $foto()], $h)->assertStatus(422);
        $this->postJson('/api/portal.php?action=warranty-request', $base + ['items' => ['hola']], $h)->assertStatus(422);
        $this->postJson('/api/portal.php?action=warranty-request', $base + ['items' => [['code' => ['812345679'], 'issue' => 'otro']]], $h)->assertStatus(422);
        $this->postJson('/api/portal.php?action=warranty-request', ['name' => ['Ana'], 'place_other' => 'X', 'items' => [['code' => '812345679', 'issue' => 'bateria']]], $h)->assertStatus(422);
        $this->postJson('/api/portal.php?action=warranty-check', ['codes' => [['812345679'], '812345679']], $h)->assertOk();

        // Tope diario de fotos por correo.
        \Illuminate\Support\Facades\RateLimiter::increment('wphoto:' . sha1('compi@empresa.com'), 86400, 400);
        $this->post('/api/portal.php?action=warranty-photo', ['ticket' => $code, 'code' => '812345679', 'type' => 'back_batteries', 'file' => $foto()], $h)->assertStatus(422);

        // Lo que ve el equipo al crear la garantía desde el ticket.
        $this->assertSame('Hotel Prueba', PortalWarranties::forTicket($t->id)['project_name']);
    }

    // Lo que mandó el cliente (fallo y fotos) es del ticket: pasa a la garantía solo si quien
    // añade los códigos ve ese ticket en el Helpdesk.
    public function test_fotos_del_portal_solo_pasan_si_se_ve_el_ticket(): void
    {
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
        DB::table('ticket_categories')->insert(['key' => 'garantias', 'name' => 'Garantías', 'color' => '#888', 'position' => 1, 'active' => 1]);
        $h = $this->pase('compi@empresa.com');
        $code = $this->postJson('/api/portal.php?action=warranty-request', ['place_other' => 'Hotel Prueba', 'name' => 'Ana', 'address' => 'Calle 1',
            'items' => [['code' => '812345679', 'issue' => 'bateria']]], $h)->assertOk()->json('code');
        $this->post('/api/portal.php?action=warranty-photo', ['ticket' => $code, 'code' => '812345679', 'type' => 'front',
            'file' => UploadedFile::fake()->image('f.jpg', 40, 40)], $h)->assertOk();
        $tid = (int) DB::table('tickets')->where('code', $code)->value('id');

        $usuario = function (string $n, array $p) {
            $u = \App\Models\User::create(['name' => $n, 'email' => "{$n}@x.com", 'password' => bcrypt('x'), 'active' => 1]);
            $u->givePermissionTo($p);
            return $u;
        };
        $ciego = $usuario('ciego', [...self::GARANTIAS, 'helpdesk.access']);           // sin la categoría Garantías
        $ve = $usuario('ve', [...self::GARANTIAS, 'helpdesk.access', 'tickets.view_all']);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([[$ciego, false], [$ve, true]] as $i => [$u, $pasa]) {
            $w = $this->garantia('G-2026-001' . $i, ['ticket_id' => $tid]);
            $item = \App\Models\WarrantyItem::create(['warranty_id' => $w->id, 'code' => '812345679', 'kind' => 'esl', 'lookup_state' => 'failed']);
            PortalWarranties::applyToItems($w, ['812345679'], $u->id);
            $this->assertSame($pasa, DB::table('warranty_item_photos')->where('warranty_item_id', $item->id)->exists(), $u->name);
            $this->assertSame($pasa, (bool) $item->fresh()->reported_issue, $u->name);
        }
    }

    // «Ya lo he enviado»: opcional; queda guardado, en la conversación y en la garantía.
    public function test_aviso_de_envio(): void
    {
        DB::table('ticket_categories')->insert(['key' => 'garantias', 'name' => 'Garantías', 'color' => '#888', 'position' => 1, 'active' => 1]);
        $h = $this->pase('compi@empresa.com');
        $code = $this->postJson('/api/portal.php?action=warranty-request', ['place_other' => 'Hotel Prueba', 'name' => 'Ana', 'address' => 'Calle 1',
            'items' => [['code' => '812345679', 'issue' => 'bateria']]], $h)->assertOk()->json('code');
        $tid = (int) DB::table('tickets')->where('code', $code)->value('id');
        $avisar = fn (array $d, $hh = null) => $this->postJson('/api/portal.php?action=warranty-shipment', $d + ['code' => $code], $hh ?? $h);

        $this->assertTrue($this->getJson('/api/portal.php?action=warranty&code=' . $code, $h)->json('warranty.can_ship'));
        // Fecha futura, seguimiento raro, paquetes a cero, datos mal formados: no.
        $avisar(['shipped_on' => now()->addDay()->toDateString()])->assertStatus(422);
        $avisar(['tracking' => '<script>'])->assertStatus(422);
        $avisar(['packages' => 0])->assertStatus(422);
        $avisar(['tracking' => ['x']])->assertStatus(422);
        // Otro correo sin relación no puede avisar.
        $avisar([], $this->pase('ajeno@libre.com'))->assertStatus(422);
        $this->assertSame(0, DB::table('warranty_shipments')->count());

        // Con lo mínimo (hoy, 1 paquete) vale; y con todo, también. Un compañero de empresa lo ve.
        $avisar([])->assertOk();
        $avisar(['shipped_on' => now()->subDays(2)->toDateString(), 'carrier' => 'SEUR', 'tracking' => 'ES123 456', 'packages' => 2, 'units' => 40, 'note' => 'va con cables'])->assertOk();
        $envios = $this->getJson('/api/portal.php?action=warranty&code=' . $code, $this->pase('otro@empresa.com'))->json('warranty.shipments');
        $this->assertSame(['SEUR', 'ES123 456', 2, 40], [$envios[0]['carrier'], $envios[0]['tracking'], $envios[0]['packages'], $envios[0]['units']]);
        $this->assertCount(2, $envios);
        $ultimo = DB::table('messages')->where('ticket_id', $tid)->orderByDesc('id')->first();
        $this->assertSame('in', $ultimo->direction);
        $this->assertStringContainsString('Seguimiento: ES123 456', $ultimo->body);

        // Ya es garantía: el aviso queda en su historial y la ficha del equipo lo enseña.
        $w = $this->garantia('G-2026-0020', ['ticket_id' => $tid, 'portal_client_id' => self::EMPRESA]);
        $avisar(['code' => 'G-2026-0020', 'tracking' => 'MRW-998877'])->assertOk();
        $this->assertCount(3, $this->getJson('/api/portal.php?action=warranty&code=G-2026-0020', $h)->json('warranty.shipments'));
        $this->assertSame(1, DB::table('warranty_events')->where('warranty_id', $w->id)->where('field', 'shipment')
            ->where('to_value', 'like', '%MRW-998877%')->count());
        $this->assertSame($w->id, (int) DB::table('warranty_shipments')->where('tracking', 'MRW-998877')->value('warranty_id'));

        // Cerrada: ya no se avisa.
        $w->forceFill(['status' => 'cerrada', 'closed_at' => now()])->save();
        $avisar(['code' => 'G-2026-0020'])->assertStatus(422);
        $this->assertFalse($this->getJson('/api/portal.php?action=warranty&code=G-2026-0020', $h)->json('warranty.can_ship'));
    }

    // Sin empresa en el portal, un id de hotel no cuenta (no es suyo): falta el sitio.
    public function test_sin_lugar_valido_no_se_crea(): void
    {
        $this->postJson('/api/portal.php?action=warranty-request', ['place_id' => 12345, 'name' => 'Ana', 'address' => 'X',
            'items' => [['code' => '812345679', 'issue' => 'no_enciende', 'detail' => 'pantalla en blanco desde el lunes']]], $this->pase('libre@libre.com'))->assertStatus(422);
        $this->assertSame(0, DB::table('tickets')->count());
    }
}
