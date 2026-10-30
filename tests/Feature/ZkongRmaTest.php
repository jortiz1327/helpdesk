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

    private function agente(array $permisos = ['warranties.access']): array
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
}
