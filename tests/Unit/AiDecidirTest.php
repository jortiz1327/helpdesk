<?php

namespace Tests\Unit;

use App\Services\Ai\AiSuggestionService;
use PHPUnit\Framework\TestCase;

class AiDecidirTest extends TestCase
{
    private function conv(string $texto, bool $conRespuesta = false): array
    {
        $c = [['role' => 'user', 'text' => 'hola']];
        if ($conRespuesta) {
            $c[] = ['role' => 'assistant', 'text' => '¿Con qué correo accede?'];
            $c[] = ['role' => 'user', 'text' => $texto];
        }
        return $c;
    }

    public function test_alergenos_y_totems_siempre_derivan(): void
    {
        $d = AiSuggestionService::decidir('Las etiquetas no muestran los alérgenos', $this->conv('x'), 'verificado', true);
        $this->assertSame('derivar', $d['decision']);
        $this->assertStringContainsString('[[DERIVAR:soporte|alérgenos]]', $d['instruccion']);
        $this->assertSame('derivar', AiSuggestionService::decidir('El plato lleva gluten y no aparece en alérgenos', $this->conv('x'), 'verificado', false)['decision']);
        // Qué alérgenos están activados en la lista es configuración del hotel: lo explica el modelo con la guía, no se deriva en código.
        $this->assertNull(AiSuggestionService::decidir('Por qué no me aparecen en alérgenos más cosas, como el cerdo. Solo salen 17', $this->conv('x'), 'verificado', true));
        $this->assertSame('derivar', AiSuggestionService::decidir('Queremos un tótem nuevo', $this->conv('x'), 'verificado', true)['decision']);
    }

    public function test_sustituir_etiqueta_rota_por_otra_que_ya_tienen_o_pilas_y_forzar_no_es_garantia(): void
    {
        $d = AiSuggestionService::decidir('Tenemos que sustituir las etiquetas, se ha roto la pantalla. Se puede dar de alta la siguiente', $this->conv('x'), null, false);
        $this->assertSame('pedir_datos', $d['decision']);
        $d = AiSuggestionService::decidir('Le pongo pilas nuevas y me la forzais', $this->conv('x'), 'verificado', true);
        $this->assertSame('responder', $d['decision']);
        $this->assertStringNotContainsString('DERIVAR', $d['instruccion']);
        // Si piden que les enviemos etiquetas, sigue siendo cosa de otra regla.
        $this->assertNull(AiSuggestionService::decidir('Hay que sustituir una etiqueta con la pantalla rota, envíennos una nueva y denla de alta', $this->conv('x'), 'verificado', false));
    }

    public function test_reposicion_con_una_etiqueta_solo_bloqueada_va_primero_al_reinicio(): void
    {
        $t = 'Dos etiquetas no cambian aunque forcemos. Una tiene humedad. La otra se ha quedado bloqueada. Necesitamos que nos envien dos etiquetas nuevas de reposicion';
        $this->assertSame('pedir_datos', AiSuggestionService::decidir($t, $this->conv('x'), null, false)['decision']);
        $d = AiSuggestionService::decidir($t, $this->conv('x'), 'verificado', true);
        $this->assertSame('responder', $d['decision']);
        $this->assertStringNotContainsString('DERIVAR', $d['instruccion']);
        $this->assertNull(AiSuggestionService::decidir($t . ' Ya cambiamos las pilas', $this->conv('x'), 'verificado', true), 'si ya probó el reinicio, decide el modelo');
    }

    public function test_hotel_don_pancho_siempre_deriva(): void
    {
        $d = AiSuggestionService::decidir('Problema con nuevas etiquetas (Hotel Don Pancho, Benidorm). Template not matched', $this->conv('x'), 'verificado', true);
        $this->assertSame('derivar', $d['decision']);
        $this->assertSame('derivar', AiSuggestionService::decidir('Duda sobre el menu info@donpancho.com', $this->conv('x'), 'verificado', true)['decision']);
    }

    public function test_las_apps_que_no_gestionamos_son_una_lista_y_derivan_aunque_cambie(): void
    {
        $d = AiSuggestionService::decidir('Problema con la app del Hotel Playa Sol', $this->conv('x'), 'verificado', true, false, ['Playa  Sol']);
        $this->assertSame('derivar', $d['decision']);
        $this->assertStringContainsString('Playa  Sol', $d['resumen']);
        $this->assertNull(AiSuggestionService::decidir('Las etiquetas no se actualizan en el Hotel Luna', $this->conv('x'), 'verificado', true, false, ['Playa Sol']));
        $this->assertNull(AiSuggestionService::decidir('Las etiquetas no se actualizan en don pancho', $this->conv('x'), 'verificado', true, false, []), 'con la lista vacía no se deriva por nombre');
        $this->assertSame('derivar', AiSuggestionService::decidir('Duda de DON PANCHO', $this->conv('x'), 'verificado', true, false, ['Don Pancho'])['decision']);
        $this->assertSame(['Hotel A', 'Hotel B'], AiSuggestionService::limpiarAjenas("Hotel A\n hotel a \nHotel B, xx\n"));
    }

