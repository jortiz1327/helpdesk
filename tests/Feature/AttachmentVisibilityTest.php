<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AttachmentService;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * El acceso a un adjunto usa el MISMO criterio de visibilidad que la bandeja
 * (TicketVisibility, una sola fuente). Un agente no puede bajarse adjuntos de un
 * ticket de OTRA área probando ids, pero sí los de la suya.
 */
class AttachmentVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private int $catMia;
    private int $catAjena;

    protected function setUp(): void
    {
        parent::setUp();
        $this->catMia   = DB::table('ticket_categories')->insertGetId(['key' => 'mia', 'name' => 'Mi área', 'color' => '#888', 'position' => 1, 'active' => 1]);
        $this->catAjena = DB::table('ticket_categories')->insertGetId(['key' => 'ajena', 'name' => 'Otra área', 'color' => '#888', 'position' => 2, 'active' => 1]);
    }

    private function ticket(int $catId): int
    {
        $cid = DB::table('contacts')->insertGetId(['name' => 'C', 'email' => 'c' . uniqid() . '@x.com']);
        return DB::table('tickets')->insertGetId([
            'code' => 'TK-' . uniqid(), 'subject' => 's', 'status' => 'abierto', 'priority' => 'media',
            'channel' => 'email', 'contact_id' => $cid, 'category_id' => $catId,
            'opened_at' => now(), 'last_message_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_un_agente_no_baja_adjuntos_de_otra_area_pero_si_de_la_suya(): void
    {
        Storage::fake('local');
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
        $u = User::create(['name' => 'Ana', 'email' => 'ana@x.com', 'password' => bcrypt('x')]);
        $u->assignRole('agente');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        DB::table('user_ticket_categories')->insert(['user_id' => $u->id, 'category_id' => $this->catMia]);
        $token = TokenService::make($u);

        $att = app(AttachmentService::class);
        $idMia   = $att->storeRaw('foto.png', 'PNGDATA', 'image/png', $this->ticket($this->catMia), null);
        $idAjena = $att->storeRaw('foto.png', 'PNGDATA', 'image/png', $this->ticket($this->catAjena), null);

        // De OTRA área → 403 (no diverge del scope de la bandeja).
        $this->withHeader('X-App-Token', $token)->get("/api/attachment.php?id=$idAjena")->assertStatus(403);
        // De SU área → sí puede.
        $this->withHeader('X-App-Token', $token)->get("/api/attachment.php?id=$idMia")->assertOk();
    }
}
