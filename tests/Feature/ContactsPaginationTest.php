<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * La lista de contactos antes traía hasta 3.000 filas de golpe. Ahora pagina OPT-IN:
 * con `page` sirve una página de 50 (+ flag `more`); sin `page` sigue devolviendo todo
 * (para el tablero Kanban, que necesita todas las tarjetas). Este test fija ambos modos.
 */
class ContactsPaginationTest extends TestCase
{
    use RefreshDatabase;

    private function token(): string
    {
        Role::findOrCreate('superadmin', 'web');
        $u = User::create(['name' => 'Jefe', 'email' => 'j@x.com', 'password' => bcrypt('x')]);
        $u->assignRole('superadmin');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        return TokenService::make($u);
    }

    public function test_pagina_de_50_con_flag_more_y_sin_page_devuelve_todo(): void
    {
        $token = $this->token();

        // 60 contactos con last_time escalonado para un orden estable.
        for ($i = 0; $i < 60; $i++) {
            DB::table('contacts')->insert([
                'name' => 'C' . $i, 'email' => "c$i@x.com",
                'last_time' => now()->subMinutes($i),
            ]);
        }

        // Página 0: 50 + hay más.
        $p0 = $this->withHeader('X-App-Token', $token)->getJson('/api/contacts.php?page=0')->assertOk();
        $this->assertCount(50, $p0->json('contacts'));
        $this->assertTrue($p0->json('more'));

        // Página 1: los 10 restantes + no hay más.
        $p1 = $this->withHeader('X-App-Token', $token)->getJson('/api/contacts.php?page=1')->assertOk();
        $this->assertCount(10, $p1->json('contacts'));
        $this->assertFalse($p1->json('more'));

        // Sin page: todo (comportamiento de siempre, para el Kanban).
        $todo = $this->withHeader('X-App-Token', $token)->getJson('/api/contacts.php')->assertOk();
        $this->assertCount(60, $todo->json('contacts'));
    }
}
