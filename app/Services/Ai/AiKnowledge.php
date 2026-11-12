<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\DB;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Base de conocimiento de la IA (tabla ai_documents).
 *
 * Qué entra:
 *  · tickets RESUELTOS o CERRADOS donde un agente contestó al cliente: pregunta del cliente
 *    + respuesta del equipo, con los datos personales tapados. Se descartan los fusionados y
 *    los que el cliente valoró con 1-2 estrellas (respuestas que no gustaron).
 *  · manuales que se declaran en config('ai.manuals') (por defecto, la guía del cliente).
 *
 * Qué NO entra, nunca: las NOTAS INTERNAS (is_internal_note = 1) ni los mensajes
 * automáticos del sistema (los que no tienen autor humano).
 *
 * La búsqueda es FULLTEXT de MySQL: sin servicios extra y suficiente al principio.
 */
class AiKnowledge
{
    private const TROZO = 1200;   // caracteres por trozo de manual

    /** Indexa tickets. Devuelve [nuevos, saltados]. */
    public function indexarTickets(bool $rehacer = false, ?int $limite = null): array
    {
        $ya = $rehacer ? [] : DB::table('ai_documents')->where('source_type', 'ticket')->pluck('source_id')->flip()->all();
        $q = DB::table('tickets as t')->leftJoin('contacts as c', 'c.id', '=', 't.contact_id')
            ->leftJoin('ticket_ratings as r', 'r.ticket_id', '=', 't.id')
            ->whereIn('t.status', ['resuelto', 'cerrado'])->whereNull('t.merged_into_id')
            ->where(fn ($w) => $w->whereNull('r.score')->orWhere('r.score', '>=', 3))
            ->orderBy('t.id');

        $nuevos = $saltados = 0;
        foreach ($q->get(['t.id', 't.subject', 'c.name as contact_name', 'r.score']) as $t) {
            if ($limite !== null && $nuevos >= $limite) {
                break;
            }
            if (isset($ya[(string) $t->id])) {
                $saltados++;
                continue;
            }
            $doc = $this->documentoDeTicket($t);
            if (! $doc) {
                $saltados++;
                continue;
            }
            DB::table('ai_documents')->updateOrInsert(
                ['source_type' => 'ticket', 'source_id' => (string) $t->id],
                $doc + ['indexed_at' => now()],
            );
            $nuevos++;
        }
        return [$nuevos, $saltados];
    }

    /** @return array{title:string, content:string, quality:?int}|null */
    private function documentoDeTicket(object $t): ?array
    {
        $msgs = DB::table('messages')->where('ticket_id', $t->id)->where('is_internal_note', 0)->whereNotNull('body')
            ->orderBy('id')->get(['direction', 'body', 'author_user_id']);

        $pregunta = $msgs->firstWhere('direction', 'in');
        $respuestas = $msgs->filter(fn ($m) => $m->direction === 'out' && $m->author_user_id)   // solo personas, no avisos automáticos
            ->map(fn ($m) => Scrub::plano((string) $m->body))->filter()->values();
        if (! $pregunta || $respuestas->isEmpty()) {
            return null;
        }

        $nombre = (string) ($t->contact_name ?? '');
        $q = trim(Scrub::tapar(Scrub::plano((string) $pregunta->body), $nombre));
        $r = trim(Scrub::tapar($respuestas->implode("\n\n"), $nombre));
        if (mb_strlen($q) < 15 || mb_strlen($r) < 40) {   // respuestas de una línea ("le llamo") no enseñan nada
            return null;
        }

        return [
            'title'   => mb_substr(Scrub::tapar((string) $t->subject, $nombre), 0, 255),
            'content' => "Pregunta del cliente:\n" . mb_substr($q, 0, 1500) . "\n\nRespuesta del equipo:\n" . mb_substr($r, 0, 2500),
            'quality' => $t->score !== null ? (int) $t->score : null,
        ];
    }

