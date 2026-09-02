<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El candado de ticket caduca solo a los N minutos. El LATIDO (action=heartbeat) lo
 * renueva mientras el agente sigue en el ticket, para que no lo pierda a mitad de una
 * respuesta larga. Y si lo tiene OTRO, el latido lo dice (para bloquear el editor).
 */
class TicketLockHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(string $email): User
    {
        Role::findOrCreate('superadmin', 'web');
        $u = User::create(['name' => ucfirst(explode('@', $email)[0]), 'email' => $email, 'password' => bcrypt('x')]);
        $u->assignRole('superadmin');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        return $u;
    }

    private function ticket(): int
    {
        $cid = DB::table('contacts')->insertGetId(['name' => 'C', 'email' => 'c@x.com']);
        return DB::table('tickets')->insertGetId([
            'code' => 'TK-' . uniqid(), 'subject' => 's', 'status' => 'abierto', 'priority' => 'media',
            'channel' => 'email', 'contact_id' => $cid, 'opened_at' => now(), 'last_message_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_el_latido_renueva_el_candado_del_que_ya_lo_tiene(): void
    {
        $a = $this->superadmin('a@x.com');
        $id = $this->ticket();

        // A toma el candado.
        $this->withHeader('X-App-Token', TokenService::make($a))
            ->postJson('/api/tickets.php?action=heartbeat', ['id' => $id])
            ->assertOk()->assertJson(['ok' => true, 'lock' => ['mine' => true]]);

        // Se envejece la marca a 110 s (aún vigente con TTL de 2 min).
        DB::table('tickets')->where('id', $id)->update(['locked_at' => now()->subSeconds(110)]);
        $viejo = DB::table('tickets')->where('id', $id)->value('locked_at');

        // Otro latido de A: debe REFRESCAR locked_at (si no, caducaría en 10 s).
        $this->withHeader('X-App-Token', TokenService::make($a))
            ->postJson('/api/tickets.php?action=heartbeat', ['id' => $id])
            ->assertOk()->assertJson(['ok' => true, 'lock' => ['mine' => true]]);

        $nuevo = DB::table('tickets')->where('id', $id)->value('locked_at');
        $this->assertTrue(strtotime($nuevo) > strtotime($viejo), 'el latido no renovó locked_at');
        $this->assertSame((int) $a->id, (int) DB::table('tickets')->where('id', $id)->value('locked_by'));
    }

    public function test_el_latido_avisa_si_el_candado_lo_tiene_otro(): void
    {
        $a = $this->superadmin('a@x.com');
        $b = $this->superadmin('b@x.com');
        $id = $this->ticket();

        // B lo tiene tomado y vigente.
        DB::table('tickets')->where('id', $id)->update(['locked_by' => $b->id, 'locked_at' => now()]);

        // El latido de A no se lo quita: le dice que lo tiene B.
        $this->withHeader('X-App-Token', TokenService::make($a))
            ->postJson('/api/tickets.php?action=heartbeat', ['id' => $id])
            ->assertOk()
            ->assertJson(['ok' => true, 'lock' => ['mine' => false, 'user_id' => $b->id, 'user_name' => $b->name]]);

        // Y el candado sigue siendo de B (no se pisó).
        $this->assertSame((int) $b->id, (int) DB::table('tickets')->where('id', $id)->value('locked_by'));
    }
}
