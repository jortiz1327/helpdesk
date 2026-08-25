<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Bloque 2 de la auditoría (fugas de alcance): un AGENTE (limitado a sus categorías) no
 * debe ver ni tocar tickets de OTRO departamento.
 *  - contact_open (aviso de duplicado) respeta scope().
 *  - ticket_fields save_values respeta scope() (no IDOR de escritura).
 */
class HelpdeskScopeFixesTest extends TestCase
{
    use RefreshDatabase;

    private int $catMia;
    private int $catAjena;
    private int $cid;

    /** Un agente con una sola categoría asignada (la «mía»). Devuelve [User, token]. */
    private function agente(): array
    {
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
        $u = User::create(['name' => 'Ana', 'email' => 'ana@x.com', 'password' => bcrypt('x')]);
        $u->assignRole('agente');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        DB::table('user_ticket_categories')->insert(['user_id' => $u->id, 'category_id' => $this->catMia]);
        return [$u, TokenService::make($u)];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->catMia   = DB::table('ticket_categories')->insertGetId(['key' => 'mia', 'name' => 'Mi área', 'color' => '#888', 'position' => 1, 'active' => 1]);
        $this->catAjena = DB::table('ticket_categories')->insertGetId(['key' => 'ajena', 'name' => 'Otra área', 'color' => '#888', 'position' => 2, 'active' => 1]);
        $this->cid      = DB::table('contacts')->insertGetId(['name' => 'Cliente', 'email' => 'cli@x.com', 'wa_id' => '34600']);
    }

    private function ticket(int $categoryId, string $code): int
    {
        return DB::table('tickets')->insertGetId([
            'code' => $code, 'subject' => "Asunto $code", 'status' => 'abierto', 'priority' => 'media',
            'channel' => 'email', 'contact_id' => $this->cid, 'category_id' => $categoryId,
            'opened_at' => now(), 'last_message_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_contact_open_no_filtra_tickets_de_otra_area(): void
    {
        [, $token] = $this->agente();
        $this->ticket($this->catAjena, 'TK-AJENA');   // abierto, de otro departamento
        $mio = $this->ticket($this->catMia, 'TK-MIA'); // abierto, de mi área

        $r = $this->withHeader('X-App-Token', $token)->getJson('/api/tickets.php?action=contact_open&email=cli@x.com');
        $r->assertOk();
        $codes = collect($r->json('tickets'))->pluck('code')->all();

        $this->assertContains('TK-MIA', $codes);          // el mío sí
        $this->assertNotContains('TK-AJENA', $codes);     // el de otra área NO (fuga cerrada)
        $this->assertSame([$mio], collect($r->json('tickets'))->pluck('id')->all());
    }

    public function test_save_values_no_deja_escribir_en_ticket_ajeno(): void
    {
        [, $token] = $this->agente();
        $field = DB::table('ticket_custom_fields')->insertGetId(['key' => 'f', 'label' => 'F', 'type' => 'text', 'required' => 0, 'position' => 1, 'active' => 1, 'created_at' => now()]);
        $ajeno = $this->ticket($this->catAjena, 'TK-AJENA');
        $mio   = $this->ticket($this->catMia, 'TK-MIA');

        // Ticket de otra área → 403 y NADA escrito.
        $this->withHeader('X-App-Token', $token)
            ->postJson('/api/ticket_fields.php?action=save_values', ['ticket_id' => $ajeno, 'values' => [$field => 'HACK']])
            ->assertStatus(403);
        $this->assertSame(0, DB::table('ticket_field_values')->where('ticket_id', $ajeno)->count());

        // Ticket de mi área → OK.
        $this->withHeader('X-App-Token', $token)
            ->postJson('/api/ticket_fields.php?action=save_values', ['ticket_id' => $mio, 'values' => [$field => 'OK']])
            ->assertOk();
        $this->assertSame('OK', DB::table('ticket_field_values')->where('ticket_id', $mio)->value('value'));
    }
}
