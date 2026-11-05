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

        // Se envejece la marca a 60 s (aún vigente: el candado vive 90 s sin latido).
        DB::table('tickets')->where('id', $id)->update(['locked_at' => now()->subSeconds(60)]);
        $viejo = DB::table('tickets')->where('id', $id)->value('locked_at');

        // Otro latido de A: debe REFRESCAR locked_at (si no, caducaría en 30 s).
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

    /**
     * TK-2611-0037: un agente lo abrió, lo dejó abierto en su PC (o cerró el navegador) y el
     * resto lo vio «tomado» toda la tarde. Sin latido, el candado cae en 90 s aunque los
     * minutos de inactividad sean 10, y el sondeo de fondo de su ventana no lo resucita.
     */
    public function test_sin_latido_el_candado_cae_en_minuto_y_medio(): void
    {
        \App\Models\Setting::put('ticket_lock_minutes', '10');
        $a = $this->superadmin('a@x.com');
        $b = $this->superadmin('b@x.com');
        $id = $this->ticket();
        DB::table('tickets')->where('id', $id)->update(['locked_by' => $a->id, 'locked_at' => now()->subSeconds(100)]);

        // La ventana de A sigue sondeando de fondo: informa, pero no renueva.
        $this->withHeader('X-App-Token', TokenService::make($a))
            ->getJson("/api/tickets.php?action=detail&id={$id}&bg=1")->assertOk();
        $this->assertTrue(strtotime(DB::table('tickets')->where('id', $id)->value('locked_at')) < time() - 90);

        // B lo abre y lo toma: ya no lo ve ocupado.
        $this->withHeader('X-App-Token', TokenService::make($b))
            ->getJson("/api/tickets.php?action=detail&id={$id}")
            ->assertOk()->assertJson(['lock' => ['mine' => true, 'lease' => 90, 'minutes' => 10]]);
        $this->assertSame((int) $b->id, (int) DB::table('tickets')->where('id', $id)->value('locked_by'));
    }

    /** Los demás ven «se libera en m:ss»: los minutos sin actividad menos lo que lleva quieto el dueño. */
    public function test_los_demas_ven_cuanto_falta_para_que_se_libere(): void
    {
        \App\Models\Setting::put('ticket_lock_minutes', '5');
        $a = $this->superadmin('a@x.com');
        $b = $this->superadmin('b@x.com');
        $id = $this->ticket();

        // A late diciendo que lleva 60 s sin tocar nada.
        $this->withHeader('X-App-Token', TokenService::make($a))
            ->postJson('/api/tickets.php?action=heartbeat', ['id' => $id, 'idle' => 60])
            ->assertOk()->assertJson(['lock' => ['mine' => true]]);

        $r = $this->withHeader('X-App-Token', TokenService::make($b))
            ->getJson("/api/tickets.php?action=detail&id={$id}&bg=1")->assertOk()
            ->assertJson(['lock' => ['mine' => false, 'user_id' => $a->id]]);
        $quedan = $r->json('lock.remaining');
        $this->assertTrue($quedan >= 238 && $quedan <= 240, "quedan {$quedan} s, se esperaban ~240");

        // Un idle disparatado no deja la cuenta en negativo ni por encima de los minutos.
        $this->withHeader('X-App-Token', TokenService::make($a))
            ->postJson('/api/tickets.php?action=heartbeat', ['id' => $id, 'idle' => 99999])->assertOk();
        $this->withHeader('X-App-Token', TokenService::make($b))
            ->getJson("/api/tickets.php?action=detail&id={$id}&bg=1")
            ->assertJson(['lock' => ['mine' => false, 'remaining' => 0]]);
    }

    public function test_el_latido_no_toma_un_ticket_cerrado(): void
    {
        $a = $this->superadmin('a@x.com');
        $id = $this->ticket();
        DB::table('tickets')->where('id', $id)->update(['status' => 'cerrado']);

        $this->withHeader('X-App-Token', TokenService::make($a))
            ->postJson('/api/tickets.php?action=heartbeat', ['id' => $id])
            ->assertOk()->assertJson(['ok' => true, 'lock' => ['mine' => true]]);
        $this->assertNull(DB::table('tickets')->where('id', $id)->value('locked_by'));
    }
}
