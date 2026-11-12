<?php

namespace Tests\Feature;

use App\Services\Ai\AiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Chats de prueba de la IA (/ia): solo en local, el agente contesta, se valora y lo corregido pasa a la memoria. */
class AiLabChatTest extends TestCase
{
    use RefreshDatabase;

    private $fake;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.lab' => true]);
        $this->fake = new class implements AiProvider {
            public array $sistemas = [];
            public function configured(): bool { return true; }
            public function complete(string $system, array $messages): array
            {
                $this->sistemas[] = $system;
                return ['text' => 'Respuesta ' . count($this->sistemas), 'model' => 'fake', 'input_tokens' => 10, 'output_tokens' => 5];
            }
        };
        $this->app->instance(AiProvider::class, $this->fake);
    }

    public function test_fuera_de_local_no_existe(): void
    {
        config(['ai.lab' => false]);
        $this->get('/ia')->assertNotFound();
        $this->postJson('/ia/api/chats', [])->assertNotFound();
    }

    public function test_la_pagina_carga_y_el_agente_contesta_saludando_solo_la_primera_vez(): void
    {
        $this->get('/ia')->assertOk()->assertSee('Chats de prueba de la IA');
        $id = $this->postJson('/ia/api/chats', ['name' => 'Marta', 'email' => 'm@x.com'])->assertOk()->json('id');

        $r = $this->postJson("/ia/api/chats/$id/send", ['text' => 'Mi etiqueta no cambia de precio'])->assertOk();
        $this->assertSame('Respuesta 1', collect($r->json('messages'))->last()['body']);
        $this->assertStringContainsString('le hablo de AEME Group', $this->fake->sistemas[0]);   // primer mensaje: se presenta

        $this->postJson("/ia/api/chats/$id/send", ['text' => 'Gracias, ¿y la antena?'])->assertOk();
        $this->assertStringContainsString('NO saludes', $this->fake->sistemas[1]);                // ya contestó: no se repite
        $this->assertSame(4, count($this->getJson("/ia/api/chats/$id")->json('messages')));
    }

    public function test_corregir_guarda_en_la_memoria_y_otra_atenua_la_anterior(): void
    {
        $id = $this->postJson('/ia/api/chats', [])->json('id');
        $ms = $this->postJson("/ia/api/chats/$id/send", ['text' => 'Hay que eliminar un usuario del hotel y crear otro'])->json('messages');
        $bot = collect($ms)->last()['id'];

        $this->postJson("/ia/api/messages/$bot/rate", ['rating' => 'down', 'correction' => 'x'])->assertStatus(422);   // sin porqué no se aprende
        $this->postJson("/ia/api/messages/$bot/rate", ['rating' => 'down', 'correction' => 'Se hace desde Usuarios, indícaselo paso a paso.'])->assertOk();
        $doc = DB::table('ai_documents')->where('source_type', 'correccion')->where('source_id', "chat:$bot")->first();
        $this->assertNotNull($doc);
        $this->assertStringContainsString('Se hace desde Usuarios', $doc->content);
        $this->assertStringContainsString('eliminar un usuario', $doc->content);

        $nuevos = $this->postJson("/ia/api/chats/$id/regenerate")->assertOk()->json('messages');
        $this->assertTrue((bool) collect($nuevos)->firstWhere('id', $bot)['superseded']);       // la mala queda atenuada
        $this->assertSame('Respuesta 2', collect($nuevos)->last()['body']);

        $this->deleteJson("/ia/api/chats/$id")->assertOk();
        $this->assertSame(0, DB::table('ai_documents')->where('source_id', "chat:$bot")->count());   // al borrar el chat, se olvida
    }
}
