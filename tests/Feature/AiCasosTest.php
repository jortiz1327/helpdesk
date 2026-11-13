<?php

namespace Tests\Feature;

use Tests\TestCase;

class AiCasosTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/ai-eval-test-' . uniqid();
        mkdir($this->dir);
        config(['ai.lab' => true, 'ai.eval_dir' => $this->dir]);   // nunca toca las marcas reales
        file_put_contents("$this->dir/candidatos.json", json_encode([
            ['id' => 7, 'code' => 'TK-1', 'canal' => 'email', 'asunto' => 'Etiqueta', 'pregunta' => 'No conecta', 'respuesta_humana' => 'Pulsa el botón', 'decision_propuesta' => 'responder'],
            ['id' => 8, 'code' => 'TK-2', 'canal' => 'whatsapp', 'asunto' => 'Alérgenos', 'pregunta' => 'Ocultar alérgenos', 'respuesta_humana' => 'Hecho', 'decision_propuesta' => 'responder'],
        ]));
        file_put_contents("$this->dir/marcas.json", json_encode(['7' => ['decision' => 'responder', 'comentario' => ''], '8' => ['decision' => 'responder', 'comentario' => '']]));
        $h = fopen("$this->dir/resultados-20261113-100000.csv", 'w');
        fwrite($h, "\xEF\xBB\xBF");
        fputcsv($h, ['caso', 'canal', 'esperado', 'obtenido', 'coincide', 'alerta', 'estado', 'texto_ia', 'cliente', 'respuesta_humana', 'comentario'], ';');
        fputcsv($h, [7, 'email', 'responder', 'pedir_info', 'no', '', 'ready', "¿De qué hotel\nnos escribe?", 'c', 'h', ''], ';');   // con salto de línea dentro
        fclose($h);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("$this->dir/*") ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_fuera_de_local_no_existe(): void
    {
        config(['ai.lab' => false]);
        $this->get('/ia/casos')->assertNotFound();
        $this->getJson('/ia/casos/api/lista')->assertNotFound();
    }

    public function test_la_pagina_y_el_script_cargan(): void
    {
        $this->get('/ia/casos')->assertOk()->assertSee('Casos de prueba de la IA');
        $this->get('/ia/casos/app.js')->assertOk()->assertHeader('Content-Type', 'application/javascript; charset=utf-8');
    }

    public function test_la_lista_junta_caso_marca_y_lo_que_hizo_la_ia(): void
    {
        $r = $this->getJson('/ia/casos/api/lista')->assertOk();
        $r->assertJsonCount(2);
        $r->assertJsonPath('0.id', 7)->assertJsonPath('0.decision', 'responder')->assertJsonPath('0.revisado', false)
            ->assertJsonPath('0.ia.obtenido', 'pedir_info')->assertJsonPath('0.ia.texto', "¿De qué hotel\nnos escribe?");
        $r->assertJsonPath('1.ia', null);   // sin prueba todavía para ese caso
    }

    public function test_guardar_cambia_la_marca_hace_copia_y_valida(): void
    {
        $this->postJson('/ia/casos/api/8', ['decision' => 'derivar', 'comentario' => ' Alérgenos: siempre derivar ', 'revisado' => true])->assertOk();
        $m = json_decode(file_get_contents("$this->dir/marcas.json"), true);
        $this->assertSame(['decision' => 'derivar', 'comentario' => 'Alérgenos: siempre derivar', 'revisado' => true], $m['8']);
        $this->assertSame('responder', $m['7']['decision']);                       // el resto, intacto
        $this->assertFileExists("$this->dir/marcas.json.bak");
        $this->assertSame('responder', json_decode(file_get_contents("$this->dir/marcas.json.bak"), true)['8']['decision']);

        $this->postJson('/ia/casos/api/8', ['decision' => 'inventada'])->assertStatus(422);
        $this->postJson('/ia/casos/api/999', ['decision' => 'derivar'])->assertNotFound();
        $this->getJson('/ia/casos/api/lista')->assertJsonPath('1.revisado', true)->assertJsonPath('1.decision', 'derivar');
    }
}
