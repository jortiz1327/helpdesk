<?php

namespace Tests\Feature;

use App\Services\Ai\AiProviders;
use App\Services\Ai\AiSuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Los tres proveedores hablan su dialecto y devuelven lo mismo; la comparación a ciegas
 * tapa los datos personales antes de enviarlos y guarda la clave aparte.
 */
class AiProvidersTest extends TestCase
{
    use RefreshDatabase;

    private function claves(): void
    {
        config(['ai.providers.anthropic.api_key' => 'k1', 'ai.providers.gemini.api_key' => 'k2', 'ai.providers.mistral.api_key' => 'k3']);
    }

    private function fakes(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(['model' => 'claude-x', 'content' => [['type' => 'text', 'text' => 'Borrador A']], 'usage' => ['input_tokens' => 11, 'output_tokens' => 4]]),
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'Borrador G']]]]], 'usageMetadata' => ['promptTokenCount' => 12, 'candidatesTokenCount' => 5]]),
            'api.mistral.ai/*' => Http::response(['model' => 'mistral-x', 'choices' => [['message' => ['content' => 'Borrador M']]], 'usage' => ['prompt_tokens' => 13, 'completion_tokens' => 6]]),
        ]);
    }

    public function test_cada_proveedor_devuelve_texto_y_tokens(): void
    {
        $this->claves();
        $this->fakes();
        $msgs = [['role' => 'user', 'content' => 'Hola']];

        $a = AiProviders::make('anthropic')->complete('sys', $msgs);
        $g = AiProviders::make('gemini')->complete('sys', $msgs);
        $m = AiProviders::make('mistral')->complete('sys', $msgs);

        $this->assertSame(['Borrador A', 11, 4], [$a['text'], $a['input_tokens'], $a['output_tokens']]);
        $this->assertSame(['Borrador G', 12, 5], [$g['text'], $g['input_tokens'], $g['output_tokens']]);
        $this->assertSame(['Borrador M', 13, 6], [$m['text'], $m['input_tokens'], $m['output_tokens']]);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'generativelanguage') && $r->hasHeader('x-goog-api-key', 'k2') && $r['systemInstruction']['parts'][0]['text'] === 'sys' && $r['contents'][0]['role'] === 'user');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'mistral') && $r->hasHeader('Authorization', 'Bearer k3') && $r['messages'][0]['role'] === 'system');
    }

    public function test_sin_clave_no_esta_configurado_y_no_llama(): void
    {
        config(['ai.providers.gemini.api_key' => null]);
        Http::fake();
        $this->assertFalse(AiProviders::make('gemini')->configured());
        Http::assertNothingSent();
    }

    private function ticketResuelto(): int
    {
        $cid = DB::table('contacts')->insertGetId(['name' => 'Marta Gómez', 'email' => 'marta@cliente.com']);
        $id = DB::table('tickets')->insertGetId([
            'code' => 'TK-CMP', 'subject' => 'Etiqueta', 'status' => 'resuelto', 'priority' => 'media', 'channel' => 'email',
            'contact_id' => $cid, 'opened_at' => now(), 'last_message_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('messages')->insert(['contact_id' => $cid, 'wa_id' => '', 'ticket_id' => $id, 'direction' => 'in',
            'body' => 'Soy Marta Gómez, escribo a marta@cliente.com o llámame al +34 600 123 456 por la etiqueta.', 'created_at' => now()]);
        return $id;
    }

    public function test_redactar_tapa_datos_personales_solo_si_se_pide(): void
    {
        $this->claves();
        $this->fakes();
        $id = $this->ticketResuelto();

        (new AiSuggestionService($p = AiProviders::make('mistral')))->redactar($id, $p, true);
        Http::assertSent(function ($r) {
            $txt = json_encode($r->data());
            return ! str_contains($txt, 'marta@cliente.com') && ! str_contains($txt, '600 123 456') && ! str_contains($txt, 'Marta')
                && str_contains($txt, '[email]') && str_contains($txt, '[tel]') && str_contains($txt, '[cliente]');
        });

        Http::fake();
        $this->fakes();
        (new AiSuggestionService($p))->redactar($id, $p, false);
        Http::assertSent(fn ($r) => str_contains(json_encode($r->data()), 'marta@cliente.com'));
    }

    public function test_comando_compara_a_ciegas_y_guarda_la_clave_aparte(): void
    {
        $this->claves();
        $this->fakes();
        $this->ticketResuelto();
        File::deleteDirectory(storage_path('app/ai-comparar'));

        $this->artisan('ai:comparar', ['--ultimos' => 5, '--yes' => true])->assertSuccessful();

        $dir = glob(storage_path('app/ai-comparar/*'))[0];
        $ciega = file_get_contents($dir . '/ciega.md');
        $clave = json_decode(file_get_contents($dir . '/clave.json'), true);

        $this->assertStringContainsString('Borrador A', $ciega);
        $this->assertStringNotContainsString('anthropic', strtolower($ciega));            // la ciega no revela quién es quién
        $this->assertStringNotContainsString('marta@cliente.com', $ciega);
        $this->assertEqualsCanonicalizing(['anthropic', 'gemini', 'mistral'], array_column($clave['TK-CMP'], 'proveedor'));
        File::deleteDirectory(storage_path('app/ai-comparar'));
    }

    public function test_el_comando_pide_al_menos_dos_proveedores(): void
    {
        config(['ai.providers.anthropic.api_key' => 'k1', 'ai.providers.gemini.api_key' => null, 'ai.providers.mistral.api_key' => null]);
        Http::fake();
        $this->artisan('ai:comparar', ['--yes' => true])->assertFailed();
        Http::assertNothingSent();
    }
}
