<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El helper Controller::validar() valida la FORMA de la entrada y deja que
 * bootstrap/app.php lo convierta en el JSON uniforme que espera el front
 * (`{ok:false, error:…}`, 422), en vez de los `if (!$id) return 400` a mano.
 */
class ValidationHelperTest extends TestCase
{
    use RefreshDatabase;

    public function test_una_accion_sin_id_devuelve_error_de_validacion_uniforme(): void
    {
        Role::findOrCreate('superadmin', 'web');
        $u = User::create(['name' => 'Jefe', 'email' => 'j@x.com', 'password' => bcrypt('x')]);
        $u->assignRole('superadmin');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $token = TokenService::make($u);

        // «category» ya usa el helper: sin id debe salir el error limpio, no un 500 ni el
        // formato por defecto de Laravel.
        $this->withHeader('X-App-Token', $token)
            ->postJson('/api/tickets.php?action=category', [])
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'error' => 'Falta el ticket']);
    }
}
