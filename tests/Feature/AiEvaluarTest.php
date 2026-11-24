<?php

namespace Tests\Feature;

use App\Console\Commands\AiEvaluar;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiSuggestionService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AiEvaluarTest extends TestCase
{
    public function test_clasifica_lo_que_hizo_la_ia(): void
    {
        $this->assertSame('derivar', AiEvaluar::clasificar('abstained', null));
        $this->assertSame('derivar', AiEvaluar::clasificar('failed', null));
        $this->assertSame('derivar', AiEvaluar::clasificar('ready', 'Gracias por escribirnos. Un compañero del equipo revisará su caso y le atenderá.'));
        $this->assertSame('pedir_info', AiEvaluar::clasificar('ready', '¿De qué hotel nos escribe y cuál es el código de la etiqueta?'));
        $this->assertSame('responder', AiEvaluar::clasificar('ready', 'Entra en Usuarios, pulsa Eliminar y confirma con tu contraseña. ¿Te funciona?'));
    }

    public function test_la_etiqueta_api_se_reconoce_en_cualquier_parte_del_comentario(): void
    {
        $this->assertTrue(AiEvaluar::necesitaApi('[API] hay que mirar la rueda'));
        $this->assertTrue(AiEvaluar::necesitaApi("Esto con [api] lo verá\notra línea"));
        $this->assertTrue(AiEvaluar::necesitaApi('[ API ]'));
        $this->assertFalse(AiEvaluar::necesitaApi('usar la api de adminhoteles'));
        $this->assertFalse(AiEvaluar::necesitaApi(null));
    }

    public function test_la_verificacion_primero_vale_por_las_dos_ordenes(): void
    {
        $f = [\App\Console\Commands\AiEvaluar::class, 'esperadoConVerificacion'];
        // «responder» o «derivar» con [VERIF]: pedir hotel y correo primero es acierto.
        $this->assertSame('pedir_info', $f('derivar', 'pedir_info', 'Es de garantías [VERIF]'));
        $this->assertSame('pedir_info', $f('responder', 'pedir_info', '[verif] explicar los pasos'));
        $this->assertSame('derivar', $f('derivar', 'derivar', '[VERIF]'));
        // Sin la etiqueta no se perdona: pedir datos donde debía derivar sigue siendo fallo.
        $this->assertSame('derivar', $f('derivar', 'pedir_info', 'garantías'));
        // «pedir_info» por «PRIMERO se verifica… después se deriva»: ir directo a derivar también vale.
        $c = 'PRIMERO se verifica (pedir el correo); después se deriva como dice el resto del comentario.';
        $this->assertSame('derivar', $f('pedir_info', 'derivar', $c));
        $this->assertSame('pedir_info', $f('pedir_info', 'responder', $c), 'contestar sola nunca vale');
        $this->assertSame('pedir_info', $f('pedir_info', 'derivar', 'faltan datos'));
    }

    public function test_el_juez_clasifica_con_el_modelo_y_cae_a_la_regla_si_falla(): void
    {
        $juez = fn (string $dice) => new class($dice) implements AiProvider {
            public function __construct(private string $dice) {}
            public function configured(): bool { return true; }
            public function complete(string $system, array $messages): array { return ['text' => $this->dice, 'model' => 'f', 'input_tokens' => 10, 'output_tokens' => 1]; }
        };
        [$d, $in, $out] = AiEvaluar::juzgar($juez('pedir_info'), 'Mi etiqueta no va', 'ready', 'Indícame los datos: 1. hotel 2. código. Después pulsa el botón.');
        $this->assertSame(['pedir_info', 10, 1], [$d, $in, $out]);          // el modelo manda sobre la regla por palabras
        $this->assertSame('responder', AiEvaluar::juzgar($juez('Responder.'), 'x', 'ready', 'Haz esto.')[0]);
        $this->assertSame('derivar', AiEvaluar::juzgar($juez('no sé'), 'x', 'abstained', null)[0]);   // sin texto no se gasta una llamada
        $falla = new class implements AiProvider {
            public function configured(): bool { return true; }
            public function complete(string $system, array $messages): array { throw new \RuntimeException('caído'); }
        };
        $this->assertSame('pedir_info', AiEvaluar::juzgar($falla, 'x', 'ready', '¿De qué hotel nos escribe?')[0]);   // cae a la regla
    }

    public function test_derivar_y_no_sabe_se_miden_por_la_marca_sin_llamar_al_juez(): void
    {
        $noDebeLlamar = new class implements AiProvider {
            public function configured(): bool { return true; }
            public function complete(string $system, array $messages): array { throw new \LogicException('no debía llamarse al juez'); }
        };
        $conMarca = json_encode(['faqs' => [], 'derivar' => ['categoria' => 'Garantías', 'motivo' => 'garantía']]);
        $sinMarca = json_encode(['faqs' => [], 'derivar' => null]);

        // Con la marca [[DERIVAR]]: derivar, aunque el texto parezca otra cosa, sin tokens y sin llamada.
        $this->assertSame(['derivar', 0, 0, true], AiEvaluar::juzgar($noDebeLlamar, 'x', 'ready', 'Entra en Usuarios y pulsa Guardar.', $conMarca));
        // «No sé» (sin borrador): derivar por la marca.
        $this->assertSame(['derivar', 0, 0, true], AiEvaluar::juzgar($noDebeLlamar, 'x', 'abstained', null, $sinMarca));
        // Borrador sin marca: sigue juzgándose como pedir_info o responder (aquí el juez cae a la regla porque falla).
        $r = AiEvaluar::juzgar($noDebeLlamar, 'x', 'ready', '¿De qué hotel nos escribe?', $sinMarca);
        $this->assertSame(['pedir_info', false], [$r[0], $r[3]]);
        $this->assertNull(AiEvaluar::porMarca('ready', 'Hola', null));
        $this->assertNull(AiEvaluar::porMarca('ready', 'Hola', 'no es json'));
    }

    public function test_el_prompt_distingue_pedir_el_correo_de_un_presupuesto_de_pedir_que_lo_preparemos(): void
    {
        $fake = new class implements AiProvider {
            public string $sistema = '';
            public function configured(): bool { return true; }
            public function complete(string $system, array $messages): array { $this->sistema = $system; return ['text' => 'Borrador', 'model' => 'f', 'input_tokens' => 1, 'output_tokens' => 1]; }
        };
        app(AiSuggestionService::class)->evaluarCaso('Presupuesto', 'Me podéis pasar un correo para pedir presupuestos de etiquetas rotas', null, $fake);
        $this->assertStringContainsString('El cliente pide EL CORREO, A QUIÉN o DÓNDE pedir un presupuesto', $fake->sistema);
        $this->assertStringContainsString('escriba a postventa@aemegroup.com', $fake->sistema);
        $this->assertStringContainsString('EXACTAMENTE «Asignar al departamento de Pedidos y facturas.»', $fake->sistema);
    }

    public function test_el_prompt_no_lleva_codigo_php_sin_resolver(): void
    {
        $fake = new class implements AiProvider {
            public string $sistema = '';
            public function configured(): bool { return true; }
            public function complete(string $system, array $messages): array { $this->sistema = $system; return ['text' => 'Borrador', 'model' => 'f', 'input_tokens' => 1, 'output_tokens' => 1]; }
        };
        app(AiSuggestionService::class)->evaluarCaso('Etiqueta', 'Mi etiqueta no cambia', null, $fake);
        $this->assertStringNotContainsString('self::', $fake->sistema);
        $this->assertStringNotContainsString("'. ", $fake->sistema);
        $this->assertStringContainsString('No derives ni contestes [[NO_SABE]]', $fake->sistema);
        // El mapa de la aplicación de hoteles va SIEMPRE en el prompt (sin depender de la búsqueda de guías).
        $this->assertStringContainsString('MAPA DE LA APLICACIÓN DE HOTELES', $fake->sistema);
        $this->assertStringContainsString('- Servicios — desayuno, comida, cena', $fake->sistema);
        $this->assertStringNotContainsString('](15-servicios.md)', $fake->sistema);
    }

    public function test_el_prompt_lleva_el_vocabulario_del_cliente_y_separa_etiquetas_de_pantallas_grandes(): void
    {
        $fake = new class implements AiProvider {
            public string $sistema = '';
            public function configured(): bool { return true; }
            public function complete(string $system, array $messages): array { $this->sistema = $system; return ['text' => 'Borrador', 'model' => 'f', 'input_tokens' => 1, 'output_tokens' => 1]; }
        };
        app(AiSuggestionService::class)->evaluarCaso('Etiqueta', 'Mis tarjetitas no cambian', null, $fake);
        foreach (['«tarjetas»', '«tarjetitas»', '«cartelitos»', '«carteles»', '«pantallitas»', 'etiquetas', 'televisores y tótems son pantallas grandes (TV) que NO son etiquetas'] as $x) {
            $this->assertStringContainsString($x, $fake->sistema);
        }
    }

    /** Auditoría de contradicciones: se ejecuta con toda la suite, así cada cambio de reglas pasa por aquí. */
    public function test_auditoria_del_prompt_una_sola_prioridad_y_sin_reglas_repetidas(): void
    {
        $base = file_get_contents(resource_path('ai/prompt-soporte.md'));
        $reglas = file_get_contents(resource_path('ai/prompt-reglas.md'));
        $todo = $base . "\n" . $reglas;

        // Una única norma que manda: nadie más se declara supremo.
        $this->assertSame(0, preg_match_all('/prevalece sobre|manda sobre|anula lo que|gana este orden/iu', $todo), 'Hay más de una norma que se declara suprema');
        $this->assertStringContainsString('ORDEN DE DECISIÓN', $reglas);
        // Las garantías se derivan sin preguntar desde el primer punto del orden (al reescribir el prompt se perdió y bajó el acierto).
        $this->assertStringContainsString('DERIVA sin preguntar más (cliente ya verificado): garantías', $reglas);
        // Una sola instrucción sobre cómo derivar: nada de proponer otro correo de destino.
        $this->assertStringNotContainsString('etiquetaselectronicas.com', $todo);
        // Cada regla grande vive en un único sitio.
        foreach (['Como máximo DOS preguntas por mensaje', 'PRESUPUESTOS, dos casos', 'GARANTÍAS DE FARMACIAS', 'ACTIVAR una etiqueta en una posición', 'Hemos registrado la incidencia'] as $frase) {
            $this->assertSame(1, substr_count($todo, $frase), "«{$frase}» debería aparecer una sola vez");
        }
        // Presupuesto de postventa y material: las dos frases literales existen una vez.
        $this->assertSame(1, substr_count($todo, 'EXACTAMENTE «Asignar al departamento de Pedidos y facturas.»'));
        // Tamaño: sin las reglas el prompt vuelve a engordar sin que nadie lo note.
        $this->assertLessThan(24000, strlen($todo), 'El prompt fijo ha crecido demasiado: adelgázalo antes de añadir reglas');
    }

    public function test_un_caso_no_se_copia_a_si_mismo_ni_guarda_nada(): void
    {
        $fake = new class implements AiProvider {
            public string $sistema = '';
            public function configured(): bool { return true; }
            public function complete(string $system, array $messages): array { $this->sistema = $system; return ['text' => 'Borrador', 'model' => 'f', 'input_tokens' => 1, 'output_tokens' => 1]; }
        };
        $texto = 'La etiqueta electrónica del buffet no conecta con la antena tras cambiar la pila';
        $limpiar = fn () => DB::table('ai_documents')->where('source_type', 'ticket')->whereIn('source_id', ['900', '901'])->delete();
        $limpiar();
        $this->beforeApplicationDestroyed($limpiar);   // esta base no se revierte sola: no se deja nada
        foreach ([[900, 'RESPUESTA-PROPIA-900'], [901, 'RESPUESTA-DE-OTRO-901']] as [$id, $marca]) {
            DB::table('ai_documents')->insert(['source_type' => 'ticket', 'source_id' => (string) $id, 'title' => 'Etiqueta sin conexión',
                'content' => "$texto. $marca", 'indexed_at' => now()]);
        }

        $r = app(AiSuggestionService::class)->evaluarCaso('Etiqueta sin conexión', $texto, 900, $fake);

        $this->assertSame('ready', $r['status']);
        $this->assertStringNotContainsString('RESPUESTA-PROPIA-900', $fake->sistema);   // leave-one-out
        $this->assertStringContainsString('RESPUESTA-DE-OTRO-901', $fake->sistema);
        $this->assertSame(0, DB::table('ai_suggestions')->count());                      // no guarda nada
    }
}
