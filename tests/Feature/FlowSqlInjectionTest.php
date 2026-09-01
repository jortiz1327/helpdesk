<?php

namespace Tests\Feature;

use App\Services\FlowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El nodo «Consultar base de datos» en modo SQL crudo interpolaba los {{{vars}}} del
 * CLIENTE dentro de la cadena → inyección SQL. Ahora van como parámetros: un valor
 * malicioso se busca como TEXTO literal, no altera la consulta.
 */
class FlowSqlInjectionTest extends TestCase
{
    use RefreshDatabase;

    private function correr(array $vars): array
    {
        $engine = app(FlowEngine::class);
        $m = new \ReflectionMethod($engine, 'query');
        $m->setAccessible(true);
        $d = ['mode' => 'sql', 'query' => "SELECT name FROM contacts WHERE name = '{{{v}}}'", 'saveTo' => 'out'];
        $m->invokeArgs($engine, [$d, &$vars, null]);
        return $vars;
    }

    public function test_el_nodo_sql_no_es_inyectable(): void
    {
        DB::table('contacts')->insert(['name' => 'Alice', 'email' => 'a@x.com']);

        // Payload clásico: si se interpolara, «name = '' OR '1'='1'» traería a Alice.
        $vars = $this->correr(['v' => "' OR '1'='1"]);

        $this->assertArrayNotHasKey('out', $vars);   // se busca el literal → no matchea → no guarda nada
    }

    public function test_el_nodo_sql_parametrizado_devuelve_el_valor_legitimo(): void
    {
        DB::table('contacts')->insert(['name' => 'Alice', 'email' => 'a@x.com']);

        $vars = $this->correr(['v' => 'Alice']);

        $this->assertSame('Alice', $vars['out'] ?? null);
    }
}
