<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Models\Warranty;
use App\Services\TokenService;
use App\Services\WarrantyAlertNotices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Avisos de alertas: campana al pasar a crítico (una vez), sin responsable, y el plan del correo. */
class WarrantyAlertNoticesTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;
    private User $jefe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
        $this->ana = User::create(['name' => 'Ana', 'email' => 'ana@x.com', 'password' => bcrypt('x'), 'active' => 1]);
        $this->ana->givePermissionTo('warranties.access');
        $this->jefe = User::create(['name' => 'Jefa', 'email' => 'jefa@x.com', 'password' => bcrypt('x'), 'active' => 1]);
        $this->jefe->givePermissionTo(['warranties.access', 'warranties.config']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        // Solo dos reglas: muy urgente (al momento) e inactividad (aviso a 3 días, crítico a 7).
        DB::table('warranty_alert_rules')->update(['active' => false]);
        DB::table('warranty_alert_rules')->where('key', 'muy_urgente')->update(['active' => true]);
        DB::table('warranty_alert_rules')->where('key', 'inactividad')->update(['active' => true, 'warn_days' => 3, 'crit_days' => 7]);
    }

    private function garantia(array $d): Warranty
    {
        return Warranty::create($d + ['code' => 'G-' . uniqid(), 'status' => 'en_curso', 'received_at' => '2026-10-01', 'urgency' => 'media',
            'responsible_id' => $this->ana->id, 'last_activity_at' => now()]);
    }

    private function campana(User $u): array
    {
        return DB::table('notifications')->where('user_id', $u->id)->where('type', 'warranty_alert')->pluck('warranty_id')->all();
    }

    public function test_campana_una_vez_y_sin_responsable(): void
    {
        $vieja = $this->garantia(['urgency' => 'muy_urgente']);
        // Primera pasada: solo anota lo que ya estaba en crítico.
        WarrantyAlertNotices::check();
        $this->assertSame([], $this->campana($this->ana));

        $w = $this->garantia(['last_activity_at' => now()->subDays(8)]);
        $sin = $this->garantia(['responsible_id' => null, 'urgency' => 'muy_urgente']);
        $aviso = $this->garantia(['last_activity_at' => now()->subDays(4)]);   // solo aviso: no va a la campana
        [$n] = WarrantyAlertNotices::check();
        $this->assertSame(2, $n);
        $this->assertSame([$w->id], $this->campana($this->ana));
        $this->assertSame([$sin->id], $this->campana($this->jefe));

        // Mientras siga crítica no se repite; si deja de serlo y vuelve, sí.
        WarrantyAlertNotices::check();
        $this->assertCount(1, $this->campana($this->ana));
        $w->update(['last_activity_at' => now()]);
        WarrantyAlertNotices::check();
        $this->assertFalse(DB::table('warranty_alert_notices')->where('warranty_id', $w->id)->exists());
        $w->update(['last_activity_at' => now()->subDays(9)]);
        WarrantyAlertNotices::check();
        $this->assertSame([$w->id, $w->id], $this->campana($this->ana));

        // Apagada la campana, se anota sin avisar.
        DB::table('warranty_settings')->updateOrInsert(['key' => 'notices'], ['value' => json_encode(['bell' => false])]);
        $this->garantia(['urgency' => 'muy_urgente']);
        WarrantyAlertNotices::check();
        $this->assertCount(2, $this->campana($this->ana));

        // En la campana: lleva a la garantía, con su código.
        $r = $this->getJson('/api/notifications.php', ['X-App-Token' => TokenService::make($this->ana)])->assertOk();
        $this->assertSame([$w->id, $w->code], [$r->json('notifications.0.warranty_id'), $r->json('notifications.0.warranty_code')]);
        $this->assertNotNull($vieja);
        $this->assertNotNull($aviso);
    }

    public function test_correo_diario(): void
    {
        $crit = $this->garantia(['urgency' => 'muy_urgente']);
        $aviso = $this->garantia(['last_activity_at' => now()->subDays(4)]);
        $sin = $this->garantia(['responsible_id' => null, 'last_activity_at' => now()->subDays(10)]);
        $this->assertFalse(WarrantyAlertNotices::digestDue(true));   // apagado de serie

        $plan = WarrantyAlertNotices::digestPlan();
        $this->assertSame([$crit->id], array_column($plan[$this->ana->id], 'id'));
        $this->assertSame([$sin->id], array_column($plan[$this->jefe->id], 'id'));

        $h = ['X-App-Token' => TokenService::make($this->jefe)];
        $this->postJson('/api/warranty_settings.php?action=notices_save', ['bell' => true, 'email' => true, 'hour' => '25:00'], $h)->assertStatus(400);
        $this->postJson('/api/warranty_settings.php?action=notices_save', ['bell' => true, 'email' => true, 'hour' => '07:00', 'include_warn' => true, 'unassigned_to' => [$this->ana->id, 9999]], $h)->assertOk();
        $this->assertSame([$this->ana->id], WarrantyAlertNotices::settings()['unassigned_to']);
        $plan = WarrantyAlertNotices::digestPlan();
        // Con las de aviso, y las sin responsable a quien se ha elegido; las críticas primero.
        $this->assertSame([$crit->id, $sin->id, $aviso->id], array_column($plan[$this->ana->id], 'id'));
        $this->assertArrayNotHasKey($this->jefe->id, $plan);

        $laborable = now()->isWeekend() ? false : null;
        if ($laborable === null && ! isset(app(\App\Services\BusinessHoursService::class)->festivos()[now()->format('Y-m-d')])) {
            Setting::put('warranty_alert_digest_last', now()->format('Y-m-d'));
            $this->assertTrue(WarrantyAlertNotices::digestDue(true));
        }
    }
}
