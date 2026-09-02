<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WhatsAppNumber;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * WhatsAppNumber pasó de $guarded=[] a $fillable explícito. Como el guardado usa
 * mass-assignment (create()/update()), este test fija que TODAS las columnas de
 * negocio siguen haciendo round-trip: si a $fillable le faltara una, se caería en
 * silencio y el assert de ese campo fallaría.
 */
class WhatsAppNumberFillableTest extends TestCase
{
    use RefreshDatabase;

    private function superadminToken(): string
    {
        Role::findOrCreate('superadmin', 'web');
        $u = User::create(['name' => 'Jefe', 'email' => 'j@x.com', 'password' => bcrypt('x')]);
        $u->assignRole('superadmin');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        return TokenService::make($u);
    }

    public function test_todas_las_columnas_hacen_round_trip_en_create_y_update(): void
    {
        $token = $this->superadminToken();

        // CREATE: todas las columnas de negocio a la vez.
        $this->withHeader('X-App-Token', $token)
            ->postJson('/api/whatsapp_numbers.php?action=save', [
                'label'           => 'Campañas',
                'phone_number_id' => 'PNID123',
                'funcion'         => 'campanas',
                'entorno'         => 'real',
                'waba_id'         => 'WABA1',
                'app_id'          => 'APP1',
                'display_number'  => '+34123',
                'token'           => 'secreto',
                'app_secret'      => 'firma',
                'active'          => true,
            ])->assertOk()->assertJson(['ok' => true]);

        $row = WhatsAppNumber::first();
        $this->assertNotNull($row);
        $this->assertSame('Campañas', $row->label);
        $this->assertSame('PNID123', $row->phone_number_id);
        $this->assertSame('campanas', $row->funcion);
        $this->assertSame('real', $row->entorno);
        $this->assertSame('WABA1', $row->waba_id);
        $this->assertSame('APP1', $row->app_id);
        $this->assertSame('+34123', $row->display_number);
        $this->assertSame('secreto', $row->token);
        $this->assertSame('firma', $row->app_secret);
        $this->assertTrue((bool) $row->active);

        // UPDATE: mismo id, cambia un campo → actualiza, no duplica.
        $this->withHeader('X-App-Token', $token)
            ->postJson('/api/whatsapp_numbers.php?action=save', [
                'id'              => $row->id,
                'label'           => 'Campañas 2',
                'phone_number_id' => 'PNID123',
                'funcion'         => 'campanas',
                'entorno'         => 'prueba',
            ])->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(1, WhatsAppNumber::count());
        $row->refresh();
        $this->assertSame('Campañas 2', $row->label);
        $this->assertSame('prueba', $row->entorno);
    }
}
