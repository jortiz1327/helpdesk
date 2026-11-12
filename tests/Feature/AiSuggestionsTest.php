<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiException;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Unit\Code128ReaderTest;

/**
 * Sugerencias de la IA: se genera con las FAQs y las correcciones anteriores, se valora, y
 * un agente no ve lo de otra área. El proveedor es de pega: no sale ninguna llamada real.
 */
class AiSuggestionsTest extends TestCase
{
    use RefreshDatabase;

    private FakeAi $fake;
    private int $cat;
    private int $cid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = new FakeAi();
        $this->app->instance(AiProvider::class, $this->fake);
        $this->cat = DB::table('ticket_categories')->insertGetId(['key' => 'a', 'name' => 'Etiquetas', 'color' => '#888', 'position' => 1, 'active' => 1]);
        $this->cid = DB::table('contacts')->insertGetId(['name' => 'Marta', 'email' => 'm@x.com']);
    }

    private function agente(): string
    {
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
        $u = User::create(['name' => 'Ana', 'email' => 'ana@x.com', 'password' => bcrypt('x')]);
        $u->assignRole('agente');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        DB::table('user_ticket_categories')->insert(['user_id' => $u->id, 'category_id' => $this->cat]);
        return TokenService::make($u);
    }

    private function ticket(string $texto, ?int $cat = null): int
    {
        $id = DB::table('tickets')->insertGetId([
            'code' => 'TK-' . uniqid(), 'subject' => 'Etiqueta sin conexión', 'status' => 'abierto', 'priority' => 'media',
            'channel' => 'email', 'contact_id' => $this->cid, 'category_id' => $cat ?? $this->cat,
            'opened_at' => now(), 'last_message_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('messages')->insert(['contact_id' => $this->cid, 'wa_id' => '', 'ticket_id' => $id, 'direction' => 'in', 'body' => $texto, 'created_at' => now()]);
        return $id;
    }

    private function activar(): void
    {
        Setting::put('ai_suggestions_enabled', '1');
    }

    public function test_lee_el_codigo_de_barras_de_la_foto_y_se_lo_da_a_la_ia(): void
    {
        $this->activar();
        Storage::fake('local');
        $tk = $this->ticket('Mi etiqueta no cambia, os mando foto');
        $m = DB::table('messages')->where('ticket_id', $tk)->value('id');
        $png = Code128ReaderTest::foto('AB12CD345');
        Storage::disk('local')->put('attachments/t/foto.png', file_get_contents($png));
        @unlink($png);
        DB::table('attachments')->insert(['ticket_id' => $tk, 'message_id' => $m, 'name' => 'foto.png', 'path' => 'attachments/t/foto.png', 'mime' => 'image/png', 'size' => 1000]);

        $this->withHeader('X-App-Token', $this->agente())->getJson("/api/ai_suggestions.php?action=suggest&ticket=$tk")->assertOk();
        $this->assertStringContainsString('AB12CD345', $this->fake->ultimoSistema);
    }

    public function test_apagada_no_llama_a_la_ia(): void
    {
        $tk = $this->ticket('Mi etiqueta no conecta');
        $r = $this->withHeader('X-App-Token', $this->agente())->getJson("/api/ai_suggestions.php?action=suggest&ticket=$tk");
        $r->assertOk()->assertJsonPath('enabled', false)->assertJsonPath('suggestion', null);
        $this->assertSame(0, $this->fake->llamadas);
    }

    public function test_genera_con_las_faqs_y_se_reutiliza(): void
    {
        $this->activar();
        DB::table('faqs')->insert(['question' => 'Mi etiqueta no conecta con la antena', 'answer' => 'Acerca la etiqueta y pulsa reiniciar.', 'keywords' => 'etiqueta conexion antena', 'active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('faqs')->insert(['question' => 'Facturas de abril', 'answer' => 'Descárgalas en el portal.', 'active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $tk = $this->ticket('Mi etiqueta no conecta con la antena');
        $token = $this->agente();

        $r = $this->withHeader('X-App-Token', $token)->getJson("/api/ai_suggestions.php?action=suggest&ticket=$tk");
        $r->assertOk()->assertJsonPath('suggestion.status', 'ready')->assertJsonPath('suggestion.body', 'Hola Marta, prueba a reiniciarla.');
        $this->assertStringContainsString('Acerca la etiqueta', $this->fake->ultimoSistema);      // la FAQ que encaja va en el prompt
        $this->assertStringNotContainsString('Facturas de abril', $this->fake->ultimoSistema);    // la que no, no

        $this->withHeader('X-App-Token', $token)->getJson("/api/ai_suggestions.php?action=suggest&ticket=$tk")->assertOk();
        $this->assertSame(1, $this->fake->llamadas);                                              // la segunda vez sale de la BD
    }

    public function test_no_sabe_se_guarda_sin_texto(): void
    {
        $this->activar();
        $this->fake->respuesta = '[[NO_SABE]]';
        $tk = $this->ticket('Quiero un descuento enorme');
        $r = $this->withHeader('X-App-Token', $this->agente())->getJson("/api/ai_suggestions.php?action=suggest&ticket=$tk");
        $r->assertOk()->assertJsonPath('suggestion.status', 'abstained')->assertJsonPath('suggestion.body', null);
    }

    public function test_fallo_del_proveedor_no_rompe_la_ficha(): void
    {
        $this->activar();
        $this->fake->falla = true;
        $tk = $this->ticket('Hola');
        $r = $this->withHeader('X-App-Token', $this->agente())->getJson("/api/ai_suggestions.php?action=suggest&ticket=$tk");
        $r->assertOk()->assertJsonPath('suggestion.status', 'failed');
    }

    public function test_valorar_en_negativo_exige_correccion_y_alimenta_la_siguiente(): void
    {
        $this->activar();
        $token = $this->agente();
        $tk = $this->ticket('Mi etiqueta no conecta');
        $id = $this->withHeader('X-App-Token', $token)->getJson("/api/ai_suggestions.php?action=suggest&ticket=$tk")->json('suggestion.id');

        $this->withHeader('X-App-Token', $token)->postJson('/api/ai_suggestions.php?action=rate', ['id' => $id, 'rating' => 'down', 'correction' => ''])->assertStatus(422);
        $this->assertNull(DB::table('ai_suggestions')->where('id', $id)->value('rating'));

        $this->withHeader('X-App-Token', $token)->postJson('/api/ai_suggestions.php?action=rate', ['id' => $id, 'rating' => 'down', 'correction' => 'Debía pedir el código de la etiqueta primero.'])
            ->assertOk()->assertJsonPath('suggestion.rating', 'down');

        // Otro ticket: la corrección anterior viaja en el prompt como ejemplo.
        $tk2 = $this->ticket('Otra etiqueta que no conecta');
        $this->withHeader('X-App-Token', $token)->getJson("/api/ai_suggestions.php?action=suggest&ticket=$tk2")->assertOk();
        $this->assertStringContainsString('Debía pedir el código de la etiqueta primero.', $this->fake->ultimoSistema);
    }

    public function test_un_agente_no_ve_sugerencias_de_otra_area(): void
    {
        $this->activar();
        $token = $this->agente();
        $otra = DB::table('ticket_categories')->insertGetId(['key' => 'b', 'name' => 'Otra', 'color' => '#888', 'position' => 2, 'active' => 1]);
        $ajeno = $this->ticket('Secreto', $otra);
        $this->withHeader('X-App-Token', $token)->getJson("/api/ai_suggestions.php?action=suggest&ticket=$ajeno")->assertStatus(404);
        $this->assertSame(0, $this->fake->llamadas);
    }

    private function encargado(): string
    {
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
        $u = User::create(['name' => 'Eva', 'email' => 'eva@x.com', 'password' => bcrypt('x')]);
        $u->assignRole('encargado_soporte');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        return TokenService::make($u);
    }

    public function test_el_panel_solo_lo_ve_quien_configura_el_soporte(): void
    {
        $token = $this->agente();
        $this->withHeader('X-App-Token', $token)->getJson('/api/ai_suggestions.php?action=admin')->assertStatus(403);
        $this->withHeader('X-App-Token', $token)->postJson('/api/ai_suggestions.php?action=admin_save', ['enabled' => true])->assertStatus(403);
        $this->assertNull(Setting::get('ai_suggestions_enabled'));
    }

    public function test_el_panel_enciende_apaga_y_cuenta_el_acierto(): void
    {
        $token = $this->encargado();
        $this->withHeader('X-App-Token', $token)->postJson('/api/ai_suggestions.php?action=admin_save', ['enabled' => true])
            ->assertOk()->assertJsonPath('enabled', true)->assertJsonPath('configured', true);

        $tk = $this->ticket('Mi etiqueta no conecta');
        $id = $this->withHeader('X-App-Token', $token)->getJson("/api/ai_suggestions.php?action=suggest&ticket=$tk")->json('suggestion.id');
        $this->withHeader('X-App-Token', $token)->postJson('/api/ai_suggestions.php?action=rate', ['id' => $id, 'rating' => 'down', 'correction' => 'Debía pedir el código primero.']);

        $r = $this->withHeader('X-App-Token', $token)->getJson('/api/ai_suggestions.php?action=admin')->assertOk();
        $r->assertJsonPath('stats.total', 1)->assertJsonPath('stats.down', 1)->assertJsonPath('stats.accuracy', 0);
        $r->assertJsonPath('corrections.0.correction', 'Debía pedir el código primero.');
        $this->assertArrayNotHasKey('api_key', $r->json());

        $this->withHeader('X-App-Token', $token)->postJson('/api/ai_suggestions.php?action=admin_save', ['enabled' => false])->assertOk()->assertJsonPath('enabled', false);
    }

    public function test_laboratorio_contesta_solo_con_lo_previo_y_no_envia_nada(): void
    {
        config(['ai.lab' => true]);
        $token = $this->encargado();
        $this->activar();
        $tk = $this->ticket('Mi etiqueta no cambia de precio desde ayer, que hago?');
        $uid = DB::table('users')->value('id');
        DB::table('messages')->insert(['contact_id' => $this->cid, 'wa_id' => '', 'ticket_id' => $tk, 'direction' => 'out', 'author_user_id' => $uid,
            'body' => 'Hola Marta, la respuesta secreta del equipo es reiniciar la zona con el codigo ZETA-99 desde Posiciones.', 'created_at' => now()]);
        DB::table('tickets')->where('id', $tk)->update(['status' => 'resuelto']);

        $r = $this->withHeader('X-App-Token', $token)->getJson('/api/ai_suggestions.php?action=lab_cases')->assertOk();
        $caso = $r->json('cases.0');
        $this->assertSame($tk, $caso['ticket_id']);
        $this->assertStringContainsString('ZETA-99', $caso['real_reply']);   // se enseña al validador...

        $g = $this->withHeader('X-App-Token', $token)->postJson('/api/ai_suggestions.php?action=lab_generate', ['ticket' => $tk, 'message' => $caso['message_id']])->assertOk();
        $g->assertJsonPath('suggestion.status', 'ready');
        $this->assertStringNotContainsString('ZETA-99', $this->fake->ultimoSistema);   // ...pero la IA no la ve
        $this->assertStringContainsString('le hablo de AEME Group', $this->fake->ultimoSistema);   // y trata el caso como primer contacto
        $this->assertSame('lab', DB::table('ai_suggestions')->value('mode'));
        $this->assertSame(1, DB::table('messages')->where('ticket_id', $tk)->where('direction', 'out')->count());   // nada enviado

        $this->withHeader('X-App-Token', $token)->postJson('/api/ai_suggestions.php?action=rate', ['id' => $g->json('suggestion.id'), 'rating' => 'up'])->assertOk();
        $this->assertSame([], $this->withHeader('X-App-Token', $token)->getJson('/api/ai_suggestions.php?action=lab_cases')->json('cases'));   // ya validado: no vuelve
    }

    public function test_no_se_puede_encender_sin_clave(): void
    {
        $this->fake->configurada = false;
        $this->withHeader('X-App-Token', $this->encargado())->postJson('/api/ai_suggestions.php?action=admin_save', ['enabled' => true])->assertStatus(422);
        $this->assertNotSame('1', Setting::get('ai_suggestions_enabled'));
    }
}

class FakeAi implements AiProvider
{
    public int $llamadas = 0;
    public string $ultimoSistema = '';
    public string $respuesta = 'Hola Marta, prueba a reiniciarla.';
    public bool $falla = false;
    public bool $configurada = true;

    public function configured(): bool
    {
        return $this->configurada;
    }

    public function complete(string $system, array $messages): array
    {
        $this->llamadas++;
        $this->ultimoSistema = $system;
        if ($this->falla) {
            throw new AiException('caído');
        }
        return ['text' => $this->respuesta, 'model' => 'fake', 'input_tokens' => 10, 'output_tokens' => 5];
    }
}
