<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiException;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

    private function flota(string $emailUsuario, bool $appsSinRespuesta = false): void
    {
        config(['ai.support.url' => 'https://panel.example.com', 'ai.support.key' => 'K-secreta']);
        Cache::flush();
        $encontrado = $emailUsuario === 'm@x.com';
        Http::fake([
            'panel.example.com/api/support/v1/users/lookup*' => Http::response(['success' => true, 'data' => $encontrado
                ? ['encontrado' => true, 'coincidencias' => [['app' => 'sol', 'hoteles' => ['Hotel Sol'], 'buffets' => [['id' => 'b3b3b3b3-0000-4000-8000-000000000003', 'nombre' => 'Buffet Principal']]]]]
                : ['encontrado' => false, 'coincidencias' => [], 'apps_sin_respuesta' => $appsSinRespuesta ? [['app' => 'luna', 'motivo' => 'sin_funcion']] : []]]),
            'panel.example.com/api/support/v1/hotels*' => function ($r) {
                $q = mb_strtolower((string) ($r->data()['q'] ?? ''));
                $h = [];
                if (str_contains($q, 'sol')) { $h[] = ['id' => 'h1', 'nombre' => 'Hotel Sol', 'grupo' => 'G', 'app' => 'sol']; }
                if (str_contains($q, 'luna')) { $h[] = ['id' => 'h2', 'nombre' => 'Hotel Luna', 'grupo' => 'G', 'app' => 'luna']; }
                return Http::response(['success' => true, 'data' => ['total' => count($h), 'hoteles' => $h]]);
            },
            'panel.example.com/api/support/v1/buffets/b3b3b3b3-0000-4000-8000-000000000003/antennas*' => Http::response(['success' => true, 'data' => [
                ['nombre' => 'Antena Frios', 'en_linea' => false, 'caida_desde' => '2026-11-10T09:12:00+01:00', 'etiquetas' => 64],
                ['nombre' => 'Antena Sala', 'en_linea' => true, 'caida_desde' => null, 'etiquetas' => 10],
            ]]),
            'panel.example.com/api/support/v1/buffets/b3b3b3b3-0000-4000-8000-000000000003/tags*' => Http::response(['success' => true, 'data' => [
                ['codigo' => 'AB12CD345', 'en_linea' => false, 'bateria' => 8, 'senal' => -81, 'actualizada' => '2026-11-09T18:30:44+01:00', 'antena' => ['nombre' => 'Antena Frios', 'en_linea' => false]],
            ]]),
        ]);
    }

    public function test_verifica_el_correo_en_la_flota_y_ve_las_antenas_sin_filtrar_ip_ni_mac(): void
    {
        $this->activar();
        $this->flota('m@x.com');
        $tk = $this->ticket('Mi etiqueta AB12CD345 no se actualiza');
        $this->withHeader('X-App-Token', $this->agente())->getJson("/api/ai_suggestions.php?action=suggest&ticket=$tk")->assertOk();
        $p = $this->fake->ultimoSistema;
        $this->assertStringContainsString('Etiqueta AB12CD345 (Buffet Principal): SIN CONEXIÓN, batería 8 %, señal -81 dBm', $p);
        $this->assertStringContainsString('la sirve la antena Antena Frios (SIN CONEXIÓN)', $p);
        $this->assertStringContainsString('Hotel Sol / Buffet Principal', $p);
        $this->assertStringContainsString('SIN CONEXIÓN: Antena Frios', $p);
        $this->assertStringNotContainsString('AP0000000001', $p);
        $this->assertStringNotContainsString('10.0.0.20', $p);
        $this->assertStringNotContainsString('K-secreta', $p);
        Http::assertSent(fn ($r) => $r->method() === 'GET' && $r->hasHeader('X-Api-Key', 'K-secreta'));
    }

    /** Forma REAL de la API (comprobada con datos reales): buffets con hotel_id, hoteles aparte y antenas en data.antenas. */
    public function test_con_la_forma_real_enlaza_buffet_y_hotel_y_pregunta_cual_si_el_nombre_es_ambiguo(): void
    {
        $this->activar();
        config(['ai.support.url' => 'https://panel.example.com', 'ai.support.key' => 'K-secreta']);
        Cache::flush();
        $buf = 'b4b4b4b4-0000-4000-8000-000000000004';
        Http::fake([
            'panel.example.com/api/support/v1/users/lookup*' => Http::response(['success' => true, 'data' => ['encontrado' => true, 'desactivados_en_apps' => 0,
                'apps_sin_respuesta' => [['app' => 'otra', 'motivo' => 'sin_funcion']],
                'coincidencias' => [['app' => 'poseidon', 'usuario' => ['nombre' => 'M', 'rol' => 'F&B', 'activo' => true], 'acceso_a_todo_el_grupo' => false,
                    'hoteles' => [['id' => 'h9', 'nombre' => 'Hotel Poseidón Playa']], 'buffets' => [['id' => $buf, 'nombre' => 'Buffet Playa', 'hotel_id' => 'h9']]]]]]),
            'panel.example.com/api/support/v1/hotels*' => Http::response(['success' => true, 'data' => ['total' => 3, 'hoteles' => [
                ['id' => 'h8', 'nombre' => 'Hotel Poseidón La Manga', 'grupo' => 'Hoteles Poseidón'],
                ['id' => 'h9', 'nombre' => 'Hotel Poseidón Playa', 'grupo' => 'Hoteles Poseidón'],
                ['id' => 'h7', 'nombre' => 'Poseidón Resort', 'grupo' => 'Hoteles Poseidón']]]]),
            "panel.example.com/api/support/v1/buffets/$buf/antennas*" => Http::response(['success' => true, 'data' => ['total' => 1, 'en_linea' => 1,
                'antenas' => [['nombre' => 'Playa-1', 'en_linea' => true, 'caida_desde' => null, 'etiquetas' => 25]]]]),
        ]);
        $tk = $this->ticket('Hola, soy del Hotel Poseidón y una etiqueta no se actualiza');
        $this->withHeader('X-App-Token', $this->agente())->getJson("/api/ai_suggestions.php?action=suggest&ticket=$tk")->assertOk();
        $p = $this->fake->ultimoSistema;
        $this->assertStringContainsString('VERIFICADO', $p);                                      // encontrado aunque otra app no respondiera
        $this->assertStringContainsString('Hotel Poseidón Playa / Buffet Playa', $p);              // hotel_id → nombre del hotel
        $this->assertStringContainsString('Antenas de Buffet Playa: 1 en total', $p);              // data.antenas
        $this->assertStringContainsString('corresponde a VARIOS hoteles', $p);                     // ambiguo: hay 3 «Poseidón»
        $this->assertStringContainsString('NO asumas cuál es', $p);
        $this->assertStringNotContainsString('Hotel Poseidón La Manga', $p);                       // no se enumeran los otros
    }

    public function test_el_prompt_limita_a_dos_preguntas_por_mensaje(): void
    {
        $this->activar();
        $tk = $this->ticket('Las etiquetas no se actualizan');
        $this->withHeader('X-App-Token', $this->agente())->getJson("/api/ai_suggestions.php?action=suggest&ticket=$tk")->assertOk();
        $p = $this->fake->ultimoSistema;
        $this->assertStringContainsString('como máximo DOS preguntas por mensaje', $p);   // base del prompt (resources/ai/prompt-soporte.md)
        $this->assertStringContainsString('como máximo DOS por mensaje', $p);              // línea fija del código
        $this->assertStringContainsString('el correo con el que accede', $p);
        $this->assertStringContainsString('NUNCA le digas al cliente que existen varios hoteles', $p);
        $this->assertStringContainsString('NO PREGUNTES LO OBVIO NI LO INVENTADO', $p);   // línea fija
        $this->assertStringContainsString('No preguntes lo obvio ni lo inventado', $p);   // prompt base
        $this->assertStringContainsString('SIEMPRE DERIVA a una persona', $p);             // criterios de la revisión de casos
    }

    public function test_un_correo_que_no_esta_en_la_app_no_recibe_datos_de_hoteles(): void
    {
        $this->activar();
        $this->flota('otro@hotel.com');
        $tk = $this->ticket('Mis etiquetas no se actualizan');
        $this->withHeader('X-App-Token', $this->agente())->getJson("/api/ai_suggestions.php?action=suggest&ticket=$tk")->assertOk();
        $this->assertStringContainsString('NO figura como usuario', $this->fake->ultimoSistema);
        $this->assertStringNotContainsString('Hotel Sol', $this->fake->ultimoSistema);
        $this->assertStringNotContainsString('Antena Frios', $this->fake->ultimoSistema);
    }

    public function test_con_el_hotel_nombrado_y_sin_correo_se_cuentan_las_antenas_pero_nada_mas(): void
    {
        $this->activar();
        config(['ai.support.url' => 'https://panel.example.com', 'ai.support.key' => 'K-secreta']);
        Cache::flush();
        $hid = 'a1a1a1a1-0000-4000-8000-000000000001';
        $buf = 'b5b5b5b5-0000-4000-8000-000000000005';
        Http::fake([
            "panel.example.com/api/support/v1/hotels/$hid/buffets*" => Http::response(['success' => true, 'data' => [
                'hotel' => ['id' => $hid, 'nombre' => 'Hotel Marina Sol'], 'buffets' => [['id' => $buf, 'nombre' => 'Buffet Sol', 'hotel_id' => $hid]]]]),
            'panel.example.com/api/support/v1/hotels*' => Http::response(['success' => true, 'data' => ['total' => 1, 'hoteles' => [['id' => $hid, 'nombre' => 'Hotel Marina Sol', 'grupo' => 'G']]]]),
            "panel.example.com/api/support/v1/buffets/$buf/antennas*" => Http::response(['success' => true, 'data' => ['total' => 1, 'en_linea' => 0,
                'antenas' => [['nombre' => 'Sol-1', 'en_linea' => false, 'caida_desde' => '2026-11-12T08:00:00+01:00', 'etiquetas' => 20]]]]),
            'panel.example.com/api/support/v1/users/lookup*' => Http::response(['success' => true, 'data' => ['encontrado' => false, 'coincidencias' => []]]),
        ]);
        DB::table('contacts')->where('id', $this->cid)->update(['email' => null]);   // p. ej. WhatsApp: no hay correo que comprobar
        $tk = $this->ticket('Hola, escribo del Hotel Marina Sol: las etiquetas no se actualizan');
        $this->withHeader('X-App-Token', $this->agente())->getJson("/api/ai_suggestions.php?action=suggest&ticket=$tk")->assertOk();
        $p = $this->fake->ultimoSistema;
        $this->assertStringContainsString('ESTADO DE LAS ANTENAS no es un dato sensible', $p);
        $this->assertStringContainsString('SIN CONEXIÓN: Sol-1', $p);
        $this->assertStringContainsString('Para cualquier OTRO dato del hotel', $p);   // usuarios, permisos, etiquetas: antes el correo
    }

    public function test_encuentra_el_hotel_de_la_firma_aunque_no_ponga_la_palabra_hotel(): void
    {
        $svc = app(\App\Services\Ai\AiSuggestionService::class);
        $f = new \ReflectionMethod($svc, 'nombresPorMayusculas');
        $f->setAccessible(true);
        $n = $f->invoke($svc, "Buenos días\nSe han actualizado algunas etiquetas, ¿Lo podríais chequear? Mil gracias,\nSubdirector | Deputy Manager\nBarceló Benidorm Beach\nAvinguda Mare de Déu del Sofrat");
        $this->assertContains('Barceló Benidorm Beach', $n);
        $this->assertNotContains('Buenos días', $n);
        $this->assertNotContains('Subdirector Deputy Manager', $n);
    }

    public function test_si_el_hotel_que_dice_no_coincide_con_el_correo_se_explica_con_tacto_y_se_deriva(): void
    {
        $this->activar();
        $this->flota('m@x.com');
        $tk = $this->ticket('Hola, soy del Hotel Luna y una etiqueta AB12CD345 no se actualiza');
        $this->withHeader('X-App-Token', $this->agente())->getJson("/api/ai_suggestions.php?action=suggest&ticket=$tk")->assertOk();
        $p = $this->fake->ultimoSistema;
        $this->assertStringContainsString('no figura asociado a ese hotel', $p);
        $this->assertStringContainsString('un compañero del equipo revisará su caso', $p);
        $this->assertStringContainsString('NO reveles a qué hotel pertenece un correo', $p);
        $this->assertStringNotContainsString('Antena Frios', $p);   // sin datos del hotel
        $this->assertStringNotContainsString('Hotel Sol', $p);      // ni del hotel real del correo
    }

    public function test_si_hotel_y_correo_coinciden_sigue_con_los_datos(): void
    {
        $this->activar();
        $this->flota('m@x.com');
        $tk = $this->ticket('Soy del Hotel Sol y mi etiqueta AB12CD345 no se actualiza');
        $this->withHeader('X-App-Token', $this->agente())->getJson("/api/ai_suggestions.php?action=suggest&ticket=$tk")->assertOk();
        $this->assertStringContainsString('VERIFICADO', $this->fake->ultimoSistema);
        $this->assertStringContainsString('SIN CONEXIÓN: Antena Frios', $this->fake->ultimoSistema);
    }

    public function test_si_no_se_puede_comprobar_no_dice_que_el_correo_no_pertenece(): void
    {
        $this->activar();
        $this->flota('otro@hotel.com', true);   // alguna app no respondió: resultado incompleto
        $tk = $this->ticket('Mis etiquetas no se actualizan');
        $this->withHeader('X-App-Token', $this->agente())->getJson("/api/ai_suggestions.php?action=suggest&ticket=$tk")->assertOk();
        $p = $this->fake->ultimoSistema;
        $this->assertStringContainsString('NO afirmes que el correo no pertenece', $p);
        $this->assertStringNotContainsString('NO figura como usuario', $p);
    }

    public function test_si_aun_no_hay_correo_se_pregunta_primero_el_hotel(): void
    {
        $this->activar();
        $this->flota('m@x.com');
        DB::table('contacts')->where('id', $this->cid)->update(['email' => null]);
        $tk = $this->ticket('Mis etiquetas no se actualizan');
        $this->withHeader('X-App-Token', $this->agente())->getJson("/api/ai_suggestions.php?action=suggest&ticket=$tk")->assertOk();
        $this->assertStringContainsString('Pregunta PRIMERO el nombre del hotel', $this->fake->ultimoSistema);
    }

    public function test_sin_configurar_la_flota_no_sale_ninguna_peticion(): void
    {
        $this->activar();
        Http::fake();
        $tk = $this->ticket('Mis etiquetas no se actualizan');
        $this->withHeader('X-App-Token', $this->agente())->getJson("/api/ai_suggestions.php?action=suggest&ticket=$tk")->assertOk();
        Http::assertNothingSent();
        $this->assertStringNotContainsString('VERIFICACIÓN DEL CLIENTE', $this->fake->ultimoSistema);
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

        // Pendiente de revisión: todavía NO viaja a la IA.
        $tk2 = $this->ticket('Otra etiqueta que no conecta');
        $this->withHeader('X-App-Token', $token)->getJson("/api/ai_suggestions.php?action=suggest&ticket=$tk2")->assertOk();
        $this->assertStringNotContainsString('Debía pedir el código de la etiqueta primero.', $this->fake->ultimoSistema);
        $this->assertSame('pending', DB::table('ai_suggestions')->where('id', $id)->value('correction_status'));

        // Un agente sin permiso no puede validar; un responsable sí, y entonces viaja como ejemplo.
        $enc = $this->encargado();
        $this->withHeader('X-App-Token', $token)->postJson('/api/ai_suggestions.php?action=admin_review', ['id' => $id, 'status' => 'validated'])->assertStatus(403);
        $this->withHeader('X-App-Token', $enc)->postJson('/api/ai_suggestions.php?action=admin_review', ['id' => $id, 'status' => 'validated'])
            ->assertOk()->assertJsonPath('corrections_pending', 0);
        $tk3 = $this->ticket('Otra etiqueta más que no conecta');
        $this->withHeader('X-App-Token', $token)->getJson("/api/ai_suggestions.php?action=suggest&ticket=$tk3")->assertOk();
        $this->assertStringContainsString('Debía pedir el código de la etiqueta primero.', $this->fake->ultimoSistema);

        // Rechazada: deja de usarse.
        $this->withHeader('X-App-Token', $enc)->postJson('/api/ai_suggestions.php?action=admin_review', ['id' => $id, 'status' => 'rejected'])->assertOk();
        $tk4 = $this->ticket('Y otra etiqueta que no conecta');
        $this->withHeader('X-App-Token', $token)->getJson("/api/ai_suggestions.php?action=suggest&ticket=$tk4")->assertOk();
        $this->assertStringNotContainsString('Debía pedir el código de la etiqueta primero.', $this->fake->ultimoSistema);
    }

    public function test_la_correccion_de_un_responsable_cuenta_como_validada(): void
    {
        $this->activar();
        $token = $this->encargado();
        $tk = $this->ticket('Mi etiqueta no conecta');
        $id = $this->withHeader('X-App-Token', $token)->getJson("/api/ai_suggestions.php?action=suggest&ticket=$tk")->json('suggestion.id');
        $this->withHeader('X-App-Token', $token)->postJson('/api/ai_suggestions.php?action=rate', ['id' => $id, 'rating' => 'down', 'correction' => 'Debía pedir el código primero.'])->assertOk();
        $this->assertSame('validated', DB::table('ai_suggestions')->where('id', $id)->value('correction_status'));
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