    /**
     * Guarda una corrección de un agente como conocimiento buscable: así, ante una pregunta
     * PARECIDA, la IA ve lo que el equipo dijo que era lo correcto (no solo las últimas
     * correcciones, que no tienen por qué venir al caso). Datos personales tapados.
     */
    public function indexarCorreccion(int $suggestionId): void
    {
        $s = DB::table('ai_suggestions as a')->join('tickets as t', 't.id', '=', 'a.ticket_id')
            ->leftJoin('contacts as c', 'c.id', '=', 't.contact_id')
            ->where('a.id', $suggestionId)
            ->first(['a.id', 'a.body', 'a.correction', 'a.rating', 'a.trigger_message_id', 't.subject', 'c.name as contact_name']);
        if (! $s || $s->rating !== 'down' || trim((string) $s->correction) === '') {
            $this->quitarCorreccion($suggestionId);
            return;
        }

        $nombre = (string) ($s->contact_name ?? '');
        $pregunta = Scrub::tapar(Scrub::plano((string) DB::table('messages')->where('id', $s->trigger_message_id)->value('body')), $nombre);
        $content = "Pregunta del cliente:\n" . mb_substr($pregunta, 0, 1200)
            . "\n\nLo que la IA respondió y estaba mal:\n" . mb_substr(Scrub::tapar((string) $s->body, $nombre), 0, 800)
            . "\n\nIndicación del equipo (qué estaba mal y cómo hay que responder; es un criterio a aplicar, no un texto para copiar):\n" . mb_substr(Scrub::tapar((string) $s->correction, $nombre), 0, 2000);

        DB::table('ai_documents')->updateOrInsert(
            ['source_type' => 'correccion', 'source_id' => (string) $suggestionId],
            ['title' => mb_substr(Scrub::tapar((string) $s->subject, $nombre), 0, 255), 'content' => $content, 'quality' => null, 'indexed_at' => now()],
        );
    }

    /** Igual que indexarCorreccion, pero para una respuesta de un chat de prueba (source_id «chat:N»). */
    public function indexarCorreccionChat(int $msgId): void
    {
        $m = DB::table('ai_lab_messages as m')->join('ai_lab_chats as c', 'c.id', '=', 'm.chat_id')->where('m.id', $msgId)
            ->first(['m.id', 'm.chat_id', 'm.body', 'm.rating', 'm.correction', 'c.title', 'c.contact_name']);
        if (! $m || $m->rating !== 'down' || trim((string) $m->correction) === '') {
            $this->quitarCorreccionChat($msgId);
            return;
        }
        $nombre = (string) ($m->contact_name ?? '');
        $q = (string) DB::table('ai_lab_messages')->where('chat_id', $m->chat_id)->where('role', 'user')->where('id', '<', $msgId)->orderByDesc('id')->value('body');
        $content = "Pregunta del cliente:\n" . mb_substr(Scrub::tapar($q, $nombre), 0, 1200)
            . "\n\nLo que la IA respondió y estaba mal:\n" . mb_substr(Scrub::tapar((string) $m->body, $nombre), 0, 800)
            . "\n\nIndicación del equipo (qué estaba mal y cómo hay que responder; es un criterio a aplicar, no un texto para copiar):\n" . mb_substr(Scrub::tapar((string) $m->correction, $nombre), 0, 2000);
        DB::table('ai_documents')->updateOrInsert(
            ['source_type' => 'correccion', 'source_id' => 'chat:' . $msgId],
            ['title' => mb_substr(Scrub::tapar((string) $m->title, $nombre), 0, 255), 'content' => $content, 'quality' => null, 'indexed_at' => now()],
        );
    }

    public function quitarCorreccionChat(int $msgId): void
    {
        DB::table('ai_documents')->where('source_type', 'correccion')->where('source_id', 'chat:' . $msgId)->delete();
    }

    public function quitarCorreccion(int $suggestionId): void
    {
        DB::table('ai_documents')->where('source_type', 'correccion')->where('source_id', (string) $suggestionId)->delete();
    }

