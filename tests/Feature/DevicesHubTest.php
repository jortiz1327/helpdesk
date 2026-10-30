<?php

namespace Tests\Feature;

use App\Models\DeviceSighting;
use App\Models\User;
use App\Models\ZkongServer;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Centro de dispositivos: servidor ZKONG simulado. Comprueba el login (contraseña cifrada
 * con la clave pública del servidor), las listas, la búsqueda en varios servidores, la
 * ficha con historial y que la contraseña y el token nunca salen hacia el navegador.
 */
class DevicesHubTest extends TestCase
{
    use RefreshDatabase;

    private string $privada = '';
    private array $logins = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);

        // Par de claves de usar y tirar. En Windows (XAMPP) OpenSSL necesita que se le
        // indique su openssl.cnf; en Linux no hace falta.
        $cfg = array_filter(['config' => getenv('OPENSSL_CONF') ?: (is_file('C:/xampp/php/extras/ssl/openssl.cnf') ? 'C:/xampp/php/extras/ssl/openssl.cnf' : null)]);
        $key = openssl_pkey_new(['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA] + $cfg);
        openssl_pkey_export($key, $this->privada, null, $cfg);
        $publica = openssl_pkey_get_details($key)['key'];
        $base64 = trim(preg_replace('/-----[^-]+-----/', '', $publica));

        Http::fake(function ($request) use ($base64) {
            $url = $request->url();
            $host = parse_url($url, PHP_URL_HOST);
            if ($host === 'roto.test') {
                return Http::response('<html>502 Bad Gateway</html>', 502);
            }
            if (str_contains($url, '/user/getErpPublicKey')) {
                return Http::response(['success' => true, 'code' => 10000, 'data' => $base64]);
            }
            if (str_contains($url, '/user/login')) {
                openssl_private_decrypt(base64_decode($request['password']), $plano, $this->privada);
                $this->logins[] = [$host, $request['account'], $plano];
                return Http::response(['success' => true, 'code' => 10000, 'data' => ['token' => 'tok-' . $host, 'currentUser' => ['merchantId' => 7, 'agencyId' => 8]]]);
            }
            if (($request->header('Authorization')[0] ?? '') !== 'tok-' . $host) {
                return Http::response(['success' => false, 'code' => 10006, 'message' => 'token caducado']);
            }
            if (str_contains($url, '/store/storeTree')) {
                return Http::response(['success' => true, 'code' => 10000, 'data' => ['storeTree' => [
                    ['id' => 1, 'label' => 'Agencia', 'children' => [
                        ['id' => 2, 'label' => 'Farmacias Sol [x]', 'children' => [['id' => 100, 'label' => 'Tienda Centro [abc]']]],
                    ]],
                ]]]);
            }
            if (str_contains($url, '/pricetag/adminEsl')) {
                // La etiqueta 805804112 vive en los dos servidores, pero solo conectada en app02.
                $code = $request->data()['barCode'] ?? null;
                $rows = [];
                if ($code === null || $code === '805804112') {
                    $rows[] = ['id' => 1, 'barCode' => '805804112', 'model' => 'ZKC42V-N', 'state' => $host === 'app02.test' ? 1 : 0,
                        'bindState' => 1, 'battery' => 80, 'softVersion' => '2.2.50', 'storeId' => 100, 'apMac' => 'AA:BB:CC:DD:EE:FF',
                        'lastCommuTime' => '2026-10-29 10:00:00', 'addTime' => '2026-01-01 09:00:00'];
                }
                return Http::response(['success' => true, 'code' => 10000, 'data' => ['rows' => $rows, 'total' => count($rows)]]);
            }
            if (str_contains($url, '/erp/esl/list') && ($request['priceTagCode'] ?? null) === '805804112') {
                return Http::response(['success' => true, 'code' => 10000, 'data' => ['list' => [['priceTagCode' => '805804112', 'itemTitle' => 'Ibuprofeno 600', 'itemBarCode' => '8470001']]]]);
            }
            if (str_contains($url, '/apStation/adminAp')) {
                return Http::response(['success' => true, 'code' => 10000, 'data' => ['rows' => [], 'total' => 0]]);
            }
            if (str_contains($url, '/pricetagModel/getAll')) {
                return Http::response(['success' => true, 'code' => 10000, 'data' => [['model' => 'ZKC42V-N'], ['model' => 'ZKC15S']]]);
            }
            if (str_contains($url, '/pricetag/allEslSoftVersion')) {
                return Http::response(['success' => true, 'code' => 10000, 'data' => ['2.2.50', '1.0.8']]);
            }
            return Http::response(['success' => false, 'code' => 500, 'message' => 'no simulado'], 404);
        });
    }

    private function token(array $permisos): string
    {
        $u = User::create(['name' => 'U', 'email' => uniqid() . '@x.com', 'password' => bcrypt('x')]);
        $u->givePermissionTo($permisos);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        return TokenService::make($u);
    }

    private function servidor(string $host, int $pos = 1): ZkongServer
    {
        return ZkongServer::create(['name' => strtoupper(explode('.', $host)[0]), 'base_url' => "https://{$host}/zk",
            'username' => 'admin', 'password' => 'secreta', 'active' => true, 'position' => $pos]);
    }

    public function test_sin_permiso_de_garantias_no_entra_y_los_servidores_son_solo_superadmin(): void
    {
        $this->getJson('/api/devices.php', ['X-App-Token' => $this->token(['helpdesk.access'])])->assertForbidden();
        $this->getJson('/api/zkong_servers.php', ['X-App-Token' => $this->token(['warranties.access'])])->assertForbidden();
    }

    public function test_guardar_servidor_cifra_la_contrasena_y_no_la_devuelve(): void
    {
        $tok = $this->token(['warranties.access', 'warranties.servers']);
        $this->postJson('/api/zkong_servers.php?action=save', ['name' => 'APP02', 'base_url' => 'ftp://app02.test/zk', 'username' => 'admin', 'password' => 'x'], ['X-App-Token' => $tok])
            ->assertStatus(400);   // solo http(s)
        $this->postJson('/api/zkong_servers.php?action=save', ['name' => 'APP02', 'base_url' => 'https://app02.test/zk/', 'username' => 'admin', 'password' => 'secreta'], ['X-App-Token' => $tok])
            ->assertOk()->assertJson(['ok' => true]);

        $raw = \DB::table('zkong_servers')->first();
        $this->assertNotSame('secreta', $raw->password);
        $this->assertSame('https://app02.test/zk', $raw->base_url);

        $list = $this->getJson('/api/zkong_servers.php', ['X-App-Token' => $tok])->assertOk()->getContent();
        $this->assertStringNotContainsString('secreta', $list);
        $this->assertStringNotContainsString('password', $list);

        // Probar: entra con la contraseña descifrada y no devuelve el token.
        $test = $this->postJson('/api/zkong_servers.php?action=test', ['id' => $raw->id], ['X-App-Token' => $tok])->assertJson(['ok' => true]);
        $this->assertStringNotContainsString('tok-', $test->getContent());
        $this->assertSame([['app02.test', 'admin', 'secreta']], $this->logins);
    }

    public function test_lista_de_etiquetas_con_tienda_y_articulo(): void
    {
        $s = $this->servidor('app02.test');
        $r = $this->getJson("/api/devices.php?action=list&server={$s->id}&kind=esl&page=0&size=25&f=%7B%7D", ['X-App-Token' => $this->token(['warranties.access'])])
            ->assertOk()->json();

        $this->assertSame(1, $r['total']);
        $this->assertSame('Tienda Centro', $r['rows'][0]['storeName']);
        $this->assertSame('Farmacias Sol', $r['rows'][0]['merchantName']);
        $this->assertSame('APP02', $r['rows'][0]['server']);

        // El artículo va aparte (la lista no lo espera).
        $it = $this->postJson("/api/devices.php?action=items&server={$s->id}", ['items' => [['storeId' => 100, 'barCode' => '805804112']]], ['X-App-Token' => $this->token(['warranties.access'])])
            ->assertOk()->json();
        $this->assertSame('Ibuprofeno 600', $it['items']['805804112']['itemTitle']);
        $this->assertCount(1, $this->logins);   // un login; el resto reutiliza el token
    }

    public function test_filtros_del_servidor(): void
    {
        $s = $this->servidor('app02.test');
        $r = $this->getJson("/api/devices.php?action=filters&server={$s->id}&kind=esl", ['X-App-Token' => $this->token(['warranties.access'])])->assertOk()->json();
        $this->assertSame(['ZKC15S', 'ZKC42V-N'], $r['models']);
        $this->assertSame([['value' => '100', 'label' => 'Tienda Centro', 'sub' => 'Farmacias Sol']], $r['stores']);
    }

    public function test_cifras_del_servidor(): void
    {
        $s = $this->servidor('app02.test');
        $r = $this->getJson("/api/devices.php?action=stats&server={$s->id}&kind=esl", ['X-App-Token' => $this->token(['warranties.access'])])->assertOk()->json();
        $this->assertSame(1, $r['stats']['total']);
    }

    public function test_busqueda_en_todos_los_servidores(): void
    {
        $this->servidor('app01.test', 1);
        $this->servidor('app02.test', 2);
        $r = $this->postJson('/api/devices.php?action=search', ['codes' => "805804112\n999999999"], ['X-App-Token' => $this->token(['warranties.access'])])
            ->assertOk()->json();

        $porCodigo = collect($r['results'])->keyBy('code');
        $this->assertCount(2, $porCodigo['805804112']['rows']);
        $this->assertSame([], $porCodigo['999999999']['rows']);
        $this->assertSame([], $r['failed']);
    }

    public function test_ficha_elige_el_servidor_vivo_y_guarda_historial(): void
    {
        $this->servidor('app01.test', 1);
        $this->servidor('app02.test', 2);
        $tok = $this->token(['warranties.access']);

        $r = $this->getJson('/api/devices.php?action=sheet&code=805-804-112', ['X-App-Token' => $tok])->assertOk()->json();
        $this->assertTrue($r['found']);
        $this->assertSame('APP02', $r['device']['server']);
        $this->assertSame('APP01', $r['others'][0]['server']);
        $this->assertCount(1, $r['history']);

        // Consultar otra vez sin cambios no abre fila nueva.
        $this->getJson('/api/devices.php?action=sheet&code=805804112', ['X-App-Token' => $tok])->assertOk();
        $this->assertSame(1, DeviceSighting::where('code', '805804112')->count());
    }

    public function test_servidor_caido_se_cuenta_sin_romper(): void
    {
        $this->servidor('app02.test', 1);
        ZkongServer::create(['name' => 'ROTO', 'base_url' => 'https://roto.test/zk', 'username' => 'a', 'password' => 'b', 'active' => true]);
        $r = $this->postJson('/api/devices.php?action=search', ['codes' => '805804112'], ['X-App-Token' => $this->token(['warranties.access'])])->assertOk()->json();
        $this->assertSame(['ROTO'], $r['failed']);
        $this->assertCount(1, $r['results'][0]['rows']);
    }
}
