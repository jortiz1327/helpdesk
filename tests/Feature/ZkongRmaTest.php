<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Warranty;
use App\Models\WarrantyItem;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ZKONG: la bandeja se llena con lo revisado como «Enviar a ZKONG», las remesas se
 * crean desde ella, se envían (Excel y ZIP de fotos) y recogen la respuesta de ZKONG.
 */
class ZkongRmaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
    }

    private function agente(array $permisos = [...self::GARANTIAS]): array
    {
        $u = User::create(['name' => 'Ana', 'email' => uniqid() . '@x.com', 'password' => bcrypt('x'), 'active' => 1]);
        $u->givePermissionTo($permisos);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        return [$u, ['X-App-Token' => TokenService::make($u)]];
    }

    /** Garantía con dispositivos ya comprobados: [código => kind]. */
    private function garantia(string $code, array $codigos): array
    {
        $w = Warranty::create(['code' => $code, 'received_at' => '2026-10-01', 'client_name' => 'Hoteles Sol', 'status' => 'en_curso']);
        $ids = [];
        foreach ($codigos as $c => $kind) {
            $ids[$c] = WarrantyItem::create(['warranty_id' => $w->id, 'code' => $c, 'kind' => $kind, 'lookup_state' => 'found',
                'server_model' => $kind === 'ap' ? null : 'ZKC26V-NAE4', 'aeme_ref' => $kind === 'ap' ? 'AMAP-C' : 'AMC26V'])->id;
        }
        return [$w, $ids];
    }

    private function rma(string $action, array $d, array $h)
    {
        return $this->postJson("/api/zkong_rma.php?action={$action}", $d, $h);
    }

    public function test_bandeja_remesa_envio_y_respuesta_de_zkong(): void
    {
        Storage::fake('local');
        [, $h] = $this->agente();
        [, $sinPermiso] = $this->agente(['helpdesk.access']);
        $this->getJson('/api/zkong_rma.php', $sinPermiso)->assertForbidden();

        [$w1, $a] = $this->garantia('G-2026-0001', ['8E9749440' => 'esl', '854A49907' => 'esl', 'A0A3B85B5F7F' => 'ap']);
        [$w2, $b] = $this->garantia('G-2026-0002', ['8E9749441' => 'esl']);
        $rev = fn ($w, array $ids, array $d) => $this->postJson('/api/warranties.php?action=review', $d + ['id' => $w->id, 'item_ids' => $ids], $h);

        // Entran en la bandeja solo las que se mandan; «pendiente de decidir» va aparte.
        $rev($w1, [$a['8E9749440'], $a['A0A3B85B5F7F']], ['resolution' => 'defectuosa', 'zkong_decision' => 'enviar', 'zkong_issue' => 'No enciende'])->assertOk();
        $rev($w1, [$a['854A49907']], ['resolution' => 'no_reproduce', 'zkong_decision' => 'decidir'])->assertOk();
        $rev($w2, [$b['8E9749441']], ['resolution' => 'no_reproduce', 'zkong_decision' => 'enviar', 'zkong_issue' => 'Pantalla rojiza'])->assertOk();

        $in = $this->getJson('/api/zkong_rma.php', $h)->assertOk()->json();
        $this->assertSame(['G-2026-0001', 'G-2026-0002'], collect($in['groups'])->pluck('code')->sort()->values()->all());
        $this->assertSame(2, count(collect($in['groups'])->firstWhere('code', 'G-2026-0001')['items']));
        $this->assertSame([['G-2026-0001', 1]], collect($in['undecided'])->map(fn ($u) => [$u['code'], (int) $u['n']])->all());
        $ap = collect(collect($in['groups'])->firstWhere('code', 'G-2026-0001')['items'])->firstWhere('kind', 'ap');
        $this->assertSame(['AMAP-C', 2], [$ap['model'], $ap['photos_total']]);   // sin modelo en ZKONG, la referencia AEME

        // Re-revisar sin cambiar la decisión conserva la fecha de entrada.
        $entrada = WarrantyItem::find($a['8E9749440'])->zkong_queued_at;
        $this->travel(2)->days();
        $rev($w1, [$a['8E9749440']], ['resolution' => 'defectuosa', 'zkong_decision' => 'enviar', 'zkong_issue' => 'No enciende nunca'])->assertOk();
        $this->assertEquals($entrada, WarrantyItem::find($a['8E9749440'])->zkong_queued_at);

        // Remesa: solo con lo que está en la bandeja (la «decidir» no entra).
        $this->rma('create', ['item_ids' => [$a['854A49907']]], $h)->assertStatus(400);
        $c = $this->rma('create', ['item_ids' => [$a['8E9749440'], $a['A0A3B85B5F7F'], $a['854A49907']]], $h)->assertOk()->json();
        $this->assertSame('R-' . now()->year . '-001', $c['code']);
        $this->assertSame(2, WarrantyItem::where('zkong_batch_id', $c['id'])->count());
        $this->rma('add', ['id' => $c['id'], 'item_ids' => [$b['8E9749441']]], $h)->assertOk();
        $this->assertSame([], $this->getJson('/api/zkong_rma.php', $h)->json('groups'));

        // En borrador: se quita una (vuelve a la bandeja) y cambiar su revisión la saca.
        $this->rma('remove', ['id' => $c['id'], 'item_ids' => [$a['A0A3B85B5F7F']]], $h)->assertOk();
        $this->assertSame(1, count($this->getJson('/api/zkong_rma.php', $h)->json('groups')));
        $rev($w2, [$b['8E9749441']], ['resolution' => 'correcta'])->assertOk();
        $this->assertNull(WarrantyItem::find($b['8E9749441'])->zkong_batch_id);
        $this->assertNull(WarrantyItem::find($b['8E9749441'])->zkong_queued_at);

        // Respuesta solo tras enviar; enviada, ya no se tocan sus etiquetas.
        $this->rma('result', ['id' => $c['id'], 'item_ids' => [$a['8E9749440']], 'result' => 'abono'], $h)->assertStatus(400);
        $this->rma('send', ['id' => $c['id']], $h)->assertOk();
        $this->rma('add', ['id' => $c['id'], 'item_ids' => [$a['A0A3B85B5F7F']]], $h)->assertStatus(400);
        $rev($w1, [$a['8E9749440']], ['resolution' => 'correcta'])->assertStatus(400);
        $this->postJson('/api/warranties.php?action=label_model', ['item_id' => $a['8E9749440'], 'aeme_ref' => ''], $h)->assertStatus(400);
        $this->postJson('/api/warranties.php?action=label_delete', ['item_id' => $a['8E9749440']], $h)->assertStatus(400);
        $this->assertNotNull(\App\Models\WarrantyItem::find($a['8E9749440']));
        $this->rma('delete', ['id' => $c['id']], $h)->assertStatus(400);

        // Excel con las columnas de ZKONG, agrupado por modelo y fallo.
        $x = $this->get("/api/zkong_rma.php?action=xlsx&id={$c['id']}", $h)->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'x') . '.xlsx';
        file_put_contents($tmp, $x->getContent());
        $hoja = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp)->getActiveSheet();
        $this->assertSame(['No', 'Model Name', 'Qty', 'Issue Description', 'SN Info', 'Remark'], $hoja->rangeToArray('A4:F4')[0]);
        $this->assertSame([1, 'ZKC26V-NAE4', 1, 'No enciende nunca', '8E9749440', 'G-2026-0001'], $hoja->rangeToArray('A5:F5', null, true, false)[0]);
        @unlink($tmp);

        // ZIP de fotos: sin fotos, 404; con una, carpeta por expediente y nombre por código.
        $this->get("/api/zkong_rma.php?action=zip&id={$c['id']}", $h)->assertNotFound();
        Storage::disk('local')->put('warranty_photos/x/f.jpg', 'jpg');
        DB::table('warranty_item_photos')->insert(['warranty_item_id' => $a['8E9749440'], 'type' => 'front', 'path' => 'warranty_photos/x/f.jpg', 'name' => 'f.jpg', 'created_at' => now()]);
        $z = $this->get("/api/zkong_rma.php?action=zip&id={$c['id']}", $h)->assertOk();
        $zip = new \ZipArchive();
        $zip->open($z->baseResponse->getFile()->getPathname());
        $this->assertSame('G-2026-0001/8E9749440_front.jpg', $zip->getNameIndex(0));
        $zip->close();

        // Respuesta de ZKONG: con todas, respondida; quitando una, vuelve a enviada.
        $this->rma('result', ['id' => $c['id'], 'item_ids' => [$a['8E9749440']], 'result' => 'inventada'], $h)->assertStatus(400);
        $r = $this->rma('result', ['id' => $c['id'], 'item_ids' => [$a['8E9749440']], 'result' => 'reposicion', 'note' => 'RMA-77'], $h)->assertOk()->json();
        $this->assertSame('respondida', $r['status']);
        $this->assertSame(['reposicion', 'RMA-77'], [$r['items'][0]['result'], $r['items'][0]['result_note']]);
        $this->assertSame('enviada', $this->rma('result', ['id' => $c['id'], 'item_ids' => [$a['8E9749440']], 'result' => ''], $h)->json('status'));
        $this->rma('result', ['id' => $c['id'], 'item_ids' => [$a['8E9749440']], 'result' => 'abono'], $h)->assertOk();

        $l = collect($this->getJson('/api/zkong_rma.php?action=batches', $h)->json('batches'))->firstWhere('id', $c['id']);
        $this->assertSame(['respondida', 1, 1, 1], [$l['status'], $l['items'], $l['warranties'], $l['answered']]);

        $this->rma('close', ['id' => $c['id']], $h)->assertOk();
        $this->rma('result', ['id' => $c['id'], 'item_ids' => [$a['8E9749440']], 'result' => 'rechazada'], $h)->assertStatus(400);
        $this->rma('reopen', ['id' => $c['id']], $h)->assertOk();
        $this->assertSame('respondida', DB::table('zkong_batches')->where('id', $c['id'])->value('status'));
        // Cerrar queda en el historial de la garantía; reabrir, solo en el de la remesa.
        $hist = DB::table('warranty_events')->where('warranty_id', $w1->id)->where('field', 'zkong')->pluck('to_value')->implode(' | ');
        $this->assertStringContainsString("Remesa {$c['code']} cerrada", $hist);
        $this->assertStringNotContainsString('reabierta', $hist);

        // La ficha de la garantía lo ve, y queda en su historial.
        $g = $this->getJson("/api/warranties.php?action=get&id={$w1->id}", $h)->json();
        $it = collect($g['items'])->firstWhere('code', '8E9749440');
        $this->assertSame([$c['code'], 'respondida', 'abono'], [$it['batch_code'], $it['batch_status'], $it['zkong_result']]);
        $zk = collect($g['events'])->where('field', 'zkong')->pluck('to_value')->implode(' | ');
        $this->assertStringContainsString("Enviada a ZKONG en la remesa {$c['code']}", $zk);
        $this->assertStringContainsString('Respuesta de ZKONG', $zk);
    }

    public function test_descartar_un_borrador_devuelve_las_etiquetas_a_la_bandeja(): void
    {
        [, $h] = $this->agente();
        [$w, $a] = $this->garantia('G-2026-0003', ['8E9749440' => 'esl']);
        $this->postJson('/api/warranties.php?action=review', ['id' => $w->id, 'item_ids' => [$a['8E9749440']], 'resolution' => 'defectuosa', 'zkong_decision' => 'enviar', 'zkong_issue' => 'X'], $h)->assertOk();
        $c = $this->rma('create', ['item_ids' => [$a['8E9749440']]], $h)->json();
        $this->rma('delete', ['id' => $c['id']], $h)->assertOk();
        $this->assertSame(0, DB::table('zkong_batches')->count());
        $this->assertSame(1, count($this->getJson('/api/zkong_rma.php', $h)->json('groups')));
        // El número no se reutiliza mal: la siguiente vuelve a ser la 001 al no quedar ninguna.
        $this->assertSame('R-' . now()->year . '-001', $this->rma('create', ['item_ids' => [$a['8E9749440']]], $h)->json('code'));
    }

    public function test_envio_a_mano_recepcion_de_zkong_y_anulacion(): void
    {
        [, $h] = $this->agente();
        [$w, $a] = $this->garantia('G-2026-0004', ['8E9749440' => 'esl', '8E9749441' => 'esl']);
        $this->postJson('/api/warranties.php?action=review', ['id' => $w->id, 'item_ids' => array_values($a), 'resolution' => 'defectuosa', 'zkong_decision' => 'enviar', 'zkong_issue' => 'X'], $h)->assertOk();
        $id = $this->rma('create', ['item_ids' => array_values($a)], $h)->json('id');

        // Envío: la fecha no puede ser futura; el resto del transporte se apunta a mano.
        $this->rma('send', ['id' => $id, 'sent_on' => now()->addDay()->toDateString()], $h)->assertStatus(400);
        $this->rma('send', ['id' => $id, 'sent_on' => now()->subDays(3)->toDateString(), 'units_sent' => 2, 'carrier' => 'DHL', 'tracking' => 'JD0001', 'shipping_ref' => 'A-77', 'note' => 'Caja 1'], $h)->assertOk();
        $b = $this->getJson("/api/zkong_rma.php?action=batch&id={$id}", $h)->json('batch');
        $this->assertSame(['enviada', 'DHL', 'JD0001', 'A-77', 2, 3], [$b['status'], $b['carrier'], $b['tracking'], $b['shipping_ref'], $b['units_sent'], $b['waiting_days']]);

        // El tracking se puede completar después; la recepción no puede ser anterior al envío.
        $this->rma('shipping', ['id' => $id, 'tracking' => 'JD0002', 'carrier' => 'DHL'], $h)->assertOk();
        $this->assertSame(1, DB::table('warranty_events')->where('field', 'zkong')->where('to_value', 'like', 'Envío de la remesa % actualizado (%tracking JD0002%')->distinct('warranty_id')->count('warranty_id'));
        $this->rma('receive', ['id' => $id, 'received_on' => now()->subDays(5)->toDateString()], $h)->assertStatus(400);
        $this->rma('receive', ['id' => $id, 'received_on' => now()->toDateString(), 'units_received' => 2], $h)->assertOk();
        $this->rma('receive', ['id' => $id, 'received_on' => now()->toDateString()], $h)->assertStatus(400);   // ya apuntada

        $d = $this->getJson("/api/zkong_rma.php?action=batch&id={$id}", $h)->json();
        $this->assertSame(['recibida', 'JD0002', 2, null], [$d['batch']['status'], $d['batch']['tracking'], $d['batch']['units_received'], $d['batch']['waiting_days']]);
        $this->assertSame(['creada', 'enviada', 'envio', 'recibida'], array_column($d['events'], 'type'));
        $this->assertSame('Caja 1', $d['events'][1]['note']);

        // Una respuesta parcial la deja en Recibida; anular pide motivo y devuelve las etiquetas a la bandeja.
        $this->rma('result', ['id' => $id, 'item_ids' => [$a['8E9749440']], 'result' => 'abono'], $h)->assertOk()->assertJsonPath('status', 'recibida');
        $this->rma('cancel', ['id' => $id], $h)->assertStatus(400);
        $this->rma('cancel', ['id' => $id, 'reason' => 'Paquete perdido'], $h)->assertOk();
        $this->assertSame(['anulada', 'Paquete perdido'], [DB::table('zkong_batches')->where('id', $id)->value('status'), DB::table('zkong_batches')->where('id', $id)->value('cancel_reason')]);
        $this->assertSame(0, WarrantyItem::whereNotNull('zkong_batch_id')->orWhereNotNull('zkong_result')->count());
        $this->assertSame(2, count($this->getJson('/api/zkong_rma.php', $h)->json('groups.0.items')));
        $this->rma('result', ['id' => $id, 'item_ids' => [$a['8E9749440']], 'result' => 'abono'], $h)->assertStatus(400);
    }

    /** El coste como el Excel de stock del portal: precio de coste (€) y, si falta o es 0, el del proveedor ($, con coma). */
    public function test_coste_del_portal_con_la_regla_del_excel_de_stock(): void
    {
        $this->assertSame(['cost' => 12.5, 'source' => 'coste'], \App\Services\ZkongCosts::costeDe('12.5', '9'));
        $this->assertSame(['cost' => 0.23, 'source' => 'proveedor'], \App\Services\ZkongCosts::costeDe('0', '0,22'));   // 0,22 $ × 1,04
        $this->assertSame(['cost' => 10.4, 'source' => 'proveedor'], \App\Services\ZkongCosts::costeDe(null, '10'));
        $this->assertNull(\App\Services\ZkongCosts::costeDe('', ''));
    }

    /** Nº de RMA, vuelta de las reposiciones y coste recuperado frente a asumido. */
    public function test_rma_vuelta_y_coste_recuperado_frente_a_asumido(): void
    {
        // El portal (falso): AMC26V con precio de coste; AMAP-C solo con el del proveedor; AMC42 sin coste.
        $this->app->instance(\App\Services\ZkongCosts::class, new class extends \App\Services\ZkongCosts {
            public array $precios = ['AMC26V' => ['cost' => 10.0, 'source' => 'coste'], 'AMAP-C' => ['cost' => 31.2, 'source' => 'proveedor']];
            public function forRefs(array $refs): array { return array_intersect_key($this->precios, array_flip(array_map('strtoupper', $refs))); }
        });
        [, $h] = $this->agente();
        [$w, $a] = $this->garantia('G-2026-0005', ['8E9749440' => 'esl', '8E9749441' => 'esl', '8E9749442' => 'esl', 'A0A3B85B5F7F' => 'ap']);
        WarrantyItem::whereKey($a['8E9749442'])->update(['aeme_ref' => 'AMC42']);
        $this->postJson('/api/warranties.php?action=review', ['id' => $w->id, 'item_ids' => array_values($a), 'resolution' => 'defectuosa', 'zkong_decision' => 'enviar', 'zkong_issue' => 'X'], $h)->assertOk();
        $id = $this->rma('create', ['item_ids' => array_values($a)], $h)->json('id');

        $this->rma('send', ['id' => $id, 'sent_on' => now()->subDays(10)->toDateString(), 'units_sent' => 4, 'rma_number' => ' RMA-7781 '], $h)->assertOk();
        // El coste se copia al enviar: si luego cambia en el portal, la remesa no cambia.
        $this->assertSame(['10.00', 'coste'], [(string) WarrantyItem::find($a['8E9749440'])->zkong_unit_cost, WarrantyItem::find($a['8E9749440'])->zkong_cost_source]);
        app(\App\Services\ZkongCosts::class)->precios['AMC26V']['cost'] = 99.0;

        // Sin recibir no hay vuelta; después sí, y no antes de la recepción.
        $this->rma('return', ['id' => $id, 'returned_on' => now()->toDateString()], $h)->assertStatus(400);
        $this->rma('receive', ['id' => $id, 'received_on' => now()->subDays(6)->toDateString()], $h)->assertOk();
        $this->rma('result', ['id' => $id, 'item_ids' => [$a['8E9749440']], 'result' => 'reposicion'], $h)->assertOk();
        $this->rma('result', ['id' => $id, 'item_ids' => [$a['A0A3B85B5F7F']], 'result' => 'abono'], $h)->assertOk();
        $this->rma('result', ['id' => $id, 'item_ids' => [$a['8E9749441'], $a['8E9749442']], 'result' => 'rechazada'], $h)->assertOk();
        $this->rma('return', ['id' => $id, 'returned_on' => now()->subDays(8)->toDateString()], $h)->assertStatus(400);
        $this->rma('return', ['id' => $id, 'returned_on' => now()->subDay()->toDateString(), 'units_returned' => 1, 'return_carrier' => 'DHL', 'return_tracking' => 'BACK1'], $h)->assertOk();

        // Otro nº de RMA (queda en la línea de tiempo).
        $this->rma('rma', ['id' => $id, 'rma_number' => 'RMA-7782'], $h)->assertOk();

        $d = $this->getJson("/api/zkong_rma.php?action=batch&id={$id}", $h)->assertOk()->json();
        $this->assertSame(['RMA-7782', 1, 'DHL', 'BACK1'], [$d['batch']['rma_number'], $d['batch']['units_returned'], $d['batch']['return_carrier'], $d['batch']['return_tracking']]);
        $this->assertSame(now()->subDay()->toDateString(), substr($d['batch']['returned_at'], 0, 10));
        // Recuperado: reposición (10) + abono (31,2); asumido: la rechazada con coste (10); la AMC42 no tiene coste.
        $this->assertEquals(['recovered' => 41.2, 'assumed' => 10.0, 'pending' => 0.0, 'total' => 51.2, 'no_cost' => 1, 'estimated' => 1], $d['costs']);
        $this->assertEquals([10, 'proveedor', null], [collect($d['items'])->firstWhere('code', '8E9749441')['cost'], collect($d['items'])->firstWhere('code', 'A0A3B85B5F7F')['cost_source'], collect($d['items'])->firstWhere('code', '8E9749442')['cost']]);
        $this->assertContains('Nº de RMA cambiado: RMA-7781 → RMA-7782', array_column($d['events'], 'text'));
        $this->assertContains('vuelta', array_column($d['events'], 'type'));

        $lista = collect($this->getJson('/api/zkong_rma.php?action=batches', $h)->json('batches'))->firstWhere('id', $id);
        $this->assertEquals([41.2, 10], [$lista['costs']['recovered'], $lista['costs']['assumed']]);
    }
}