    /** Pasa a la base de conocimiento todas las correcciones guardadas (idempotente). */
    public function indexarCorrecciones(): int
    {
        $ids = DB::table('ai_suggestions')->where('rating', 'down')->whereNotNull('correction')->pluck('id');
        foreach ($ids as $id) {
            $this->indexarCorreccion((int) $id);
        }
        return $ids->count();
    }

    /** Indexa los manuales declarados. Devuelve el número de trozos escritos. */
    public function indexarManuales(): int
    {
        $n = 0;
        foreach ((array) config('ai.manuals', []) as $clave) {
            $m = config('manuals.catalog.' . $clave);
            $ruta = $m ? resource_path('manuals/' . $m['file']) : null;
            if (! $ruta || ! is_file($ruta)) {
                continue;
            }
            try {
                $texto = (new Parser())->parseFile($ruta)->getText();
            } catch (Throwable $e) {
                report($e);
                continue;
            }
            DB::table('ai_documents')->where('source_type', 'manual')->where('source_id', 'like', $clave . '#%')->delete();
            foreach ($this->trocear($texto) as $i => $trozo) {
                DB::table('ai_documents')->insert([
                    'source_type' => 'manual', 'source_id' => $clave . '#' . ($i + 1), 'title' => mb_substr($m['title'], 0, 255),
                    'content' => $trozo, 'quality' => null, 'indexed_at' => now(),
                ]);
                $n++;
            }
        }
        return $n + $this->indexarDocumentacion();
    }

