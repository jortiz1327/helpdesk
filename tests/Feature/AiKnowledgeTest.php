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

    public function test_una_correccion_se_indexa_sin_datos_personales_y_se_usa_ante_algo_parecido(): void
    {
        $fake = new class implements AiProvider {
            public string $sistema = '';
            public function configured(): bool { return true; }
            public function complete(string $system, array $messages): array { $this->sistema = $system; return ['text' => 'Borrador', 'model' => 'f', 'input_tokens' => 1, 'output_tokens' => 1]; }
        };
        $svc = new AiSuggestionService($fake);

        $t1 = $this->ticket('abierto', 'Etiqueta electrónica sin conexión', 'Soy Marta Gómez. Mi etiqueta electrónica no conecta con la antena de la tienda.', null);
        $svc->forTicket($t1, $this->uid);
        $id = (int) DB::table('ai_suggestions')->where('ticket_id', $t1)->value('id');
        $svc->rate($id, 'down', 'Debía pedir el código de la etiqueta antes de nada, Marta.', $this->uid);

        // Lo que corrige un agente pasa directo a la memoria: nadie lo revisa antes.
        $this->assertSame('validated', DB::table('ai_suggestions')->where('id', $id)->value('correction_status'));
        $this->assertSame(1, DB::table('ai_documents')->where('source_type', 'correccion')->count());

        $doc = DB::table('ai_documents')->where('source_type', 'correccion')->first();
        $this->assertNotNull($doc);
        $this->assertStringContainsString('pedir el código de la etiqueta', $doc->content);
        $this->assertStringNotContainsString('Marta', $doc->content . $doc->title);

        // Llegan 4 correcciones más recientes sobre otros temas: la nuestra ya no está entre las 4 últimas.
        for ($i = 0; $i < 4; $i++) {
            DB::table('ai_suggestions')->insert(['ticket_id' => $t1, 'trigger_message_id' => null, 'status' => 'ready', 'body' => 'x', 'rating' => 'down',
                'correction_status' => 'validated', 'correction' => 'Otra cosa distinta número ' . $i,'rated_at' => now()->addMinutes(5 + $i), 'created_at' => now(), 'updated_at' => now()]);
        }

        // Otra consulta parecida: por parecido sí la encuentra, y va en su apartado.
        $t2 = $this->ticket('abierto', 'Etiqueta electrónica sin conexión', 'Mi etiqueta electrónica tampoco conecta con la antena de la tienda.', null);
        $svc->forTicket($t2, $this->uid);
        $this->assertStringContainsString('CORRECCIONES DEL EQUIPO', $fake->sistema);

        // Si el agente cambia de opinión (👍), deja de ser conocimiento.
        $svc->rate($id, 'up', null, $this->uid);
        $this->assertSame(0, DB::table('ai_documents')->where('source_type', 'correccion')->count());
    }

    public function test_las_plantillas_del_equipo_se_indexan_filtradas_y_se_ofrecen_a_la_ia_cuando_encajan(): void
    {
        $this->assertTrue(AiKnowledge::plantillaExcluida('estado-ticket', 'Estado ticket', str_repeat('Texto largo. ', 10)));
        $this->assertTrue(AiKnowledge::plantillaExcluida('garantia-envio', 'Garantía: pedir el envío', str_repeat('Texto largo. ', 10)));
        $this->assertTrue(AiKnowledge::plantillaExcluida('menu-info', 'Pedir datos', 'El día XX de XXXXXX se guardó el menú y hay que revisar el dispositivo.'));
        $this->assertTrue(AiKnowledge::plantillaExcluida('corta', 'Corta', 'Hola'));
        $this->assertTrue(AiKnowledge::plantillaExcluida('no-funciona', 'No funciona', 'Si la etiqueta no funciona, envíela a la siguiente dirección: Avenida Santos Patronos, 32, Alzira.'));
        $this->assertFalse(AiKnowledge::plantillaExcluida('exportar-menus', 'Cómo exportar la rueda de menús', 'Puedes descargar tu rueda de menús desde la aplicación pulsando Exportar menús.'));

        DB::table('canned_responses')->whereIn('shortcut', ['t-exportar', 't-garantia', 't-off'])->delete();
        $fila = fn ($s, $t, $b, $a = 1) => DB::table('canned_responses')->insert(['shortcut' => $s, 'title' => $t, 'body' => $b, 'active' => $a]);
        $fila('t-exportar', 'Cómo exportar la rueda de menús', 'Puede descargar su rueda de menús desde la aplicación: entre en Rueda de menús y pulse el botón Exportar menús. Se descargará un Excel.');
        $fila('t-garantia', 'Garantía de etiqueta defectuosa', 'Para revisar la etiqueta por garantía necesitamos tres fotos de la etiqueta defectuosa y su código.');
        $fila('t-off', 'Exportar menús desactivada', 'Plantilla desactivada que explica cómo exportar la rueda de menús en un Excel descargable.', 0);
        $k = new AiKnowledge();
        $this->assertSame(1, $k->indexarPlantillas());                         // solo la activa y válida
        $this->assertSame(1, DB::table('ai_documents')->where('source_type', 'plantilla')->count());

        $fake = new class implements AiProvider {
            public string $sistema = '';
            public function configured(): bool { return true; }
            public function complete(string $system, array $messages): array { $this->sistema = $system; return ['text' => 'Borrador', 'model' => 'f', 'input_tokens' => 1, 'output_tokens' => 1]; }
        };
        $svc = new AiSuggestionService($fake);
        $t = $this->ticket('abierto', 'Exportar la rueda de menús', 'Buenas, ¿cómo puedo exportar la rueda de menús a un Excel?', null);
        $svc->forTicket($t, $this->uid);
        $this->assertStringContainsString('PLANTILLAS DE RESPUESTA DEL EQUIPO', $fake->sistema);
        $this->assertStringContainsString('[Plantilla: Cómo exportar la rueda de menús]', $fake->sistema);
        $this->assertStringContainsString('No cambian el ORDEN DE DECISIÓN', $fake->sistema);
        $this->assertStringNotContainsString('tres fotos', $fake->sistema);       // la de garantía no se ofrece

        // Si se desactiva, deja de ser conocimiento.
        DB::table('canned_responses')->where('shortcut', 't-exportar')->update(['active' => 0]);
        $this->assertSame(0, $k->indexarPlantillas());
        $this->assertSame(0, DB::table('ai_documents')->where('source_type', 'plantilla')->count());
        DB::table('canned_responses')->whereIn('shortcut', ['t-exportar', 't-garantia', 't-off'])->delete();
    }

    public function test_la_guia_cuyo_titulo_nombra_el_asunto_llega_aunque_otras_repitan_mas_la_palabra(): void
    {
        $k = new AiKnowledge();
        $ins = fn ($id, $titulo, $c) => DB::table('ai_documents')->updateOrInsert(['source_type' => 'manual', 'source_id' => $id], ['title' => $titulo, 'content' => $c, 'quality' => null, 'indexed_at' => now()]);
        $ins('doc:test/idiomas-x.md#1', 'Idiomas', 'Activa o desactiva cada idioma con el interruptor de Ajustes.');
        for ($i = 1; $i <= 6; $i++) {
            $ins("doc:test/otra-$i.md#1", 'Traducciones ' . $i, 'El segundo idioma, el segundo idioma y otra vez el segundo idioma de las etiquetas, segundo idioma siempre. Aparece el idioma, idioma, idioma.');
        }
        $r = $k->buscar('no sale el segundo idioma necesito que salga el segundo idioma', null, 0, 3, 0, 'no sale el segundo idioma');
        $ids = array_map(fn ($d) => $d->source_id, $r);
        $this->assertContains('doc:test/idiomas-x.md#1', $ids);
        // «Menú de snacks con ventana de apertura y cierre» es crear un servicio aunque no diga «servicio».
        $ins('doc:test/servicios-x.md#1', 'Servicios', 'Crea un servicio con su horario de apertura y cierre desde Ajustes.');
        $r = $k->buscar('Se solicitaba que se crease el menú de snacks en la plataforma. Debe tener sólo una ventana de tiempo (apertura y cierre)', null, 0, 3, 0, 'Criar separador de snack para labels');
        $this->assertContains('doc:test/servicios-x.md#1', array_map(fn ($d) => $d->source_id, $r));
        DB::table('ai_documents')->where('source_id', 'like', 'doc:test/%')->delete();
    }

    public function test_la_guia_se_encuentra_por_raiz_de_palabra_y_sin_ruido_del_saludo(): void
    {
        foreach ([['Usuarios', 'Crear y eliminar usuarios: entra en Usuarios, pulsa Eliminar y confirma con tu contraseña.'], ['Wellness: Iconos', 'Gestionar las fuentes tipográficas de iconos y los distintivos de las ofertas wellness.']] as $i => [$tit, $c]) {
            DB::table('ai_documents')->insert(['source_type' => 'manual', 'source_id' => "x:$i#1", 'title' => $tit, 'content' => $c, 'indexed_at' => now()]);
        }
        $r = app(AiKnowledge::class)->buscar('Modificar Usuario Buenos días Eva Vallejo ya no trabaja con nosotros, hay que eliminar el Usuario y crear otro', null, 0, 3, 0, 'Modificar Usuario');
        $this->assertNotEmpty($r);
        $this->assertSame('Usuarios', $r[0]->title);   // «usuario» encuentra «usuarios»
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
    public function test_las_correcciones_base_del_repositorio_se_importan_sin_duplicar_y_sin_datos_personales(): void
    {
        $k = new AiKnowledge();
        $this->assertSame(6, $k->importarCorreccionesBase());
        $this->assertSame(6, $k->importarCorreccionesBase());   // idempotente
        $docs = DB::table('ai_documents')->where('source_type', 'correccion')->where('source_id', 'like', 'base:%')->get();
        $this->assertCount(6, $docs);
        foreach ($docs as $d) {
            $this->assertDoesNotMatchRegularExpression('/@|\+?\d{9}|Calle|Avinguda/u', $d->content, 'sin datos personales');
        }
        // Una copia vieja del mismo criterio, indexada desde una sugerencia, se quita para no duplicar.
        $j = json_decode(file_get_contents(resource_path('ai/correcciones-base.json')), true);
        DB::table('ai_documents')->insert(['source_type' => 'correccion', 'source_id' => '47', 'title' => 'x', 'content' => $j[0]['contenido'], 'quality' => null, 'indexed_at' => now()]);
        $k->importarCorreccionesBase();
        $this->assertSame(0, DB::table('ai_documents')->where('source_type', 'correccion')->where('source_id', '47')->count());
    }
}