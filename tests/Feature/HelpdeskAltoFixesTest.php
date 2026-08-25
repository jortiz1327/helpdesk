<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Bloque 1 de la auditoría (los ALTO), vía la API real con token:
 *  - un ticket con el SLA EN PAUSA no se cuenta ni se filtra como «vencido»;
 *  - el hilo de mensajes se pagina (últimos N + «anteriores» bajo demanda).
 */
class HelpdeskAltoFixesTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): array
    {
        Role::findOrCreate('superadmin', 'web');   // el rol con bypass a todos los permisos
        $u = User::create(['name' => 'Jefe', 'email' => 'jefe@x.com', 'password' => bcrypt('x')]);
        $u->assignRole('superadmin');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        return [$u, TokenService::make($u)];
    }

    private function ticket(array $extra = []): int
    {
        $cid = DB::table('contacts')->insertGetId(['name' => 'C', 'email' => 'c' . uniqid() . '@x.com']);
        return DB::table('tickets')->insertGetId(array_merge([
            'code' => 'TK-' . uniqid(), 'subject' => 's', 'status' => 'abierto', 'priority' => 'media',
            'channel' => 'email', 'contact_id' => $cid, 'opened_at' => now(),
            'last_message_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }

    public function test_un_ticket_con_sla_en_pausa_no_cuenta_como_vencido(): void
    {
        [, $token] = $this->superadmin();
        DB::table('settings')->updateOrInsert(['key' => 'sla_active'], ['value' => '1']);

        // Corriendo y pasado de plazo → SÍ vencido.
        $corriendo = $this->ticket([
            'status' => 'en_progreso', 'sla_resolve_due_at' => now()->subHour(), 'sla_paused_since' => null,
        ]);
        // En pausa (esperando cliente) con el due_at congelado en el pasado → NO vencido.
        $pausado = $this->ticket([
            'status' => 'esperando_respuesta', 'sla_resolve_due_at' => now()->subDay(), 'sla_paused_since' => now()->subHour(),
        ]);

        $r = $this->withHeader('X-App-Token', $token)->getJson('/api/tickets.php?action=list');
        $r->assertOk();
        $this->assertSame(1, $r->json('counts.sla_late'), 'solo el que corre está vencido');

        // El filtro «SLA vencido» tampoco lo trae.
        $r2 = $this->withHeader('X-App-Token', $token)->getJson('/api/tickets.php?action=list&sla=late');
        $ids = collect($r2->json('tickets'))->pluck('id')->all();
        $this->assertContains($corriendo, $ids);
        $this->assertNotContains($pausado, $ids);
    }

    public function test_el_hilo_de_mensajes_se_pagina(): void
    {
        [, $token] = $this->superadmin();
        $id = $this->ticket();

        // 70 mensajes: más de una página (60).
        for ($i = 1; $i <= 70; $i++) {
            DB::table('messages')->insert([
                'contact_id' => DB::table('tickets')->where('id', $id)->value('contact_id'),
                'ticket_id' => $id, 'direction' => 'in', 'channel' => 'email', 'type' => 'text',
                'body' => "msg $i", 'status' => 'received', 'created_at' => now(),
            ]);
        }

        $r = $this->withHeader('X-App-Token', $token)->getJson("/api/tickets.php?action=detail&id=$id");
        $r->assertOk();
        $this->assertCount(60, $r->json('messages'), 'solo la última página');
        $this->assertTrue($r->json('messages_more'), 'hay anteriores');
        // La página trae los MÁS RECIENTES (asc): el último es el 70.
        $this->assertSame('msg 70', collect($r->json('messages'))->last()['body']);

        // Cargar los anteriores al primero mostrado.
        $primero = collect($r->json('messages'))->first()['id'];
        $r2 = $this->withHeader('X-App-Token', $token)->getJson("/api/tickets.php?action=messages&id=$id&before=$primero");
        $r2->assertOk();
        $this->assertCount(10, $r2->json('messages'), 'los 10 restantes');
        $this->assertFalse($r2->json('more'), 'ya no hay más atrás');
    }
}
