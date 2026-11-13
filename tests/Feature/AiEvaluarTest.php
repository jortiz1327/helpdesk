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
