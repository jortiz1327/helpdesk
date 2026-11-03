<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Warranty;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Expedientes de garantía: numeración, historial automático de cambios, seguimiento,
 * filtros rápidos y permisos.
 */
class WarrantiesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
    }

    private function agente(array $permisos = [...self::GARANTIAS], string $name = 'Ana'): array
    {
        $u = User::create(['name' => $name, 'email' => uniqid() . '@x.com', 'password' => bcrypt('x'), 'active' => 1]);
        $u->givePermissionTo($permisos);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        return [$u, ['X-App-Token' => TokenService::make($u)]];
    }

    private function crear(array $h, array $datos = []): array
    {
        return $this->postJson('/api/warranties.php?action=save', $datos + [
            'received_at' => '2026-10-20', 'client_name' => 'Hoteles Sol', 'project_name' => 'Sol Centro',
            'category' => 'Hoteles', 'urgency' => 'media', 'status' => 'no_iniciado',
        ], $h)->assertOk()->json();
    }

    public function test_sin_permiso_no_entra(): void
    {
        [, $h] = $this->agente(['helpdesk.access']);
        $this->getJson('/api/warranties.php', $h)->assertForbidden();
    }

    public function test_bandeja_de_tickets_pide_tambien_helpdesk(): void
    {
        [, $solo] = $this->agente();
        $this->getJson('/api/warranties.php?action=inbox&tab=todos', $solo)->assertForbidden();
        $this->getJson('/api/warranties.php?action=by_ticket&ticket_id=1', $solo)->assertForbidden();
        [, $ambos] = $this->agente([...self::GARANTIAS, 'helpdesk.access'], 'Pepe');
        $this->getJson('/api/warranties.php?action=inbox&tab=todos', $ambos)->assertOk();
        $this->getJson('/api/warranties.php?action=by_ticket&ticket_id=1', $ambos)->assertOk();
    }

    public function test_numeracion_por_anio(): void
    {
        [, $h] = $this->agente();
        $a = $this->crear($h);
        $b = $this->crear($h);
        $y = now()->year;
        $this->assertSame("G-{$y}-0001", $a['code']);
        $this->assertSame("G-{$y}-0002", $b['code']);
    }

    public function test_editar_deja_historial_legible_y_cerrar_pone_fecha(): void
    {
        [$ana, $h] = $this->agente();
        [$luis] = $this->agente([...self::GARANTIAS], 'Luis');
        $id = $this->crear($h)['id'];

        $this->postJson('/api/warranties.php?action=save', [
            'id' => $id, 'received_at' => '2026-10-20', 'client_name' => 'Hoteles Sol', 'project_name' => 'Sol Centro',
            'category' => 'Hoteles', 'urgency' => 'muy_urgente', 'status' => 'cerrada', 'responsible_id' => $luis->id,
        ], $h)->assertOk();

        $w = Warranty::find($id);
        $this->assertNotNull($w->closed_at);

        $r = $this->getJson("/api/warranties.php?action=get&id={$id}", $h)->assertOk()->json();
        $ev = collect($r['events'])->keyBy('field');
        $this->assertSame(['Media', 'Muy urgente'], [$ev['urgency']['from_value'], $ev['urgency']['to_value']]);
        $this->assertSame(['No iniciado', 'Cerrada'], [$ev['status']['from_value'], $ev['status']['to_value']]);
        $this->assertSame('Luis', $ev['responsible_id']['to_value']);
        $this->assertFalse($ev->has('client_name'));   // lo que no cambia no se apunta
        $this->assertTrue($ev->has('created'));

        // Reabrir quita la fecha de cierre.
        $this->postJson('/api/warranties.php?action=save', ['id' => $id, 'received_at' => '2026-10-20', 'status' => 'en_curso'], $h)->assertOk();
        $this->assertNull(Warranty::find($id)->closed_at);
    }

    public function test_todas_las_fechas_se_pueden_cambiar_y_quedan_en_el_historial(): void
    {
        [, $h] = $this->agente();
        $id = $this->crear($h, ['first_contact_at' => '2026-10-10', 'status' => 'cerrada', 'closed_at' => '2026-10-25'])['id'];
        $w = Warranty::find($id);
        $this->assertSame(['2026-10-10', '2026-10-25'], [$w->first_contact_at->toDateString(), $w->closed_at->toDateString()]);

        $this->postJson('/api/warranties.php?action=save', [
            'id' => $id, 'first_contact_at' => '2026-10-08', 'received_at' => '2026-10-21', 'status' => 'cerrada', 'closed_at' => '2026-10-27',
            'client_name' => 'Hoteles Sol', 'project_name' => 'Sol Centro', 'category' => 'Hoteles', 'urgency' => 'media',
        ], $h)->assertOk();
        $ev = collect($this->getJson("/api/warranties.php?action=get&id={$id}", $h)->json('events'))->keyBy('field');
        $this->assertSame(['10/10/2026', '08/10/2026'], [$ev['first_contact_at']['from_value'], $ev['first_contact_at']['to_value']]);
        $this->assertSame(['20/10/2026', '21/10/2026'], [$ev['received_at']['from_value'], $ev['received_at']['to_value']]);
        $this->assertSame(['25/10/2026', '27/10/2026'], [$ev['closed_at']['from_value'], $ev['closed_at']['to_value']]);
        $this->assertSame(4, $ev->count());   // created + las tres fechas, nada más

        $this->postJson('/api/warranties.php?action=save', ['id' => $id, 'received_at' => '2026-10-21', 'closed_at' => '2026-02-30'], $h)->assertStatus(400);
    }

    public function test_responsable_debe_tener_acceso_y_ticket_debe_existir(): void
    {
        [, $h] = $this->agente();
        [$otro] = $this->agente(['helpdesk.access'], 'Pepe');
        $this->postJson('/api/warranties.php?action=save', ['received_at' => '2026-10-20', 'responsible_id' => $otro->id], $h)->assertStatus(400);
        $this->postJson('/api/warranties.php?action=save', ['received_at' => '2026-10-20', 'ticket_code' => 'TK-NO-EXISTE'], $h)->assertStatus(400);
        $this->postJson('/api/warranties.php?action=save', ['received_at' => ''], $h)->assertStatus(400);
    }

    /**
     * La garantía no sirve para leer tickets del Helpdesk que el usuario no ve: no se
     * vinculan, y si ya lo estaban, ni asunto, ni imágenes, ni correo del cliente.
     */
    public function test_ticket_de_otra_area_no_se_vincula_ni_se_lee(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $mia = DB::table('ticket_categories')->insertGetId(['key' => 'mia', 'name' => 'Garantías', 'color' => '#888', 'position' => 1, 'active' => 1]);
        $ajena = DB::table('ticket_categories')->insertGetId(['key' => 'ajena', 'name' => 'Comercial', 'color' => '#888', 'position' => 2, 'active' => 1]);
        $ticket = function (int $cat, string $code) {
            $cid = DB::table('contacts')->insertGetId(['name' => 'C', 'email' => $code . '@cliente.com']);
            return DB::table('tickets')->insertGetId(['code' => $code, 'subject' => "Asunto {$code}", 'status' => 'abierto', 'priority' => 'media',
                'channel' => 'email', 'contact_id' => $cid, 'category_id' => $cat, 'opened_at' => now(), 'last_message_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        };
        $ticket($mia, 'TK-MIA');
        $tAjena = $ticket($ajena, 'TK-AJENA');
        $jpg = \Illuminate\Http\UploadedFile::fake()->image('f.jpg', 20, 20)->get();
        \Illuminate\Support\Facades\Storage::disk('local')->put('attachments/x/f.jpg', $jpg);
        $foto = DB::table('attachments')->insertGetId(['ticket_id' => $tAjena, 'name' => 'f.jpg', 'path' => 'attachments/x/f.jpg', 'mime' => 'image/jpeg', 'size' => strlen($jpg)]);

        // Agente del Helpdesk limitado a su categoría, con garantías; y otro solo de garantías.
        [$ana, $h] = $this->agente([...self::GARANTIAS, 'helpdesk.access']);
        DB::table('user_ticket_categories')->insert(['user_id' => $ana->id, 'category_id' => $mia]);
        [, $hl] = $this->agente([...self::GARANTIAS], 'Luis');

        $base = ['received_at' => '2026-10-20', 'status' => 'no_iniciado'];
        $this->postJson('/api/warranties.php?action=save', $base + ['ticket_code' => 'TK-MIA'], $h)->assertOk();
        $err = $this->postJson('/api/warranties.php?action=save', $base + ['ticket_code' => 'TK-AJENA'], $h)->assertStatus(400)->json('error');
        // El mismo mensaje que si no existiera: no confirma qué códigos hay.
        $noExiste = $this->postJson('/api/warranties.php?action=save', $base + ['ticket_code' => 'TK-NO-EXISTE'], $h)->assertStatus(400)->json('error');
        $this->assertSame(str_replace('TK-NO-EXISTE', 'TK-AJENA', $noExiste), $err);
        $this->postJson('/api/warranties.php?action=save', $base + ['ticket_code' => 'TK-MIA'], $hl)->assertStatus(400);

        // Una garantía que ya estaba enlazada al ticket ajeno (la hizo otro): no se lee nada de él.
        $w = Warranty::create($base + ['code' => 'G-2026-0099', 'ticket_id' => $tAjena, 'last_activity_at' => now(), 'status_changed_at' => now()]);
        $this->assertNull($this->getJson("/api/warranties.php?action=get&id={$w->id}", $h)->assertOk()->json('warranty.ticket_subject'));
        $this->assertSame([], $this->getJson("/api/warranties.php?action=ticket_images&id={$w->id}", $h)->assertOk()->json('images'));
        $media = TokenService::mediaToken($ana);
        $this->get("/api/warranty_photo.php?w={$w->id}&src=a:{$foto}&token={$media}")->assertNotFound();
        $this->assertNull($this->getJson("/api/warranty_docs.php?action=email&id={$w->id}", $h)->assertOk()->json('to'));
        // Editarla sin cambiar el ticket sigue funcionando.
        $this->postJson('/api/warranties.php?action=save', $base + ['id' => $w->id, 'ticket_code' => 'TK-AJENA', 'client_name' => 'X'], $h)->assertOk();

        // Fotos del cliente: otro ticket del mismo contacto en un área que no ve tampoco aparece.
        $wMia = Warranty::where('ticket_id', DB::table('tickets')->where('code', 'TK-MIA')->value('id'))->first();
        $cMia = DB::table('tickets')->where('code', 'TK-MIA')->value('contact_id');
        $tOculto = DB::table('tickets')->insertGetId(['code' => 'TK-OCULTO', 'subject' => 'x', 'status' => 'abierto', 'priority' => 'media', 'channel' => 'email',
            'contact_id' => $cMia, 'category_id' => $ajena, 'opened_at' => now(), 'last_message_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $oculta = DB::table('attachments')->insertGetId(['ticket_id' => $tOculto, 'name' => 'o.jpg', 'path' => 'attachments/x/f.jpg', 'mime' => 'image/jpeg', 'size' => strlen($jpg)]);
        $this->assertSame([1, []], array_values(array_intersect_key($this->getJson("/api/warranties.php?action=ticket_images&id={$wMia->id}&all=1", $h)->json(), ['tickets' => 1, 'images' => 1])));
        $this->get("/api/warranty_photo.php?w={$wMia->id}&src=a:{$oculta}&token={$media}")->assertNotFound();

        // Mensajes del cliente en la línea de tiempo: solo los suyos (no respuestas, notas
        // internas ni WhatsApp de Campañas), y solo si ve el ticket.
        $cAjena = DB::table('tickets')->where('id', $tAjena)->value('contact_id');
        $msg = fn (array $d) => DB::table('messages')->insert($d + ['contact_id' => $cAjena, 'ticket_id' => $tAjena, 'direction' => 'in', 'channel' => 'email',
            'funcion' => 'soporte', 'type' => 'text', 'is_html' => 0, 'is_internal_note' => 0, 'created_at' => now()]);
        $msg(['body' => '<p>Llegaron <b>rotas</b></p><p>tres</p>', 'is_html' => 1]);
        $msg(['body' => '', 'channel' => 'whatsapp', 'type' => 'image']);
        $msg(['body' => 'respuesta nuestra', 'direction' => 'out']);
        $msg(['body' => 'nota', 'is_internal_note' => 1]);
        $msg(['body' => 'oferta', 'channel' => 'whatsapp', 'funcion' => 'campanas']);
        $this->assertSame([], $this->getJson("/api/warranties.php?action=get&id={$w->id}", $h)->json('messages'));

        // Quien sí ve el ticket, sí lo lee.
        DB::table('user_ticket_categories')->insert(['user_id' => $ana->id, 'category_id' => $ajena]);
        $this->assertSame('Asunto TK-AJENA', $this->getJson("/api/warranties.php?action=get&id={$w->id}", $h)->json('warranty.ticket_subject'));
        $m = $this->getJson("/api/warranties.php?action=get&id={$w->id}", $h)->json('messages');
        $this->assertEqualsCanonicalizing([['correo', 'Llegaron rotas tres'], ['whatsapp', 'Ha mandado una foto']], array_map(fn ($x) => [$x['channel'], $x['text']], $m));
        $this->assertSame('TK-AJENA@cliente.com', $this->getJson("/api/warranty_docs.php?action=email&id={$w->id}", $h)->json('to'));
    }

    public function test_seguimiento_y_filtros_rapidos(): void
    {
        [$ana, $h] = $this->agente();
        $a = $this->crear($h, ['responsible_id' => $ana->id, 'urgency' => 'alta']);
        $b = $this->crear($h, ['status' => 'pte_cliente', 'client_name' => '']);
        $c = $this->crear($h, ['status' => 'cerrada']);
        // b lleva un mes sin moverse.
        Warranty::whereKey($b['id'])->update(['last_activity_at' => now()->subDays(30)]);

        $this->postJson('/api/warranties.php?action=note', ['id' => $a['id'], 'type' => 'llamada', 'body' => 'Llamado al cliente'], $h)->assertOk();
        $this->postJson('/api/warranties.php?action=note', ['id' => $a['id'], 'body' => '  '], $h)->assertStatus(400);
        $notes = $this->getJson("/api/warranties.php?action=get&id={$a['id']}", $h)->json('notes');
        $this->assertSame([['llamada', 'Llamado al cliente', 'Ana']], collect($notes)->map(fn ($n) => [$n['type'], $n['body'], $n['user_name']])->all());

        $ids = fn (string $chip) => collect($this->getJson("/api/warranties.php?chip={$chip}", $h)->json('rows'))->pluck('id')->sort()->values()->all();
        $this->assertSame([$a['id']], $ids('mine'));
        $this->assertSame([$a['id']], $ids('urgent'));
        $this->assertSame([$a['id'], $b['id']], $ids('open'));
        $this->assertSame([$b['id']], $ids('pte_cliente'));
        $this->assertSame([$b['id']], $ids('stale'));
        $this->assertSame([$b['id']], $ids('no_client'));
        $this->assertSame([$c['id']], $ids('closed'));

        $r = $this->getJson('/api/warranties.php?q=' . urlencode($a['code']), $h)->json();
        $this->assertSame(1, $r['total']);
        $this->assertSame(3, $r['all']);
    }

    public function test_contadores_categoria_y_cambios_en_lote(): void
    {
        [$ana, $h] = $this->agente();
        [$luis] = $this->agente([...self::GARANTIAS], 'Luis');
        [$pepe] = $this->agente(['helpdesk.access'], 'Pepe');
        $a = $this->crear($h, ['responsible_id' => $ana->id]);
        $b = $this->crear($h, ['category' => 'Tiendas']);
        $c = $this->crear($h, ['status' => 'cerrada']);

        $r = $this->getJson('/api/warranties.php', $h)->json();
        $this->assertSame(['open' => 2, 'mine' => 1, 'pte_zkong' => 0, 'closed' => 1, 'all' => 3, 'repeated' => 0], $r['counts']);
        $this->assertSame([$b['id']], collect($this->getJson('/api/warranties.php?category=Tiendas', $h)->json('rows'))->pluck('id')->all());

        // Estado y responsable de varias a la vez; cada cambio queda en su historial.
        $this->postJson('/api/warranties.php?action=bulk', ['ids' => [$a['id'], $b['id']], 'status' => 'cerrada', 'responsible_id' => $luis->id], $h)
            ->assertOk()->assertJson(['changed' => 2]);
        $this->assertNotNull(Warranty::find($a['id'])->closed_at);
        $this->assertSame($luis->id, Warranty::find($b['id'])->responsible_id);
        $ev = collect($this->getJson("/api/warranties.php?action=get&id={$b['id']}", $h)->json('events'))->keyBy('field');
        $this->assertSame(['No iniciado', 'Cerrada'], [$ev['status']['from_value'], $ev['status']['to_value']]);
        $this->assertSame('Luis', $ev['responsible_id']['to_value']);

        // Reabrir en lote quita la fecha de cierre; lo que no cambia no cuenta.
        $this->postJson('/api/warranties.php?action=bulk', ['ids' => [$a['id'], $c['id']], 'status' => 'en_curso'], $h)->assertJson(['changed' => 2]);
        $this->assertNull(Warranty::find($c['id'])->closed_at);
        $this->postJson('/api/warranties.php?action=bulk', ['ids' => [$a['id']], 'status' => 'en_curso'], $h)->assertJson(['changed' => 0]);

        // Sin acceso a garantías no puede ser responsable; sin nada que cambiar, error.
        $this->postJson('/api/warranties.php?action=bulk', ['ids' => [$a['id']], 'responsible_id' => $pepe->id], $h)->assertStatus(400);
        $this->postJson('/api/warranties.php?action=bulk', ['ids' => [$a['id']]], $h)->assertStatus(400);
        $this->postJson('/api/warranties.php?action=bulk', ['ids' => [$a['id']], 'responsible_id' => 'none'], $h)->assertJson(['changed' => 1]);
        $this->assertNull(Warranty::find($a['id'])->responsible_id);
    }

    public function test_bandeja_de_garantias_solo_su_categoria_y_garantia_enlazada_al_ticket(): void
    {
        $catGar = DB::table('ticket_categories')->insertGetId(['key' => 'garantias_t', 'name' => 'Garantías', 'color' => '#888', 'position' => 1, 'active' => 1]);
        $catSop = DB::table('ticket_categories')->insertGetId(['key' => 'soporte_t', 'name' => 'Soporte', 'color' => '#888', 'position' => 2, 'active' => 1]);
        $ticket = function (?int $cat, string $code) {
            $cid = DB::table('contacts')->insertGetId(['name' => 'C', 'email' => 'c' . uniqid() . '@x.com']);
            return DB::table('tickets')->insertGetId([
                'code' => $code, 'subject' => 'Etiquetas sin pantalla', 'status' => 'abierto', 'priority' => 'media',
                'channel' => 'email', 'contact_id' => $cid, 'category_id' => $cat,
                'opened_at' => now(), 'last_message_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        };
        $g = $ticket($catGar, 'TK-GAR-1');
        $ticket($catSop, 'TK-SOP-1');
        $ticket(null, 'TK-SIN-1');

        $super = User::create(['name' => 'Root', 'email' => 'root@x.com', 'password' => bcrypt('x'), 'active' => 1]);
        $super->assignRole(config('rbac.super_role'));
        $h = ['X-App-Token' => TokenService::make($super)];

        // Estricta: solo la categoría pedida, sin los «sin categoría».
        $codes = fn (string $qs) => collect($this->getJson("/api/tickets.php?action=list&status=all&{$qs}", $h)->assertOk()->json('tickets'))->pluck('code')->sort()->values()->all();
        $this->assertSame(['TK-GAR-1'], $codes("category={$catGar}&category_strict=1"));
        $this->assertSame([], $codes('category=0&category_strict=1'));            // sin categoría válida, nada
        $this->assertSame(['TK-GAR-1', 'TK-SIN-1'], $codes("category={$catGar}"));   // Soporte sigue igual

        // Garantía creada a mano desde el ticket: queda enlazada.
        $w = $this->crear($h, ['ticket_code' => 'TK-GAR-1']);
        $r = $this->getJson("/api/warranties.php?action=by_ticket&ticket_id={$g}", $h)->assertOk()->json('warranties');
        $this->assertSame([$w['code']], array_column($r, 'code'));
    }

    public function test_hoteles_y_tiendas_del_portal(): void
    {
        $this->assertSame('Fergus Club Europa', \App\Services\AemePortal::baseName('Fergus Club Europa - Proyecto'));
        $this->assertSame('Farmacia Carmen Castro', \App\Services\AemePortal::baseName('Farmacia  Carmen Castro - Comanda 981 - Expofarm'));
        $this->assertNull(\App\Services\AemePortal::baseName('Soporte - Farmacia Remunta - Comanda 981'));
        $this->assertNull(\App\Services\AemePortal::baseName('(Nulo) Hotel X - Proyecto'));
        $this->assertNull(\App\Services\AemePortal::baseName('Stock Expofarm - Comanda 1011'));
        $this->assertNull(\App\Services\AemePortal::baseName('Proyecto temporal'));

        // Varios pedidos de la misma tienda: una sola entrada, la más reciente.
        $bases = \App\Services\AemePortal::bases([
            (object) ['project_id' => 5, 'project_title' => 'Farmacia Sol - Comanda 10'],
            (object) ['project_id' => 9, 'project_title' => 'Farmacia Sol - Comanda 22'],
            (object) ['project_id' => 7, 'project_title' => 'Amanecer - Proyecto'],
        ]);
        $this->assertSame([[7, 'Amanecer'], [9, 'Farmacia Sol']], array_map(fn ($b) => [$b['id'], $b['name']], $bases));
    }

    public function test_sin_portal_se_escribe_a_mano_y_guarda_los_ids(): void
    {
        config(['database.connections.portal_aeme.host' => '']);
        [, $h] = $this->agente();
        $this->getJson('/api/warranties.php?action=portal_clients', $h)->assertOk()->assertJson(['available' => false, 'clients' => []]);

        $w = $this->crear($h, ['portal_client_id' => 12, 'portal_project_id' => 345]);
        $r = $this->getJson("/api/warranties.php?action=get&id={$w['id']}", $h)->json('warranty');
        $this->assertSame([12, 345], [$r['portal_client_id'], $r['portal_project_id']]);
    }

    /** Servidores ZKONG de mentira: código => [servidor, tienda, modelo, conectada]. */
    private function zkong(array $etiquetas): void
    {
        $this->app->instance(\App\Services\Zkong\ZkongDevices::class, new class($etiquetas) extends \App\Services\Zkong\ZkongDevices {
            public function __construct(private array $e) {}
            public function servers() { return collect([(object) ['name' => 'APP02']]); }
            public function labels(array $codes, ?int $userId = null): array
            {
                $out = [];
                foreach ($codes as $c) {
                    $x = $this->e[$c] ?? null;
                    $row = $x ? ['barCode' => $c, 'mac' => $c, 'online' => $x[3] ? 1 : 0, 'offLineTime' => $x[3] ? null : now()->subDays(3)->toDateTimeString(), 'server' => $x[0], 'server_url' => 'https://' . strtolower($x[0]) . '.zkong.com', 'storeName' => $x[1], 'merchantName' => null,
                        'model' => $x[2], 'state' => $x[3] ? 1 : 0, 'lastCommuTime' => now()->subDays($x[4] ?? 0)->toDateTimeString()] : null;
                    $out[$c] = ['rows' => $row ? [$row] : [], 'live' => $row];
                }
                return ['labels' => $out, 'failed' => []];
            }
        });
        \Illuminate\Support\Facades\Cache::flush();
    }

    public function test_etiquetas_solo_con_el_codigo_sacan_modelo_producto_y_avisos(): void
    {
        config(['database.connections.portal_aeme.host' => '']);   // sin portal: los nombres de producto no hacen falta aquí
        [, $h] = $this->agente();
        $this->zkong([
            '8E9749440' => ['APP02', 'Sol Centro', 'ZKC26V-NAE4', true],
            '854A49907' => ['APP02', 'Mis Chuches', 'ZKC99X-NE4', false, 60],
        ]);

        $r = $this->postJson('/api/warranties.php?action=labels_check', ['codes' => "8e9749440\n854A49907, ZZZZZZZZ9  malo", 'client_name' => 'Hoteles Sol', 'project_name' => 'Sol Centro'], $h)->assertOk()->json();
        $this->assertSame(['MALO'], $r['invalid']);
        $res = collect($r['results'])->keyBy('code');
        $this->assertSame('AMC26V', $res['8E9749440']['aeme_ref']);           // del modelo del servidor sale el producto
        $this->assertSame([], $res['8E9749440']['warnings']);
        $this->assertNull($res['854A49907']['aeme_ref']);                     // modelo fuera del catálogo
        $claves = collect($res['854A49907']['warnings'])->pluck('key')->all();
        $this->assertContains('no_model', $claves);
        $this->assertContains('other_client', $claves);
        $this->assertContains('silent', $claves);
        $this->assertSame('not_found', $res['ZZZZZZZZ9']['lookup_state']);
        $this->assertSame('info', collect($res['854A49907']['warnings'])->firstWhere('key', 'no_model')['tone']);   // se queda el modelo de ZKONG

        // Antenas: por su MAC, con o sin dos puntos.
        $this->zkong(['A0A3B85B5F7F' => ['APP02', 'Sol Centro', null, false]]);
        $ap = $this->postJson('/api/warranties.php?action=labels_check', ['codes' => 'a0:a3:b8:5b:5f:7f', 'project_name' => 'Sol Centro'], $h)->json('results.0');
        $this->assertSame(['A0A3B85B5F7F', 'ap', 'found', false], [$ap['code'], $ap['kind'], $ap['lookup_state'], $ap['online']]);
        $this->assertContains('ap_offline', collect($ap['warnings'])->pluck('key')->all());
        $this->assertSame(['AMAP-C', false], [$ap['aeme_ref'], $ap['model_manual']]);    // la antena es AMAP-C en el portal
        $this->assertNotContains('no_model', collect($ap['warnings'])->pluck('key')->all());
        $this->zkong([
            '8E9749440' => ['APP02', 'Sol Centro', 'ZKC26V-NAE4', true],
            '854A49907' => ['APP02', 'Mis Chuches', 'ZKC99X-NE4', false, 60],
        ]);

        // Alta con las etiquetas; la que no tiene modelo se elige a mano.
        $w = $this->crear($h, ['labels' => "8E9749440\n854A49907", 'manual' => ['854A49907' => 'AMC42B-W']]);
        $this->assertSame(['8E9749440', '854A49907'], $w['added']);
        $items = collect($this->getJson("/api/warranties.php?action=get&id={$w['id']}", $h)->json('items'))->keyBy('code');
        $this->assertSame(['AMC26V', false], [$items['8E9749440']['aeme_ref'], $items['8E9749440']['model_manual']]);
        $this->assertSame(['AMC42B-W', true], [$items['854A49907']['aeme_ref'], $items['854A49907']['model_manual']]);

        // En otra garantía abierta no puede entrar; cerrada la primera, sí (con aviso del historial).
        // El alta con una etiqueta que no puede entrar (o que no aparece) no se crea.
        $base = ['received_at' => '2026-10-20', 'client_name' => 'Hoteles Sol', 'project_name' => 'Sol Centro', 'status' => 'no_iniciado'];
        $antes = Warranty::count();
        $this->postJson('/api/warranties.php?action=save', $base + ['labels' => '8E9749440'], $h)->assertStatus(400)->assertJsonFragment(['ok' => false]);
        $this->postJson('/api/warranties.php?action=save', $base + ['labels' => 'ZZZZZZZZ9'], $h)->assertStatus(400);
        $this->assertSame($antes, Warranty::count());
        $otra = $this->crear($h);
        $add = $this->postJson('/api/warranties.php?action=labels_add', ['id' => $otra['id'], 'codes' => '8E9749440'], $h)->assertOk()->json();
        $this->assertSame([], $add['added']);
        $this->assertStringContainsString($w['code'], $add['skipped'][0]['reason']);
        Warranty::whereKey($w['id'])->update(['status' => 'cerrada', 'closed_at' => now()]);
        $add = $this->postJson('/api/warranties.php?action=labels_add', ['id' => $otra['id'], 'codes' => '8E9749440'], $h)->assertOk()->json();
        $this->assertSame(['8E9749440'], $add['added']);

        // Cambiar el modelo y quitar una etiqueta queda en el historial.
        $item = \App\Models\WarrantyItem::where('warranty_id', $otra['id'])->first();
        $this->postJson('/api/warranties.php?action=label_model', ['item_id' => $item->id, 'aeme_ref' => 'NO-EXISTE'], $h)->assertStatus(400);
        $this->postJson('/api/warranties.php?action=label_model', ['item_id' => $item->id, 'aeme_ref' => 'AMC42B-W'], $h)->assertOk();
        $this->assertContains('model_changed', collect($item->fresh()->warnings)->pluck('key')->all());
        $this->postJson('/api/warranties.php?action=label_delete', ['item_id' => $item->id], $h)->assertOk();
        $campos = collect($this->getJson("/api/warranties.php?action=get&id={$otra['id']}", $h)->json('events'))->pluck('field')->all();
        $this->assertSame(2, count(array_keys($campos, 'items')));
        $this->assertContains('item_model', $campos);

        $this->assertSame(2, collect($this->getJson('/api/warranties.php', $h)->json('rows'))->firstWhere('id', $w['id'])['labels_count']);
    }

    public function test_revision_por_dispositivo_con_fotos_y_las_imagenes_del_ticket(): void
    {
        config(['database.connections.portal_aeme.host' => '']);
        \Illuminate\Support\Facades\Storage::fake('local');
        [$u, $h] = $this->agente([...self::GARANTIAS, 'helpdesk.access']);
        $this->zkong([
            '8E9749440' => ['APP02', 'Sol Centro', 'ZKC26V-NAE4', true],
            '854A49907' => ['APP02', 'Sol Centro', 'ZKC26V-NAE4', true],
            'A0A3B85B5F7F' => ['APP02', 'Sol Centro', null, true],
        ]);

        // Ticket con una foto del cliente (y un PDF que no cuenta como imagen).
        $cid = DB::table('contacts')->insertGetId(['name' => 'C', 'email' => 'c@x.com']);
        $tid = DB::table('tickets')->insertGetId(['code' => 'TK-1', 'subject' => 'Rotas', 'status' => 'abierto', 'priority' => 'media',
            'channel' => 'email', 'contact_id' => $cid, 'opened_at' => now(), 'last_message_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $jpg = \Illuminate\Http\UploadedFile::fake()->image('rota.jpg', 40, 30)->get();
        \Illuminate\Support\Facades\Storage::disk('local')->put('attachments/x/rota.jpg', $jpg);
        \Illuminate\Support\Facades\Storage::disk('local')->put('attachments/x/doc.pdf', '%PDF-1.4');
        $foto = DB::table('attachments')->insertGetId(['ticket_id' => $tid, 'name' => 'rota.jpg', 'path' => 'attachments/x/rota.jpg', 'mime' => 'image/jpeg', 'size' => strlen($jpg)]);
        DB::table('attachments')->insert(['ticket_id' => $tid, 'name' => 'doc.pdf', 'path' => 'attachments/x/doc.pdf', 'mime' => 'application/pdf', 'size' => 8]);
        // Otro ticket del mismo cliente (sus fotos también valen) y uno de otro cliente (no).
        $mismo = DB::table('tickets')->insertGetId(['code' => 'TK-3', 'subject' => 'Antes', 'status' => 'cerrado', 'priority' => 'media',
            'channel' => 'whatsapp', 'contact_id' => $cid, 'opened_at' => now(), 'last_message_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $antes = DB::table('attachments')->insertGetId(['ticket_id' => $mismo, 'name' => 'antes.jpg', 'path' => 'attachments/x/rota.jpg', 'mime' => 'image/jpeg', 'size' => strlen($jpg), 'created_at' => now()->subDay()]);
        $otroTicket = DB::table('tickets')->insertGetId(['code' => 'TK-2', 'subject' => 'Otro', 'status' => 'abierto', 'priority' => 'media',
            'channel' => 'email', 'contact_id' => DB::table('contacts')->insertGetId(['name' => 'D', 'email' => 'd@x.com']), 'opened_at' => now(), 'last_message_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $ajena = DB::table('attachments')->insertGetId(['ticket_id' => $otroTicket, 'name' => 'a.jpg', 'path' => 'attachments/x/rota.jpg', 'mime' => 'image/jpeg', 'size' => 1]);

        $w = $this->crear($h, ['ticket_code' => 'TK-1', 'labels' => "8E9749440\n854A49907\nA0A3B85B5F7F"]);
        $items = collect($this->getJson("/api/warranties.php?action=get&id={$w['id']}", $h)->json('items'))->keyBy('code');
        $this->assertSame('pendiente', $items['8E9749440']['resolution']);
        $ids = [$items['8E9749440']['id'], $items['854A49907']['id']];
        $ap = $items['A0A3B85B5F7F']['id'];

        // Lo que pide cada solución.
        $rev = fn (array $d) => $this->postJson('/api/warranties.php?action=review', $d + ['id' => $w['id'], 'item_ids' => $ids], $h);
        $rev(['resolution' => 'danada'])->assertStatus(400);
        $rev(['resolution' => 'desechada'])->assertStatus(400);
        $rev(['resolution' => 'defectuosa', 'zkong_decision' => 'enviar'])->assertStatus(400);
        $rev(['resolution' => 'inventada'])->assertStatus(400);

        // Varias de golpe: batería (sin fotos) y luego defectuosas (piden fotos).
        $r = $rev(['resolution' => 'bateria', 'battery_changed' => true])->assertOk()->json('items');
        $this->assertSame(['bateria', true], [$r[0]['resolution'], $r[0]['battery_changed']]);
        $r = collect($rev(['resolution' => 'defectuosa', 'zkong_decision' => 'enviar', 'zkong_issue' => 'No enciende'])->assertOk()->json('items'))->keyBy('code');
        $this->assertSame(['defectuosa', 'enviar', 'No enciende', false, 'Ana'], [$r['8E9749440']['resolution'], $r['8E9749440']['zkong_decision'], $r['8E9749440']['zkong_issue'], $r['8E9749440']['battery_changed'], $r['8E9749440']['reviewed_by_name']]);
        $this->assertSame('pendiente', $r['A0A3B85B5F7F']['resolution']);
        $this->postJson('/api/warranties.php?action=review', ['id' => $w['id'], 'item_ids' => [$ap], 'resolution' => 'danada', 'broken_cases' => ['liquids', 'inventado']], $h)->assertOk();
        $this->assertSame(['liquids'], \App\Models\WarrantyItem::find($ap)->broken_cases);

        // Imágenes del ticket: solo las imágenes de SU ticket.
        $imgs = $this->getJson("/api/warranties.php?action=ticket_images&id={$w['id']}", $h)->assertOk()->json('images');
        $this->assertSame(["a:{$foto}"], array_column($imgs, 'src'));
        $media = TokenService::mediaToken($u);
        $this->get("/api/warranty_photo.php?w={$w['id']}&src=a:{$foto}&token={$media}")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get("/api/warranty_photo.php?w={$w['id']}&src=a:{$ajena}&token={$media}")->assertNotFound();
        // Fotos del cliente: también las de sus otros tickets, con ticket y canal; y se descargan.
        $todas = $this->getJson("/api/warranties.php?action=ticket_images&id={$w['id']}&all=1", $h)->assertOk()->json();
        $this->assertSame([2, ["a:{$foto}", "a:{$antes}"], ['TK-1', 'TK-3']], [$todas['tickets'], array_column($todas['images'], 'src'), array_column($todas['images'], 'ticket')]);
        $this->get("/api/warranty_photo.php?w={$w['id']}&src=a:{$antes}&dl=1&token={$media}")->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="antes.jpg"');

        // Fotos: subida, desde el ticket, tipo que no es de una antena, y sustituir.
        $foto1 = fn ($item, $type, $file) => $this->post('/api/warranties.php?action=photo_upload', ['item_id' => $item, 'type' => $type, 'file' => $file], $h);
        $foto1($ids[0], 'front', \Illuminate\Http\UploadedFile::fake()->image('f.png'))->assertOk();
        $foto1($ids[0], 'front', \Illuminate\Http\UploadedFile::fake()->create('virus.jpg', 10, 'image/jpeg'))->assertStatus(400);   // no es una imagen de verdad
        $foto1($ap, 'back_batteries', \Illuminate\Http\UploadedFile::fake()->image('f.png'))->assertStatus(400);                    // las antenas no tienen pilas
        $this->postJson('/api/warranties.php?action=photo_from_ticket', ['item_id' => $ids[0], 'type' => 'back_batteries', 'src' => "a:{$ajena}"], $h)->assertStatus(400);
        // Un adjunto que se llama .png sin serlo, y una imagen de 50 megapíxeles (solo la cabecera).
        \Illuminate\Support\Facades\Storage::disk('local')->put('attachments/x/falsa.png', 'no soy una imagen');
        $falsa = DB::table('attachments')->insertGetId(['ticket_id' => $tid, 'name' => 'falsa.png', 'path' => 'attachments/x/falsa.png', 'mime' => 'image/png', 'size' => 17]);
        $this->postJson('/api/warranties.php?action=photo_from_ticket', ['item_id' => $ids[0], 'type' => 'back_batteries', 'src' => "a:{$falsa}"], $h)->assertStatus(400);
        $gigante = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NN', 10000, 5000) . "\x08\x02\x00\x00\x00" . pack('N', 0);
        $foto1($ids[0], 'front', \Illuminate\Http\UploadedFile::fake()->createWithContent('g.png', $gigante))->assertStatus(400)->assertJsonFragment(['error' => 'La foto es demasiado grande (más de 40 megapíxeles): redúcela antes de subirla']);
        $p =$this->postJson('/api/warranties.php?action=photo_from_ticket', ['item_id' => $ids[0], 'type' => 'back_batteries', 'src' => "a:{$foto}"], $h)->assertOk()->json('photos');
        $this->assertSame(['front', 'back_batteries'], array_column($p, 'type'));
        $this->assertSame([null, "a:{$foto}"], array_column($p, 'source'));
        $foto1($ids[0], 'front', \Illuminate\Http\UploadedFile::fake()->image('g.jpg'))->assertOk();
        $this->assertSame(2, DB::table('warranty_item_photos')->where('warranty_item_id', $ids[0])->count());
        $this->assertCount(2, \Illuminate\Support\Facades\Storage::disk('local')->files('warranty_photos/' . date('Y/m')));   // la sustituida se borró

        $pid = DB::table('warranty_item_photos')->where('warranty_item_id', $ids[0])->where('type', 'front')->value('id');
        $this->get("/api/warranty_photo.php?id={$pid}&token={$media}")->assertOk();
        $this->get("/api/warranty_photo.php?id={$pid}&token=" . TokenService::make($u))->assertUnauthorized();   // por URL solo el de medios
        $this->postJson('/api/warranties.php?action=photo_delete', ['photo_id' => $pid], $h)->assertOk()->assertJsonCount(1, 'photos');

        // Historial y quitar el dispositivo se lleva sus ficheros.
        $ev = collect($this->getJson("/api/warranties.php?action=get&id={$w['id']}", $h)->json('events'))->where('field', 'review')->pluck('to_value')->all();
        $this->assertContains('Defectuosa (2): 8E9749440, 854A49907', $ev);
        $this->postJson('/api/warranties.php?action=label_delete', ['item_id' => $ids[0]], $h)->assertOk();
        $this->assertSame([], \Illuminate\Support\Facades\Storage::disk('local')->files('warranty_photos/' . date('Y/m')));
    }

    public function test_solo_superadmin_borra(): void
    {
        [, $h] = $this->agente();
        $id = $this->crear($h)['id'];
        $this->postJson('/api/warranties.php?action=delete', ['id' => $id], $h)->assertForbidden();

        $super = User::create(['name' => 'Root', 'email' => 'root@x.com', 'password' => bcrypt('x'), 'active' => 1]);
        $super->assignRole(config('rbac.super_role'));
        $this->postJson('/api/warranties.php?action=delete', ['id' => $id], ['X-App-Token' => TokenService::make($super)])->assertOk();
        $this->assertSame(0, Warranty::count());
        $this->assertSame(0, DB::table('warranty_events')->count());
    }

    public function test_dispositivo_en_varias_garantias_se_marca(): void
    {
        [, $h] = $this->agente();
        $g = fn (string $code, string $fecha, string $cli) => Warranty::create(['code' => $code, 'status' => 'en_curso', 'received_at' => $fecha,
            'client_name' => $cli, 'last_activity_at' => now(), 'status_changed_at' => now()]);
        $a = $g('G-2026-0001', '2026-03-01', 'Hotel Sol');
        $b = $g('G-2026-0002', '2026-06-01', 'Hotel Sol');
        $c = $g('G-2026-0003', '2026-09-01', 'Otro');
        $item = fn ($w, $code, $res = 'pendiente') => \App\Models\WarrantyItem::create(['warranty_id' => $w->id, 'code' => $code, 'kind' => 'esl', 'lookup_state' => 'found', 'resolution' => $res]);
        $item($a, '8E9749440', 'defectuosa');
        $item($b, '8E9749440', 'bateria');
        $item($c, '8E9749440');
        $item($c, '854A49907');

        $items = collect($this->getJson("/api/warranties.php?action=get&id={$c->id}", $h)->assertOk()->json('items'))->keyBy('code');
        $this->assertSame(['G-2026-0002', 'G-2026-0001'], array_column($items['8E9749440']['other_warranties'], 'code'));   // la más reciente primero
        $this->assertSame('bateria', $items['8E9749440']['other_warranties'][0]['resolution']);
        $this->assertSame([], $items['854A49907']['other_warranties']);

        $l = $this->getJson('/api/warranties.php?action=list', $h)->assertOk()->json();
        $this->assertSame(3, $l['counts']['repeated']);
        $this->assertSame(['G-2026-0001' => 1, 'G-2026-0002' => 1, 'G-2026-0003' => 1], collect($l['rows'])->pluck('repeated', 'code')->sortKeys()->all());
        $item($c, 'A0A3B85B5F7F');
        $this->assertSame(3, $this->getJson('/api/warranties.php?action=list&chip=repeated', $h)->json('total'));
    }
}
