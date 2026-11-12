<?php

namespace Tests\Feature;

use App\Services\Ai\AiKnowledge;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiSuggestionService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Base de conocimiento: entra lo resuelto y lo que contestó una persona, sale todo dato
 * personal y NUNCA las notas internas. DatabaseTruncation (no una transacción): el índice
 * FULLTEXT de InnoDB no ve filas sin confirmar.
 */
class AiKnowledgeTest extends TestCase
{
    use DatabaseTruncation;

    private int $cid;

    private static int $hechos = 0;

    /**
     * El truncado deja filas CONFIRMADAS que chocarían con los tests de transacción que
     * vengan después en el mismo proceso: tras el último test de la clase se reconstruye
     * la base de pruebas (solo si es la de pruebas).
     */
    protected function tearDown(): void
    {
        $total = count(array_filter(get_class_methods($this), fn ($m) => str_starts_with($m, 'test_')));
        if (++self::$hechos >= $total && DB::connection()->getDatabaseName() === 'helpdesk_test') {
            $this->artisan('migrate:fresh', ['--force' => true]);
        }
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->cid = DB::table('contacts')->insertGetId(['name' => 'Marta Gómez', 'email' => 'marta@cliente.com']);
        $uid = DB::table('users')->insertGetId(['name' => 'Ana', 'email' => 'ana@x.com', 'password' => 'x']);
        $this->uid = $uid;
    }

    private int $uid;

    private function ticket(string $estado, string $asunto, string $pregunta, ?string $respuesta, bool $conNota = false): int
    {
        $id = DB::table('tickets')->insertGetId([
            'code' => 'TK-' . uniqid(), 'subject' => $asunto, 'status' => $estado, 'priority' => 'media', 'channel' => 'email',
            'contact_id' => $this->cid, 'opened_at' => now(), 'last_message_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $m = fn ($dir, $body, $nota = 0, $autor = null) => DB::table('messages')->insert(['contact_id' => $this->cid, 'wa_id' => '', 'ticket_id' => $id,
            'direction' => $dir, 'body' => $body, 'is_internal_note' => $nota, 'author_user_id' => $autor, 'created_at' => now()]);
        $m('in', $pregunta);
        if ($respuesta) {
            $m('out', $respuesta, 0, $this->uid);
        }
        if ($conNota) {
            $m('out', 'NOTA SECRETA: el cliente es un moroso conocido', 1, $this->uid);
        }
        return $id;
    }

    public function test_indexa_solo_lo_valido_sin_datos_personales_ni_notas(): void
    {
        $bueno = $this->ticket('resuelto', 'Etiqueta electrónica sin conexión', 'Soy Marta Gómez (marta@cliente.com, 600 123 456). Mi etiqueta electrónica no conecta con la antena.',
            'Hola Marta, reinicie la etiqueta electrónica acercándola a la antena y pulse el botón trasero durante cinco segundos.', true);
        $this->ticket('abierto', 'Abierto', 'Pregunta suficientemente larga abierta', 'Respuesta suficientemente larga abierta');
        $this->ticket('resuelto', 'Sin respuesta humana', 'Pregunta suficientemente larga sin respuesta', null);
        $mal = $this->ticket('resuelto', 'Mal valorado', 'Pregunta suficientemente larga mal valorada', 'Respuesta suficientemente larga mal valorada');
        DB::table('ticket_ratings')->insert(['ticket_id' => $mal, 'score' => 1, 'created_at' => now(), 'updated_at' => now()]);

        [$nuevos] = (new AiKnowledge())->indexarTickets();

        $this->assertSame(1, $nuevos);
        $doc = DB::table('ai_documents')->where('source_type', 'ticket')->first();
        $this->assertSame((string) $bueno, $doc->source_id);
        $this->assertStringContainsString('reinicie la etiqueta', $doc->content);
        foreach (['marta@cliente.com', '600 123 456', 'Marta', 'Gómez', 'NOTA SECRETA', 'moroso'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $doc->content . $doc->title);
        }
        $this->assertSame(0, (new AiKnowledge())->indexarTickets()[0]);   // incremental: no repite
    }

    public function test_tapa_enlaces_con_parametros(): void
    {
        $t = \App\Services\Ai\Scrub::tapar('Mira https://aemegroup.com/ayuda y https://x.cloud/portal?token=abc123 ok');
        $this->assertStringContainsString('https://aemegroup.com/ayuda', $t);
        $this->assertStringNotContainsString('abc123', $t);
        $this->assertStringContainsString('[enlace]', $t);
    }

    public function test_indexa_el_manual_del_cliente(): void
    {
        $this->assertGreaterThan(0, (new AiKnowledge())->indexarManuales());
        $this->assertTrue(DB::table('ai_documents')->where('source_type', 'manual')->exists());
    }

    public function test_busca_casos_parecidos_y_no_se_copia_a_si_mismo(): void
    {
        $a = $this->ticket('resuelto', 'Etiqueta electrónica sin conexión', 'Mi etiqueta electrónica no conecta con la antena de la tienda.', 'Reinicie la etiqueta electrónica acercándola a la antena de la tienda.');
        $this->ticket('resuelto', 'Factura de marzo', 'Necesito descargar la factura de marzo del portal.', 'Puede descargar la factura de marzo desde el apartado Facturas del portal.');
        $k = new AiKnowledge();
        $k->indexarTickets();

        $r =$k->buscar('Etiqueta electrónica sin conexión con la antena', null);
        $this->assertCount(1, $r);
        $this->assertSame((string) $a, $r[0]->source_id);                // la de facturas no encaja

        $this->assertSame([], $k->buscar('Etiqueta electrónica sin conexión con la antena', $a));   // sin copiarse a sí mismo
    }

    public function test_el_prompt_lleva_el_caso_parecido_y_lo_cita_en_las_fuentes(): void
    {
        $viejo = $this->ticket('resuelto', 'Etiqueta electrónica sin conexión', 'Mi etiqueta electrónica no conecta con la antena de la tienda.', 'Reinicie la etiqueta electrónica acercándola a la antena de la tienda.');
        (new AiKnowledge())->indexarTickets();
        $nuevo = $this->ticket('abierto', 'Etiqueta electrónica sin conexión', 'Mi etiqueta electrónica tampoco conecta con la antena.', null);

        $fake = new class implements AiProvider {
            public string $sistema = '';
            public function configured(): bool { return true; }
            public function complete(string $system, array $messages): array { $this->sistema = $system; return ['text' => 'Borrador', 'model' => 'f', 'input_tokens' => 1, 'output_tokens' => 1]; }
        };
        $r = (new AiSuggestionService($fake))->redactar($nuevo, $fake);

        $this->assertStringContainsString('Reinicie la etiqueta electrónica', $fake->sistema);
        $this->assertCount(1, json_decode($r['sources'], true)['docs']);
    }
}
