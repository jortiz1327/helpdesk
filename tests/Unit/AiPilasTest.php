<?php

namespace Tests\Unit;

use App\Services\Ai\AiSuggestionService;
use PHPUnit\Framework\TestCase;

/**
 * Tema pilas y baterías: lo que decide el código (sin modelo) y que el manual, las reglas y el criterio base dicen lo mismo.
 */
class AiPilasTest extends TestCase
{
    private function conv(): array
    {
        return [['role' => 'user', 'text' => 'hola']];
    }

    private function leer(string $ruta): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $ruta);
    }

    public function test_pedir_pilas_es_pedido_de_material_y_no_pide_verificar_ni_deriva(): void
    {
        foreach (['Necesito 30 pilas', 'Quería encargarle 10 pilas de las de la foto para las pantallas grandes', 'Podríamos hacer un pedido de tapas y de pilas'] as $t) {
            $this->assertNull(AiSuggestionService::decidir($t, $this->conv(), null, false), $t);
        }
    }

    public function test_garantia_tras_cambiar_la_pila_se_deriva_a_garantias(): void
    {
        $d = AiSuggestionService::decidir('Hemos cambiado las pilas y la etiqueta sigue sin funcionar, queremos tramitar la garantía', $this->conv(), 'verificado', true);
        $this->assertSame('derivar', $d['decision']);
        $this->assertStringContainsString('[[DERIVAR:garantias|', $d['instruccion']);
    }

    public function test_en_un_hotel_sin_verificar_las_pilas_que_no_funcionan_piden_primero_los_datos(): void
    {
        $d = AiSuggestionService::decidir('La etiqueta PS-05 no funciona, le he cambiado las pilas y tampoco', $this->conv(), null, false);
        $this->assertSame('pedir_datos', $d['decision']);
    }

    public function test_el_manual_de_pilas_trae_referencias_marcas_y_el_paso_de_las_tres_fotos(): void
    {
        $m = $this->leer('resources/ai-docs/soporte/pilas-y-baterias.md');
        foreach (['AM-BZ2450', 'CR2450', 'Beidongli', 'HenliMax', 'NSN', 'VARTA', 'postventa@aemegroup.com', 'tres fotos', 'se deriva a Garantías', 'Señales de pila baja', 'Vida útil'] as $clave) {
            $this->assertStringContainsString($clave, $m, 'el manual de pilas no menciona «' . $clave . '»');
        }
        $this->assertStringContainsString('1250', $m);
        $this->assertStringContainsString('5000', $m);
    }

    public function test_el_protocolo_las_reglas_y_el_criterio_base_piden_las_tres_fotos_antes_de_garantias(): void
    {
        $this->assertStringContainsString('tres fotos', $this->leer('resources/ai-docs/soporte/protocolo-garantias-reinicio-etiqueta.md'));
        $this->assertStringContainsString('tres fotos', $this->leer('resources/ai-docs/soporte/reglas-de-derivacion.md'));
        $this->assertStringContainsString('TRES fotos', $this->leer('resources/ai/prompt-reglas.md'));
        $base = json_decode($this->leer('resources/ai/correcciones-base.json'), true);
        $this->assertIsArray($base, 'correcciones-base.json no es JSON válido');
        $c = collect($base)->firstWhere('clave', 'etiqueta-no-cambia-pasos-antes-de-garantias');
        $this->assertNotNull($c);
        $this->assertStringContainsString('TRES fotos', $c['contenido']);
        $this->assertStringContainsString('deriva cuando lleguen', $c['contenido']);
    }
}