    /**
     * Documentación propia: los .md / .txt de resources/ai-docs (p. ej. el informe de cada
     * apartado de la aplicación de los hoteles). Cada archivo se reindexa entero al cambiar.
     */
    public function indexarDocumentacion(): int
    {
        $dir = resource_path('ai-docs');
        if (! is_dir($dir)) {
            return 0;
        }
        $n = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (! $f->isFile() || ! in_array(strtolower($f->getExtension()), ['md', 'txt'], true)) {
                continue;
            }
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1));
            $texto = (string) file_get_contents($f->getPathname());
            $titulo = preg_match('/^#\s+(.+)$/m', $texto, $m) ? trim($m[1]) : pathinfo($rel, PATHINFO_FILENAME);
            DB::table('ai_documents')->where('source_type', 'manual')->where('source_id', 'like', 'doc:' . $rel . '#%')->delete();
            foreach ($this->trocear($texto) as $i => $trozo) {
                DB::table('ai_documents')->insert([
                    'source_type' => 'manual', 'source_id' => 'doc:' . $rel . '#' . ($i + 1), 'title' => mb_substr($titulo, 0, 255),
                    'content' => $trozo, 'quality' => null, 'indexed_at' => now(),
                ]);
                $n++;
            }
        }
        return $n;
    }

    private const PARADAS = 'a al algo algun alguna ante aqui asi aun aunque bien buen buena buenas buenos cada como con contra cual cuando de del desde donde dos el ella ellos en entre era eran eres es esa ese eso esta estaba estan este esto estos fue ha han hasta hay hemos hola la las le les lo los mas me mi mis mucho muy nos nosotros nuestro o os otra otro para pero por porque que quien se sea ser si sin sobre su sus te tiene tienen todo todos tu tus un una uno unos usted va ya yo buenas dias tardes noches saludos gracias favor tambien necesito necesitamos necesitaria queria queremos podrian podria pueden puedo puede hotel hoteles etiqueta etiquetas';

    /** Consulta BOOLEAN con raíces de palabra: las del asunto pesan más. '' si no queda ninguna palabra útil. */
    private function consultaManual(string $asunto, string $texto): string
    {
        $paradas = array_flip(explode(' ', self::PARADAS));
        $terminos = [];
        $anadir = function (string $t, bool $fuerte) use (&$terminos, $paradas) {
            foreach (preg_split('/\s+/u', mb_strtolower(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $t))) ?: [] as $p) {
                $p = strtr($p, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
                if (mb_strlen($p) < 4 || isset($paradas[$p]) || ctype_digit($p) || count($terminos) >= 40) {
                    continue;
                }
                $l = mb_strlen($p);
                $raiz = mb_substr($p, 0, $l <= 5 ? $l - 1 : ($l <= 7 ? $l - 2 : $l - 3));
                if (mb_strlen($raiz) >= 4 && (! isset($terminos[$raiz]) || $fuerte)) {
                    $terminos[$raiz] = $fuerte;
                }
            }
        };
        $anadir($asunto, true);
        $anadir(mb_substr($texto, 0, 500), false);
        return implode(' ', array_map(fn ($r, $fuerte) => ($fuerte ? '>' : '') . $r . '*', array_keys($terminos), $terminos));
    }

    /** Párrafos agrupados en trozos de ~TROZO caracteres. */
    private function trocear(string $texto): array
    {
        $parrafos = preg_split('/\n\s*\n|\r\n\s*\r\n/', trim(preg_replace("/[ \t]+/", ' ', $texto))) ?: [];
        $out = [];
        $actual = '';
        foreach ($parrafos as $p) {
            $p = trim((string) $p);
            if ($p === '') {
                continue;
            }
            if ($actual !== '' && mb_strlen($actual) + mb_strlen($p) > self::TROZO) {
                $out[] = $actual;
                $actual = '';
            }
            $actual .= ($actual === '' ? '' : "\n\n") . $p;
        }
        if (mb_strlen($actual) >= 40) {
            $out[] = $actual;
        }
        return $out;
    }

    /**
     * Lo más parecido a $texto: hasta $nTickets casos resueltos y $nManuales trozos de manual.
     * Se excluye $ticketId (si se está contestando un ticket ya resuelto, que no se copie a sí mismo).
     * El documento viene sin datos personales. Solo cuentan los que puntúan al menos el 40 % del mejor
     * (y más que config('ai.knowledge_min_score'), 0 por defecto).
     */
    public function buscar(string $texto, ?int $ticketId = null, int $nTickets = 3, int $nManuales = 4, int $nCorrecciones = 2, string $asunto = ''): array
    {
        $q = trim(mb_substr(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $texto), 0, 600));
        if (mb_strlen($q) < 6) {
            return [];
        }
        $min = (float) config('ai.knowledge_min_score', 1.0);

        $buscar = function (string $tipo, int $n) use ($q, $ticketId, $min, $texto, $asunto) {
            // Los manuales se buscan por raíces de palabra (usuario ≈ usuarios, eliminar ≈ eliminación) y sin el ruido
            // del mensaje (saludos, nombres…): FULLTEXT natural no sabe de plurales. Lo del asunto pesa más.
            [$modo, $consulta] = $tipo === 'manual' && ($b = $this->consultaManual($asunto, $texto)) !== '' ? ['BOOLEAN', $b] : ['NATURAL LANGUAGE', $q];
            $f = DB::table('ai_documents')->where('source_type', $tipo)
                ->selectRaw("id, source_type, source_id, title, content, quality, MATCH(title, content) AGAINST(? IN $modo MODE) AS score", [$consulta])
                ->whereRaw("MATCH(title, content) AGAINST(? IN $modo MODE)", [$consulta]);
            if ($tipo === 'ticket' && $ticketId) {
                $f->where('source_id', '!=', (string) $ticketId);
            }
            $r = $f->orderByDesc('score')->orderByDesc('quality')->limit($n * 3)->get();
            // Los puntos de FULLTEXT dependen del tamaño del corpus: se compara con el MEJOR resultado.
            $tope = (float) ($r->first()->score ?? 0);
            return $r->filter(fn ($d) => (float) $d->score > 0 && (float) $d->score >= $min && (float) $d->score >= $tope * 0.4)
                ->take($n)->values()->all();
        };

        return array_merge($buscar('correccion', $nCorrecciones), $buscar('ticket', $nTickets), $buscar('manual', $nManuales));
    }
}
