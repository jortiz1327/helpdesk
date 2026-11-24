<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Ai\AiProvider;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Chat de pruebas de la IA dentro de Ajustes (permiso support.config): nada sale a ningún cliente, cada corrección
 * lleva el nombre de quien la escribió, hay que validar la respuesta para seguir, una corrección se retira y se
 * restaura, y el gasto de pruebas tiene su propio tope.
 */
class AiPruebasTest extends TestCase
{
    use RefreshDatabase;

    private function token(string $rol): string
    {
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
        $u = User::create(['name' => 'Marta Soporte', 'email' => $rol . '@x.com', 'password' => bcrypt('x')]);
        $u->assignRole($rol);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        return TokenService::make($u);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(AiProvider::class, new class implements AiProvider {
            public function configured(): bool { return true; }
            public function complete(string $system, array $messages): array
            {
                return ['text' => 'Respuesta de prueba', 'model' => 'fake', 'input_tokens' => 10, 'output_tokens' => 5];
            }
        });
    }

    private function api(string $tok, string $accion, array $body = [], string $metodo = 'post')
    {
        $url = '/api/ai_pruebas.php?action=' . $accion;
        return $this->withHeader('X-App-Token', $tok)->{$metodo . 'Json'}($url, $body);
    }

    public function test_solo_con_permiso_de_configuracion(): void
    {
        $this->api($this->token('agente'), 'status', [], 'get')->assertForbidden();
    }

    public function test_el_encargado_entra_y_el_tope_por_defecto_son_20_euros(): void
    {
        $this->api($this->token('encargado_soporte'), 'status', [], 'get')->assertOk()->assertJsonPath('presupuesto.limit', 20);
    }

    public function test_hay_que_validar_la_respuesta_para_seguir_y_la_correccion_lleva_autor(): void
    {
        $tok = $this->token('encargado_soporte');
        $id = $this->api($tok, 'create', ['name' => 'Javier'])->assertOk()->json('id');
        $this->api($tok, 'send', ['id' => $id, 'text' => 'Hola, no puedo entrar'])->assertOk();
        $ms = $this->api($tok, 'reply', ['id' => $id])->assertOk()->json('messages');
        $bot = collect($ms)->last();
        $this->assertSame('Respuesta de prueba', $bot['body']);

        // Sin validar la respuesta no se puede escribir otro mensaje.
        $this->api($tok, 'send', ['id' => $id, 'text' => 'Otra cosa'])->assertStatus(409);

        $this->api($tok, 'rate', ['id' => $bot['id'], 'rating' => 'down', 'correction' => 'Debía pedir el hotel primero.'])->assertOk();
        $m = DB::table('ai_lab_messages')->where('id', $bot['id'])->first();
        $this->assertSame('Marta Soporte', $m->rated_by_name);
        $this->assertSame('Marta Soporte', DB::table('ai_lab_chats')->where('id', $id)->value('author_name'));
        $this->assertSame(1, DB::table('ai_documents')->where('source_type', 'correccion')->where('source_id', 'chat:' . $bot['id'])->count());

        $this->api($tok, 'send', ['id' => $id, 'text' => 'Otra cosa'])->assertOk();   // ya validada: se puede seguir
    }

    public function test_retirar_quita_la_correccion_de_la_memoria_y_restaurar_la_devuelve(): void
    {
        $tok = $this->token('encargado_soporte');
        $id = $this->api($tok, 'create')->json('id');
        $this->api($tok, 'send', ['id' => $id, 'text' => 'Consulta'])->assertOk();
        $bot = collect($this->api($tok, 'reply', ['id' => $id])->json('messages'))->last();
        $this->api($tok, 'rate', ['id' => $bot['id'], 'rating' => 'down', 'correction' => 'Criterio que no debe usarse.'])->assertOk();
        $doc = fn () => DB::table('ai_documents')->where('source_type', 'correccion')->where('source_id', 'chat:' . $bot['id'])->count();
        $this->assertSame(1, $doc());

        $r = $this->api($tok, 'retire', ['id' => $bot['id'], 'retired' => true])->assertOk();
        $this->assertTrue($r->json('items.0.retired'));
        $this->assertSame('Marta Soporte', $r->json('items.0.retired_by'));
        $this->assertSame(0, $doc());   // la IA ya no la usa

        $r = $this->api($tok, 'retire', ['id' => $bot['id'], 'retired' => false])->assertOk();
        $this->assertFalse($r->json('items.0.retired'));
        $this->assertSame(1, $doc());   // vuelve a la memoria
    }

    public function test_con_el_tope_alcanzado_no_se_llama_a_la_ia(): void
    {
        $tok = $this->token('encargado_soporte');
        $id = $this->api($tok, 'create')->json('id');
        \App\Models\Setting::put('ai_lab_budget_month_eur', '0.000001');
        DB::table('ai_lab_messages')->insert(['chat_id' => $id, 'role' => 'assistant', 'body' => 'x', 'status' => 'failed', 'model' => 'fake', 'input_tokens' => 2000000, 'output_tokens' => 1000000, 'created_at' => now()]);
        $this->api($tok, 'send', ['id' => $id, 'text' => 'Consulta'])->assertOk();
        $this->api($tok, 'reply', ['id' => $id])->assertStatus(429)->assertJsonPath('ok', false);
    }
}