    public function test_garantia_deriva_pero_la_de_farmacia_la_decide_el_modelo(): void
    {
        $this->assertSame('garantias', explode('|', explode('DERIVAR:', AiSuggestionService::decidir('Queremos la garantía', $this->conv('x'), 'verificado', false)['instruccion'])[1])[0]);
        $this->assertNull(AiSuggestionService::decidir('Garantía de las etiquetas de la farmacia', $this->conv('x'), null, false));
    }

    public function test_permisos_y_roles_los_cambia_el_hotel_asi_que_no_se_deriva_en_codigo(): void
    {
        $this->assertNull(AiSuggestionService::decidir('Necesitamos ampliar permisos al jefe de cocina', $this->conv('x'), 'verificado', true));
    }

    public function test_problema_de_uso_sin_hotel_ni_correo_pide_datos_solo_la_primera_vez(): void
    {
        $d = AiSuggestionService::decidir('Las etiquetas no se actualizan', $this->conv('x'), null, false);
        $this->assertSame('pedir_datos', $d['decision']);
        $this->assertSame('pedir_datos', AiSuggestionService::decidir('La de CL02-05 debería poner otro plato y tampoco cambia', $this->conv('x'), null, false)['decision']);
        $this->assertNull(AiSuggestionService::decidir('Las etiquetas no se actualizan', $this->conv('x', true), 'verificado', false), 'en un seguimiento decide el modelo');
        $this->assertNull(AiSuggestionService::decidir('Las etiquetas no se actualizan', $this->conv('x'), 'verificado', true), 'con hotel identificado decide el modelo');
        $this->assertNull(AiSuggestionService::decidir('Las etiquetas no se actualizan', $this->conv('x'), 'verificado', false), 'con correo verificado decide el modelo');
    }

    public function test_un_mensaje_sin_tema_claro_lo_decide_el_modelo(): void
    {
        $this->assertNull(AiSuggestionService::decidir('Gracias, ya funciona', $this->conv('x'), null, false));
        $this->assertNull(AiSuggestionService::decidir('Necesito 30 pilas', $this->conv('x'), null, true));
    }

    public function test_en_un_hotel_se_verifica_siempre_antes_de_contestar_o_derivar(): void
    {
        $d = AiSuggestionService::decidir("Queremos la garantía de las etiquetas", $this->conv('x'), 'sin_correo', false);
        $this->assertSame('pedir_datos', $d['decision']);
        $d = AiSuggestionService::decidir("¿Cómo creo una posición nueva?", $this->conv('x'), 'sin_correo', true);
        $this->assertSame('pedir_datos', $d['decision']);
        $this->assertStringContainsString('SOLO el correo', $d['instruccion']);
        // Material y presupuestos, farmacias y tiendas no dependen de la verificación del hotel.
        $this->assertNull(AiSuggestionService::decidir("Necesito 30 pilas", $this->conv('x'), 'sin_correo', false));
        $this->assertNull(AiSuggestionService::decidir("Etiquetas de la farmacia sin stock", $this->conv('x'), 'sin_correo', false));
        // Una tienda (balanza, carnicería, caja…) no es un hotel: de momento la lleva una persona, sin pedirle hotel ni correo.
        $d = AiSuggestionService::decidir('Desde la balanza no cambia los precios automáticamente a las etiquetas electrónicas', $this->conv('x'), 'sin_correo', false);
        $this->assertSame('derivar', $d['decision']);
        $this->assertStringContainsString('tienda o comercio', $d['resumen']);
        $this->assertSame('derivar', AiSuggestionService::decidir('Tengo una carnicería y las etiquetas no cambian', $this->conv('x'), 'sin_correo', false)['decision']);
        // Si ya es un usuario verificado de la aplicación, o es un pedido de material, no se desvía.
        $this->assertNotSame('derivar', AiSuggestionService::decidir('La balanza de la tienda del hotel no marca bien', $this->conv('x'), 'verificado', true)['decision'] ?? null);
        $this->assertNull(AiSuggestionService::decidir('Necesito 30 pilas para la tienda', $this->conv('x'), 'sin_correo', false));
        // El sitio que nombra no está en el listado de hoteles: quizá no sea un hotel, no se fuerza la norma (manda la verificación normal).
        $this->assertNull(AiSuggestionService::decidir('Somos la universidad, las etiquetas no cambian', $this->conv('x'), 'sin_correo', true, true));
        // Ya comprobado (aunque no figure o el panel no responda) deciden las reglas normales.
        $this->assertSame('derivar', AiSuggestionService::decidir("Queremos la garantía", $this->conv('x'), 'no_encontrado', false)['decision']);
    }

}
