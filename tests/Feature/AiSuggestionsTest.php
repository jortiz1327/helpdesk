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

    private function gastar(float $usd): void
    {
        // Con la tarifa de prueba 1 $/M de entrada, 1 M de tokens = 1 $.
        config(['ai.prices' => ['default' => [1.0, 1.0]]]);
        $tk = $this->ticket('Gasto previo');
        DB::table('ai_suggestions')->insert(['ticket_id' => $tk, 'trigger_message_id' => 0, 'status' => 'ready', 'body' => 'x', 'sources' => '{}', 'model' => 'm',
            'input_tokens' => (int) round($usd * 1e6), 'output_tokens' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_con_el_tope_alcanzado_no_se_llama_a_la_ia_y_se_explica(): void
    {
        $this->activar();
        Setting::put('ai_budget_month_usd', '50');
        $this->gastar(50.0);
        $tk = $this->ticket('Mi etiqueta no cambia');
        $r = $this->withHeader('X-App-Token', $this->agente())->getJson("/api/ai_suggestions.php?action=suggest&ticket=$tk")->assertOk();
        $this->assertSame(0, $this->fake->llamadas, 'no sale ninguna llamada al proveedor');
        $this->assertSame('failed', $r->json('suggestion.status'));
        $this->assertTrue($r->json('suggestion.limit_reached'));
    }

    public function test_un_agente_no_puede_pedir_mas_de_30_sugerencias_por_minuto(): void
    {
        $this->activar();
        $tk = $this->ticket('Mi etiqueta no cambia');
        $tok = $this->agente();
        for ($i = 0; $i < \App\Http\Controllers\AiSuggestionsController::MAX_POR_MINUTO; $i++) {
            $this->withHeader('X-App-Token', $tok)->getJson("/api/ai_suggestions.php?action=suggest&ticket=$tk")->assertOk();
        }
        $this->withHeader('X-App-Token', $tok)->getJson("/api/ai_suggestions.php?action=suggest&ticket=$tk")->assertStatus(429);
    }

    public function test_bajo_el_tope_sigue_sugiriendo_y_el_panel_avisa_al_80(): void
    {
        $this->activar();
        Setting::put('ai_budget_month_usd', '50');
        $this->gastar(41.0);
        $tk = $this->ticket('Mi etiqueta no cambia');
        $tok = $this->agente();
        $this->withHeader('X-App-Token', $tok)->getJson("/api/ai_suggestions.php?action=suggest&ticket=$tk")->assertOk();
        $this->assertSame(1, $this->fake->llamadas);
        $b = app(\App\Services\Ai\AiSuggestionService::class)->presupuesto();
        $this->assertSame('warn', $b['level']);
        $this->assertSame(50.0, $b['limit']);
    }

    public function test_tope_cero_es_sin_tope_y_solo_el_responsable_lo_cambia(): void
    {
        $this->activar();
        Setting::put('ai_budget_month_usd', '0');
        $this->gastar(500.0);
        $this->assertFalse(app(\App\Services\Ai\AiSuggestionService::class)->topeAlcanzado());
        $this->withHeader('X-App-Token', $this->agente())->postJson('/api/ai_suggestions.php?action=admin_budget', ['budget' => 10])->assertForbidden();
    }
    public function test_historial_apunta_cada_valoracion_y_lo_que_se_envio_al_final(): void
    {
        $this->activar();
        $tk = $this->ticket('Mi etiqueta no cambia');
        $tok = $this->agente();
        $id = $this->withHeader('X-App-Token', $tok)->getJson("/api/ai_suggestions.php?action=suggest&ticket=$tk")->json('suggestion.id');
        $this->withHeader('X-App-Token', $tok)->postJson('/api/ai_suggestions.php?action=rate', ['id' => $id, 'rating' => 'down', 'correction' => 'Debía pedir el código primero.'])->assertOk();
        $this->withHeader('X-App-Token', $tok)->postJson('/api/ai_suggestions.php?action=rate', ['id' => $id, 'rating' => 'up'])->assertOk();
        // El agente contesta al cliente después de la sugerencia.
        DB::table('messages')->insert(['contact_id' => $this->cid, 'wa_id' => '', 'ticket_id' => $tk, 'direction' => 'out', 'body' => '<p>Hola, ya lo miramos.</p>', 'created_at' => now()->addMinute()]);

        $h = app(\App\Services\Ai\AiSuggestionService::class)->historial();
        $this->assertSame(2, $h['total'], 'las dos valoraciones quedan, la segunda no pisa a la primera');
        $this->assertSame('up', $h['items'][0]['rating']);
        $this->assertSame('down', $h['items'][1]['rating']);
        $this->assertSame('Debía pedir el código primero.', $h['items'][1]['correction']);
        $this->assertSame('Ana', $h['items'][1]['user']);
        $this->assertNotEmpty($h['items'][1]['ai'], 'guarda lo que sugirió la IA');
        $this->assertSame('Hola, ya lo miramos.', $h['items'][0]['sent']['text']);
        $this->assertSame(1, app(\App\Services\Ai\AiSuggestionService::class)->historial('down')['total']);
    }

    public function test_un_pulgar_abajo_rapido_con_motivo_se_apunta_pero_no_ensena_a_la_ia(): void
    {
        $this->activar();
        $tk = $this->ticket('Mi etiqueta no cambia');
        $tok = $this->agente();
        $id = $this->withHeader('X-App-Token', $tok)->getJson("/api/ai_suggestions.php?action=suggest&ticket=$tk")->json('suggestion.id');
        $r = $this->withHeader('X-App-Token', $tok)->postJson('/api/ai_suggestions.php?action=rate', ['id' => $id, 'rating' => 'down', 'reason' => 'derivar'])->assertOk();
        $this->assertSame('derivar', $r->json('suggestion.reason'));
        $fila = DB::table('ai_suggestions')->where('id', $id)->first();
        $this->assertNull($fila->correction);
        $this->assertSame(0, app(\App\Services\Ai\AiSuggestionService::class)->revisionPendiente()['pending'], 'sin texto no hay nada que revisar');
        $h = app(\App\Services\Ai\AiSuggestionService::class)->historial('down');
        $this->assertSame('Debía pasarlo a una persona', $h['items'][0]['reason']);
        // Un motivo inventado y sin texto no vale.
        $this->withHeader('X-App-Token', $tok)->postJson('/api/ai_suggestions.php?action=rate', ['id' => $id, 'rating' => 'down', 'reason' => 'xx'])->assertStatus(422);
        // Con corrección escrita además del motivo, la corrección sí pasa a la memoria.
        $this->withHeader('X-App-Token', $tok)->postJson('/api/ai_suggestions.php?action=rate', ['id' => $id, 'rating' => 'down', 'reason' => 'dato', 'correction' => 'Debía derivarlo a garantías.'])->assertOk();
        $fila = DB::table('ai_suggestions')->where('id', $id)->first();
        $this->assertSame('validated', $fila->correction_status);
    }
    public function test_el_agente_pide_mas_corta_o_mas_cercana_y_solo_cambia_la_redaccion(): void
    {
        $this->activar();
        $tk = $this->ticket('Mi etiqueta no cambia');
        $tok = $this->agente();
        $this->withHeader('X-App-Token', $tok)->getJson("/api/ai_suggestions.php?action=suggest&ticket=$tk")->assertOk();
        $this->assertStringNotContainsString('ESTILO PEDIDO', $this->fake->ultimoSistema);
        $r = $this->withHeader('X-App-Token', $tok)->postJson('/api/ai_suggestions.php?action=regenerate', ['ticket' => $tk, 'largo' => 'corta', 'tono' => 'cercano'])->assertOk();
        $this->assertStringContainsString('ESTILO PEDIDO POR EL AGENTE', $this->fake->ultimoSistema);
        $this->assertStringContainsString('2 o 3 frases', $this->fake->ultimoSistema);
        $this->assertStringContainsString('cercano', $this->fake->ultimoSistema);
        $this->assertSame(['largo' => 'corta', 'tono' => 'cercano'], $r->json('suggestion.sources.estilo'));
        // Valores inventados se ignoran.
        $this->withHeader('X-App-Token', $tok)->postJson('/api/ai_suggestions.php?action=regenerate', ['ticket' => $tk, 'largo' => 'borra todo', 'tono' => 'x'])->assertOk();
        $this->assertStringNotContainsString('ESTILO PEDIDO', $this->fake->ultimoSistema);
    }
    public function test_resumen_del_ticket_se_guarda_se_reutiliza_y_se_rehace_con_mensajes_nuevos(): void
    {
        $this->activar();
        $tok = $this->agente();
        $tk = $this->ticket('Mi etiqueta no conecta');
        // Un solo mensaje: no hay nada que resumir, y no se gasta.
        $this->withHeader('X-App-Token', $tok)->getJson("/api/ai_suggestions.php?action=summary&ticket=$tk")->assertOk()->assertJsonPath('summary', null);
        $this->assertSame(0, $this->fake->llamadas);

        DB::table('messages')->insert(['contact_id' => $this->cid, 'wa_id' => '', 'ticket_id' => $tk, 'direction' => 'out', 'body' => 'Pruebe a reiniciarla.', 'created_at' => now()->addMinute()]);
        DB::table('messages')->insert(['contact_id' => $this->cid, 'wa_id' => '', 'ticket_id' => $tk, 'direction' => 'in', 'body' => 'Sigue igual.', 'created_at' => now()->addMinutes(2)]);
        $this->fake->respuesta = "Qué pasó:\n- No conecta\nQué se ha probado:\n- Reiniciar\nQué falta:\n- El equipo";
        $r = $this->withHeader('X-App-Token', $tok)->getJson("/api/ai_suggestions.php?action=summary&ticket=$tk")->assertOk();
        $this->assertStringContainsString('Qué falta', $r->json('summary.body'));
        $this->assertSame(3, $r->json('summary.messages'));
        $this->assertSame(1, $this->fake->llamadas);
        $this->assertStringContainsString('Resume la conversación', $this->fake->ultimoSistema);

        // Sin mensajes nuevos se reutiliza: no cuesta nada.
        $this->withHeader('X-App-Token', $tok)->getJson("/api/ai_suggestions.php?action=summary&ticket=$tk")->assertOk();
        $this->assertSame(1, $this->fake->llamadas);
        // Con un mensaje nuevo se rehace.
        DB::table('messages')->insert(['contact_id' => $this->cid, 'wa_id' => '', 'ticket_id' => $tk, 'direction' => 'out', 'body' => 'Lo revisamos.', 'created_at' => now()->addMinutes(3)]);
        $this->withHeader('X-App-Token', $tok)->getJson("/api/ai_suggestions.php?action=summary&ticket=$tk")->assertOk();
        $this->assertSame(2, $this->fake->llamadas);
        // El gasto del resumen cuenta para el tope mensual.
        $this->assertSame(2, DB::table('ai_summaries')->count());
        // Si el proveedor falla, se avisa sin romper.
        $this->fake->falla = true;
        $this->withHeader('X-App-Token', $tok)->getJson("/api/ai_suggestions.php?action=summary&ticket=$tk&regen=1")->assertStatus(503)->assertJsonPath('ok', false);
    }
    public function test_la_medicion_cuenta_cuanto_se_aprovecha_el_borrador_y_compara_tiempos(): void
    {
        $this->activar();
        $borrador = 'Hola, para ayudarle necesito el nombre completo de su hotel y el correo con el que accede a la aplicación.';
        $caso = function (string $enviado, ?int $min = 3) use ($borrador) {
            $tk = $this->ticket('Mi etiqueta no conecta');
            DB::table('ai_suggestions')->insert(['ticket_id' => $tk, 'trigger_message_id' => 0, 'status' => 'ready', 'body' => $borrador, 'sources' => '{}',
                'created_at' => now(), 'updated_at' => now()]);
            if ($enviado !== '') {
                DB::table('messages')->insert(['contact_id' => $this->cid, 'wa_id' => '', 'ticket_id' => $tk, 'direction' => 'out', 'body' => "<p>$enviado</p>", 'created_at' => now()->addMinutes($min)]);
            }
            return $tk;
        };
        $caso($borrador);                                                                       // sin cambios
        $caso('Hola, necesito el nombre de su hotel y el correo con el que entra en la aplicación, gracias.');   // retoques
        $caso('Revisado: ya lo he arreglado desde el panel, pruebe ahora y me dice.');          // reescrita
        $caso('');                                                                              // sin respuesta
        $sinIa = $this->ticket('Otra consulta sin IA');
        DB::table('messages')->insert(['contact_id' => $this->cid, 'wa_id' => '', 'ticket_id' => $sinIa, 'direction' => 'out', 'body' => 'Hecho.', 'created_at' => now()->addMinutes(30)]);

        $m = app(\App\Services\Ai\AiSuggestionService::class)->medicion();
        $this->assertSame(4, $m['borradores']);
        $this->assertSame(['sin_cambios' => 1, 'retoques' => 1, 'reescrita' => 1, 'sin_respuesta' => 1], $m['por_uso']);
        $this->assertSame(3, $m['min_hasta_enviar']);
        $this->assertSame(3, $m['tickets_con_ia']);
        $this->assertSame(1, $m['tickets_sin_ia']);
        $this->assertSame(30, $m['min_primera_sin_ia']);
    }

    public function test_avisa_de_las_correcciones_que_llevan_dias_sin_revisar(): void
    {
        $tk = $this->ticket('Mi etiqueta no cambia');
        $fila = ['ticket_id' => $tk, 'trigger_message_id' => 0, 'status' => 'ready', 'body' => 'x', 'sources' => '{}', 'rating' => 'down', 'correction' => 'Debía pedir el código.', 'correction_status' => 'pending', 'created_at' => now(), 'updated_at' => now()];
        DB::table('ai_suggestions')->insert($fila + ['rated_at' => now()->subDays(5)]);
        DB::table('ai_suggestions')->insert($fila + ['rated_at' => now()->subDay()]);
        DB::table('ai_suggestions')->insert(['correction_status' => 'validated'] + $fila + ['rated_at' => now()->subDays(9)]);
        $r = app(\App\Services\Ai\AiSuggestionService::class)->revisionPendiente();
        $this->assertSame(2, $r['pending']);
        $this->assertSame(1, $r['stale'], 'solo la de hace 5 días lleva más de 3 sin revisar; la validada no cuenta');
        $this->assertNotNull($r['oldest']);
    }

    public function test_avisa_de_la_documentacion_que_cambio_y_limpia_lo_borrado(): void
    {
        $k = app(\App\Services\Ai\AiKnowledge::class);
        $k->indexarDocumentacion();   // punto de partida: todo lo que hay ya está en la memoria
        $base = $k->documentacionPendiente();
        $dir = resource_path('ai-docs/zz-prueba-test');
        @mkdir($dir, 0777, true);
        $f = $dir . '/uno.md';
        file_put_contents($f, "# Uno\n\nTexto de prueba para la memoria de la IA, lo bastante largo para que se guarde como un trozo entero.");
        try {
            $this->assertSame($base['nuevos'] + 1, $k->documentacionPendiente()['nuevos'], 'un archivo nuevo se avisa');
            $k->indexarDocumentacion();
            $this->assertSame($base['nuevos'], $k->documentacionPendiente()['nuevos']);
            touch($f, time() + 60);
            $this->assertSame($base['changed'] + 1, $k->documentacionPendiente()['changed'], 'un archivo cambiado se avisa');
            unlink($f);
            $this->assertSame($base['borrados'] + 1, $k->documentacionPendiente()['borrados'], 'un archivo borrado se avisa');
            $k->indexarDocumentacion();
            $this->assertSame(0, DB::table('ai_documents')->where('source_id', 'like', 'doc:zz-prueba-test/%')->count(), 'lo borrado deja de estar en la memoria');
        } finally {
            @unlink($f);
            @rmdir($dir);
        }
    }

    public function test_el_historial_es_solo_para_quien_tiene_support_config(): void
    {
        $this->withHeader('X-App-Token', $this->agente())->getJson('/api/ai_suggestions.php?action=admin_history')->assertForbidden();
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
        $this->assertStringContainsString('Como máximo DOS preguntas por mensaje', $p);
        $this->assertStringContainsString('el correo con el que accede', $p);
        $this->assertStringContainsString('nunca digas que hay varios con ese nombre', $p);
        $this->assertStringContainsString('No preguntes lo obvio ni lo inventado', $p);
        $this->assertStringContainsString('DERIVA sin preguntar más', $p);
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

    public function test_si_el_correo_no_se_pudo_comprobar_pero_el_hotel_viene_nombrado_se_cuentan_las_antenas(): void
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
            "panel.example.com/api/support/v1/buffets/$buf/antennas*" => Http::response(['success' => true, 'data' => ['total' => 1, 'en_linea' => 1,
                'antenas' => [['nombre' => 'Sol-1', 'en_linea' => true, 'etiquetas' => 20]]]]),
            // El panel contesta, pero una de sus apps no: el correo no se puede comprobar (ni en el reintento).
            'panel.example.com/api/support/v1/users/lookup*' => Http::response(['success' => true, 'data' => ['encontrado' => false, 'coincidencias' => [], 'apps_sin_respuesta' => ['AdminHoteles-3']]]),
        ]);
        $tk = $this->ticket('Hola, escribo del Hotel Marina Sol: las etiquetas no se actualizan');
        $this->withHeader('X-App-Token', $this->agente())->getJson("/api/ai_suggestions.php?action=suggest&ticket=$tk")->assertOk();
        $p = $this->fake->ultimoSistema;
        $this->assertStringContainsString('No se ha podido comprobar el correo', $p);
        $this->assertStringContainsString('NO afirmes que el correo no pertenece al hotel', $p);
        $this->assertStringContainsString('todas con conexión', $p);   // aun así, las antenas del hotel que nombra
        $this->assertStringContainsString('Ruedas de menús > Forzar menú', $p);   // con antenas en línea y «sigue igual»: se le da el paso, no se deriva
        $this->assertStringContainsString('Para cualquier OTRO dato del hotel', $p);
    }

    public function test_un_ok_despues_de_la_respuesta_no_se_contesta_pero_una_pregunta_si(): void
    {
        $S = \App\Services\Ai\AiSuggestionService::class;
        $u = fn ($t) => ['role' => 'user', 'text' => $t];
        $a = ['role' => 'assistant', 'text' => 'Fuerce el menú desde Ruedas de menús.'];
        foreach (['ok', 'Vale, gracias!', 'Perfecto 👍', '👍', 'muchas gracias', 'Ok.'] as $acuse) {
            $this->assertTrue($S::soloAcusaRecibo([$u('No cambia'), $a, $u($acuse)]), $acuse);
        }
        $this->assertTrue($S::soloAcusaRecibo([$u('No cambia'), $a, $u('o'), $u('ok')]));   // varios mensajes seguidos
        // Todo lo que sea más que un acuse sigue contestándose.
        foreach (['ok pero sigue igual', 'gracias, y el desayuno?', 'no', 'sí', 'ya lo he forzado y no funciona'] as $no) {
            $this->assertFalse($S::soloAcusaRecibo([$u('No cambia'), $a, $u($no)]), $no);
        }
        $this->assertFalse($S::soloAcusaRecibo([$u('ok')]));   // sin respuesta previa de la IA, se contesta
    }

    public function test_un_audio_de_whatsapp_se_contesta_con_texto_fijo_sin_llamar_a_la_ia(): void
    {
        $S = \App\Services\Ai\AiSuggestionService::class;
        $u = fn ($t) => ['role' => 'user', 'text' => $t];
        $this->assertTrue($S::soloAudios([$u('[audio]')]));
        $this->assertTrue($S::soloAudios([$u('hola'), ['role' => 'assistant', 'text' => 'x'], $u('[audio]'), $u('[audio]')]));
        $this->assertFalse($S::soloAudios([$u('[audio]'), $u('no funciona la etiqueta')]));   // si hay texto, se contesta al texto
        $this->assertFalse($S::soloAudios([$u('hola')]));

        $r = app($S)->responderChat((object) ['title' => 'Audio', 'contact_name' => null, 'contact_email' => null], [$u('[audio]')], $this->fake, true);
        $this->assertSame('ready', $r['status']);
        $this->assertStringContainsString('no podemos escuchar este audio', $r['body']);
        $this->assertSame(0, $this->fake->llamadas);   // no se llamó al proveedor: no gasta nada
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

        // Sin revisión previa: lo que corrige un agente viaja ya a la IA como ejemplo.
        $this->assertSame('validated', DB::table('ai_suggestions')->where('id', $id)->value('correction_status'));
        $tk3 = $this->ticket('Otra etiqueta más que no conecta');
        $this->withHeader('X-App-Token', $token)->getJson("/api/ai_suggestions.php?action=suggest&ticket=$tk3")->assertOk();
        $this->assertStringContainsString('Debía pedir el código de la etiqueta primero.', $this->fake->ultimoSistema);

        // Un agente sin permiso no puede retirarla; un responsable sí (opcional): deja de usarse.
        $enc = $this->encargado();
        $this->withHeader('X-App-Token', $token)->postJson('/api/ai_suggestions.php?action=admin_review', ['id' => $id, 'status' => 'rejected'])->assertStatus(403);
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
