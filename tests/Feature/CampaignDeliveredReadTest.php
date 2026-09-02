<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * delivered/read se calculaban por dos caminos distintos —subconsulta SQL en la LISTA,
 * recuento en el frontend desde los destinatarios en el DETALLE—, así que podían mostrar
 * cifras distintas. Ahora ambos endpoints los sacan de la MISMA subconsulta: este test
 * fija que lista y detalle devuelven exactamente lo mismo.
 */
class CampaignDeliveredReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_lista_y_detalle_cuadran_en_delivered_y_read(): void
    {
        Role::findOrCreate('superadmin', 'web');
        $u = User::create(['name' => 'Jefe', 'email' => 'j@x.com', 'password' => bcrypt('x')]);
        $u->assignRole('superadmin');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $token = TokenService::make($u);

        $cid = DB::table('campaigns')->insertGetId(['title' => 'Promo', 'template_name' => 'promo_tpl']);
        // Mezcla de estados: delivered = delivered+read = 2+3 = 5 · read = 3.
        $mete = function (string $status, int $n) use ($cid) {
            for ($i = 0; $i < $n; $i++) {
                DB::table('campaign_recipients')->insert(['campaign_id' => $cid, 'wa_id' => '34' . uniqid(), 'status' => $status]);
            }
        };
        $mete('pending', 2);
        $mete('sent', 1);
        $mete('delivered', 2);
        $mete('read', 3);
        $mete('failed', 1);

        // LISTA
        $lista = $this->withHeader('X-App-Token', $token)->getJson('/api/campaigns.php')->assertOk()->json('campaigns');
        $fila  = collect($lista)->firstWhere('id', $cid);
        $this->assertNotNull($fila);

        // DETALLE
        $det = $this->withHeader('X-App-Token', $token)->getJson("/api/campaigns.php?id=$cid")->assertOk()->json('campaign');

        // Cuadran entre sí y con lo esperado.
        $this->assertSame(5, (int) $fila['delivered']);
        $this->assertSame(3, (int) $fila['read_count']);
        $this->assertSame((int) $fila['delivered'], (int) $det['delivered'], 'delivered no cuadra lista↔detalle');
        $this->assertSame((int) $fila['read_count'], (int) $det['read_count'], 'read no cuadra lista↔detalle');
    }
}
