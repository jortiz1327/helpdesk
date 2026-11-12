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
        return $n;
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
    public function buscar(string $texto, ?int $ticketId = null, int $nTickets = 3, int $nManuales = 2): array
    {
        $q = trim(mb_substr(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $texto), 0, 600));
        if (mb_strlen($q) < 6) {
            return [];
        }
        $min = (float) config('ai.knowledge_min_score', 1.0);

        $buscar = function (string $tipo, int $n) use ($q, $ticketId, $min) {
            $f = DB::table('ai_documents')->where('source_type', $tipo)
                ->selectRaw('id, source_id, title, content, quality, MATCH(title, content) AGAINST(? IN NATURAL LANGUAGE MODE) AS score', [$q])
                ->whereRaw('MATCH(title, content) AGAINST(? IN NATURAL LANGUAGE MODE)', [$q]);
            if ($tipo === 'ticket' && $ticketId) {
                $f->where('source_id', '!=', (string) $ticketId);
            }
            $r = $f->orderByDesc('score')->orderByDesc('quality')->limit($n * 3)->get();
            // Los puntos de FULLTEXT dependen del tamaño del corpus: se compara con el MEJOR resultado.
            $tope = (float) ($r->first()->score ?? 0);
            return $r->filter(fn ($d) => (float) $d->score > 0 && (float) $d->score >= $min && (float) $d->score >= $tope * 0.4)
                ->take($n)->values()->all();
        };

        return array_merge($buscar('ticket', $nTickets), $buscar('manual', $nManuales));
    }
}
