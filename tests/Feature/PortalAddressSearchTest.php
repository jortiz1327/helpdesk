<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Buscador de direcciones del portal: solo con pase, sin clave tira de Photon y con clave
 * de Google (que el navegador nunca ve), y con cupo por correo.
 */
class PortalAddressSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
    }

    private function pase(string $email = 'cliente@empresa.com'): array
    {
        $token = 'pase-' . md5($email);
        DB::table('portal_sessions')->insert(['token_hash' => hash('sha256', $token), 'email' => $email, 'ip' => '127.0.0.1',
            'expires_at' => now()->addDays(30), 'created_at' => now()]);
        return ['X-Portal-Token' => $token];
    }

    public function test_sin_pase_no_busca(): void
    {
        $this->getJson('/api/portal.php?action=address-search&q=Rua+Augusta')->assertStatus(401);
    }

    public function test_photon_sin_clave(): void
    {
        config(['services.places.google_key' => null]);
        Http::fake(['photon.komoot.io/*' => Http::response(['features' => [['properties' => [
            'osm_type' => 'N', 'osm_id' => 1, 'street' => 'Rua Augusta', 'housenumber' => '100',
            'postcode' => '1100-050', 'city' => 'Lisboa', 'country' => 'Portugal',
        ]]]])]);

        $r = $this->getJson('/api/portal.php?action=address-search&lang=pt&q=Rua+Augusta+100', $this->pase())->assertOk();
        $r->assertJsonPath('provider', 'photon');
        $r->assertJsonPath('results.0.main', 'Rua Augusta 100');
        $r->assertJsonPath('results.0.address', "Rua Augusta 100\n1100-050 Lisboa\nPortugal");
    }

    public function test_google_con_clave_y_detalle(): void
    {
        config(['services.places.google_key' => 'clave-de-prueba']);
        Http::fake([
            'places.googleapis.com/v1/places:autocomplete' => Http::response(['suggestions' => [['placePrediction' => [
                'placeId' => 'ChIJabcdefghij', 'structuredFormat' => ['mainText' => ['text' => 'Hotel Riu Montego Bay'], 'secondaryText' => ['text' => 'Montego Bay, Jamaica']],
            ]]]]),
            'places.googleapis.com/v1/places/*' => Http::response([
                'displayName' => ['text' => 'Hotel Riu Montego Bay'], 'formattedAddress' => 'Mahoe Bay, Montego Bay, Jamaica',
                'types' => ['lodging', 'establishment'],
                'addressComponents' => [
                    ['longText' => 'Mahoe Bay', 'types' => ['route']],
                    ['longText' => 'Montego Bay', 'types' => ['locality']],
                    ['longText' => 'Saint James', 'types' => ['administrative_area_level_1']],
                    ['longText' => 'Jamaica', 'types' => ['country']],
                ],
            ]),
        ]);
        $h = $this->pase();

        $this->getJson('/api/portal.php?action=address-search&lang=en&session=s1&q=Hotel+Riu+Montego', $h)->assertOk()
            ->assertJsonPath('provider', 'google')->assertJsonPath('results.0.id', 'ChIJabcdefghij');
        $this->getJson('/api/portal.php?action=address-place&lang=en&session=s1&id=ChIJabcdefghij', $h)->assertOk()
            ->assertJsonPath('address', "Hotel Riu Montego Bay\nMahoe Bay\nMontego Bay\nSaint James, Jamaica");

        Http::assertSent(fn ($req) => $req->hasHeader('X-Goog-Api-Key', 'clave-de-prueba'));
    }

    public function test_si_google_falla_tira_de_photon(): void
    {
        config(['services.places.google_key' => 'clave-sin-places']);
        Http::fake([
            'places.googleapis.com/*' => Http::response(['error' => ['status' => 'PERMISSION_DENIED']], 403),
            'photon.komoot.io/*' => Http::response(['features' => [['properties' => [
                'osm_type' => 'N', 'osm_id' => 2, 'street' => 'Avenida Paseo de la Reforma', 'housenumber' => '222',
                'postcode' => '06600', 'city' => 'Ciudad de México', 'country' => 'México',
            ]]]]),
        ]);

        $this->getJson('/api/portal.php?action=address-search&q=Reforma+222', $this->pase())->assertOk()
            ->assertJsonPath('results.0.address', "Avenida Paseo de la Reforma 222\n06600 Ciudad de México\nMéxico");
    }

    public function test_cupo_por_minuto(): void
    {
        config(['services.places.google_key' => null]);
        Http::fake(['photon.komoot.io/*' => Http::response(['features' => []])]);
        $h = $this->pase();
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/portal.php?action=address-search&q=calle+' . $i, $h)->assertOk();
        }
        $this->getJson('/api/portal.php?action=address-search&q=calle+mas', $h)->assertStatus(429);
        // Buscar no gasta el freno general del portal (el que protege el pedido de código).
        $this->getJson('/api/portal.php?action=categories')->assertOk();
    }

    public function test_el_freno_general_sigue_en_30(): void
    {
        for ($i = 0; $i < 30; $i++) $this->getJson('/api/portal.php?action=categories')->assertOk();
        $this->getJson('/api/portal.php?action=categories')->assertStatus(429);
    }
}
