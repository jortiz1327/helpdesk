<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * INVARIANTE DE SEGURIDAD: los tokens son firmados (HMAC) y llevan una VERSIÓN. Un token
 * manipulado no debe valer, y al subir token_version (lo que hace el cambio de contraseña)
 * los tokens anteriores quedan REVOCADOS. Es la única palanca para «echar» a una sesión.
 */
class TokenRevocationTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(string $email = 'u@x.com'): User
    {
        return User::create(['name' => 'U', 'email' => $email, 'password' => Hash::make('vieja1234'), 'token_version' => 1]);
    }

    public function test_verify_rechaza_tokens_manipulados(): void
    {
        $tok = TokenService::make($this->usuario());
        [$payload, $sig] = explode('.', $tok);

        $this->assertNull(TokenService::verify($payload . 'X.' . $sig), 'payload manipulado');
        $this->assertNull(TokenService::verify($payload . '.' . $sig . 'X'), 'firma manipulada');
        $this->assertNull(TokenService::verify('basura'), 'sin punto');
        $this->assertNull(TokenService::verify(''), 'vacío');
        $this->assertNotNull(TokenService::verify($tok), 'el válido sí');
    }

    public function test_subir_token_version_revoca_los_tokens_anteriores(): void
    {
        $u = $this->usuario();
        $tok = TokenService::make($u);
        $this->assertNotNull(TokenService::verify($tok));

        // Revocar (lo que hace cambiar la contraseña): sube la versión.
        $u->token_version = 2;
        $u->save();

        $this->assertNull(TokenService::verify($tok), 'el token viejo ya no vale');
        $this->assertNotNull(TokenService::verify(TokenService::make($u->fresh())), 'uno nuevo sí');
    }

    public function test_token_de_usuario_borrado_no_vale(): void
    {
        $u = $this->usuario();
        $tok = TokenService::make($u);
        $u->delete();
        $this->assertNull(TokenService::verify($tok));
    }

    public function test_cambiar_la_contrasena_revoca_la_sesion_anterior(): void
    {
        $u = $this->usuario('jefe@x.com');
        $tok = TokenService::make($u);

        // Cambiar la contraseña por el endpoint real → debe invalidar el token con el que se llamó.
        $this->withHeader('X-App-Token', $tok)
            ->postJson('/api/auth.php?action=change', ['current' => 'vieja1234', 'new_password' => 'nueva12345'])
            ->assertOk();

        $this->assertNull(TokenService::verify($tok), 'el token con el que se cambió ya no vale');
    }
}
