<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El alta pública de incidencias no debe permitir abrir una «en nombre de otro»: si el
 * correo del formulario YA está registrado, hay que demostrar que eres tú (pase/código).
 * Así nadie dispara el acuse de recibo a una víctima. Un correo NUEVO entra sin fricción.
 */
class PortalCreateOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private function crear(array $body, array $headers = [])
    {
        return $this->withHeaders($headers)->postJson('/api/portal.php?action=create', $body);
    }

    public function test_un_correo_ya_registrado_exige_codigo(): void
    {
        DB::table('contacts')->insert(['name' => 'Ana', 'email' => 'ana@x.com']);

        $this->crear(['email' => 'ana@x.com', 'subject' => 'Fallo', 'body' => 'algo va mal por aqui'])
            ->assertStatus(401)
            ->assertJson(['reauth' => true]);

        $this->assertSame(0, DB::table('tickets')->count());   // no se creó nada
    }

    public function test_un_correo_nuevo_puede_crear_sin_codigo(): void
    {
        $this->crear(['email' => 'nuevo@x.com', 'subject' => 'Algo', 'body' => 'tengo un problema aqui'])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertSame(1, DB::table('tickets')->count());
    }

    public function test_con_pase_valido_del_correo_si_puede_crear(): void
    {
        DB::table('contacts')->insert(['name' => 'Cli', 'email' => 'cli@x.com']);
        $token = 'pase-de-prueba';
        DB::table('portal_sessions')->insert([
            'token_hash' => hash('sha256', $token), 'email' => 'cli@x.com', 'ip' => '127.0.0.1',
            'expires_at' => now()->addDays(30), 'created_at' => now(),
        ]);

        $this->crear(['email' => 'cli@x.com', 'subject' => 'Algo', 'body' => 'tengo un problema aqui'],
            ['X-Portal-Token' => $token])
            ->assertOk();

        $this->assertSame(1, DB::table('tickets')->count());
    }
}
