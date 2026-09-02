<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Los contadores de la bandeja se cachean 15 s por agente. Tras una acción que muta
 * tickets se sube la versión global (TicketCounters::bump), de modo que el reload
 * inmediato ve el número FRESCO y no el cacheado de hasta 15 s antes. Sin la
 * invalidación, la segunda lectura seguiría dando el valor viejo dentro de esa ventana.
 */
class TicketCountersInvalidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_una_accion_refresca_los_contadores_al_instante(): void
    {
        Cache::flush();   // evita arrastrar versiones/cachés de otros tests

        Role::findOrCreate('superadmin', 'web');
        $u = User::create(['name' => 'Jefe', 'email' => 'j@x.com', 'password' => bcrypt('x')]);
        $u->assignRole('superadmin');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $token = TokenService::make($u);

        $cid = DB::table('contacts')->insertGetId(['name' => 'C', 'email' => 'c@x.com']);
        $id = DB::table('tickets')->insertGetId([
            'code' => 'TK-1', 'subject' => 's', 'status' => 'abierto', 'priority' => 'media',
            'channel' => 'email', 'contact_id' => $cid, 'opened_at' => now(), 'last_message_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // 1ª lectura: 1 activo (queda cacheado bajo la versión actual).
        $this->withHeader('X-App-Token', $token)->getJson('/api/tickets.php?action=list')
            ->assertOk()->assertJsonPath('counts.active', 1);

        // Acción que muta: cerrar el ticket → sube la versión de contadores.
        $this->withHeader('X-App-Token', $token)
            ->postJson('/api/tickets.php?action=status', ['id' => $id, 'status' => 'cerrado'])
            ->assertOk();

        // 2ª lectura INMEDIATA: debe reflejar 0 activos. Sin invalidación seguiría
        // devolviendo el 1 cacheado (dentro de los 15 s).
        $this->withHeader('X-App-Token', $token)->getJson('/api/tickets.php?action=list')
            ->assertOk()->assertJsonPath('counts.active', 0);
    }
}
