<?php

namespace App\Services\Ai;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Sugerencias de respuesta para un ticket (horario humano: la IA NO contesta, propone).
 *
 * «Entrenarla» no es reentrenar un modelo: cada sugerencia se escribe con
 *   1. las instrucciones de la casa (este archivo),
 *   2. las FAQs publicadas que mejor encajan con lo que pregunta el cliente,
 *   3. las correcciones que los agentes escribieron cuando una sugerencia salió mal.
 * Así, cada corrección guardada mejora las siguientes respuestas, con cualquier proveedor.
 */
class AiSuggestionService
{
    /** Marca que devuelve el modelo cuando no tiene base para contestar. */
    public const NO_SABE = '[[NO_SABE]]';

    private const MAX_FAQS = 4;
    private const MAX_EJEMPLOS = 4;
    private const MAX_MENSAJES = 10;

    private bool $anonimizar = false;
    private string $nombreCliente = '';

    public function __construct(protected AiProvider $ai, protected ?AiKnowledge $knowledge = null)
    {
        $this->knowledge ??= new AiKnowledge();
    }

    /** Interruptor del panel (ajuste) Y clave puesta. Sin las dos, no sale ningún dato. */
    public function enabled(): bool
    {
        return Setting::get('ai_suggestions_enabled', '0') === '1' && $this->ai->configured();
    }

    /** ¿Hay clave del proveedor activo? (no dice cuál es) */
    public function configurado(): bool
    {
        return $this->ai->configured();
    }

    /**
     * Datos del panel de Ajustes: interruptor, proveedor, acierto medido con las valoraciones
     * de los agentes, conocimiento indexado y las últimas correcciones. Nunca lleva la clave.
     */
    public function panel(): array
    {
        $prov = (string) config('ai.provider');
        $s = DB::table('ai_suggestions');
        $cuenta = fn (string $estado) => (clone $s)->where('status', $estado)->count();
        $arriba = (clone $s)->where('rating', 'up')->count();
        $abajo = (clone $s)->where('rating', 'down')->count();

        return [
            'enabled'    => Setting::get('ai_suggestions_enabled', '0') === '1',
            'configured' => $this->ai->configured(),
            'provider'   => $prov,
            'model'      => (string) config("ai.providers.$prov.model"),
            'stats'      => [
                'total' => (clone $s)->count(), 'ready' => $cuenta('ready'), 'abstained' => $cuenta('abstained'), 'failed' => $cuenta('failed'),
                'up' => $arriba, 'down' => $abajo, 'used' => (clone $s)->whereNotNull('used_at')->count(),
                // Acierto = útiles / valoradas. Solo tiene sentido con algunas valoraciones.
                'accuracy' => ($arriba + $abajo) > 0 ? round($arriba / ($arriba + $abajo) * 100) : null,
            ],
            'knowledge'  => [
                'tickets' => DB::table('ai_documents')->where('source_type', 'ticket')->count(),
                'manuals' => DB::table('ai_documents')->where('source_type', 'manual')->count(),
                'last'    => DB::table('ai_documents')->max('indexed_at'),
            ],
            // Donde la IA se rinde o falla: consultas sin base suficiente y errores técnicos.
            'gaps' => DB::table('ai_suggestions as a')->leftJoin('tickets as t', 't.id', '=', 'a.ticket_id')
                ->whereIn('a.status', ['abstained', 'failed'])->orderByDesc('a.id')->limit(10)
                ->get(['a.id', 'a.status', 'a.error', 't.code as ticket_code', 't.subject', 'a.created_at'])->all(),
            'corrections' => DB::table('ai_suggestions as a')->leftJoin('tickets as t', 't.id', '=', 'a.ticket_id')
                ->where('a.rating', 'down')->whereNotNull('a.correction')->orderByDesc('a.rated_at')->limit(8)
                ->get(['a.id', 't.code as ticket_code', 'a.correction', 'a.rated_at'])->all(),
        ];
    }

    /**
     * La sugerencia vigente para el último mensaje del cliente; si no hay, se genera.
     * Si el cliente aún no ha escrito nada que contestar, devuelve null.
     */
    public function forTicket(int $ticketId, ?int $userId = null, bool $regenerate = false): ?object
    {
        $ult = DB::table('messages')->where('ticket_id', $ticketId)->where('direction', 'in')
            ->where('is_internal_note', 0)->orderByDesc('id')->first(['id', 'body']);
        if (! $ult) {
            return null;
        }

        if (! $regenerate) {
            $ya = DB::table('ai_suggestions')->where('ticket_id', $ticketId)->where('trigger_message_id', $ult->id)
                ->where('status', '!=', 'failed')->orderByDesc('id')->first();
            if ($ya) {
                return $ya;
            }
        }

        return $this->generate($ticketId, (int) $ult->id, $userId);
    }

    private function generate(int $ticketId, int $messageId, ?int $userId): object
    {
        $fila = $this->redactar($ticketId, $this->ai) + [
            'ticket_id' => $ticketId, 'trigger_message_id' => $messageId, 'mode' => 'suggest',
            'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
        ];
        $id = DB::table('ai_suggestions')->insertGetId($fila);
        return DB::table('ai_suggestions')->where('id', $id)->first();
    }

    /**
     * Pide el borrador a un proveedor SIN guardar nada (la fila la compone quien llama).
     * Lo usa también la comparación entre proveedores. Con $anonimizar se tapan correos,
     * teléfonos y el nombre del cliente antes de que el texto salga hacia fuera.
     * Nunca lanza: un fallo vuelve como status «failed».
     *
     * @return array{status:string, body:?string, sources:string, model?:?string, input_tokens?:?int, output_tokens?:?int, error?:string}
     */
    public function redactar(int $ticketId, AiProvider $ai, bool $anonimizar = false): array
    {
        $this->anonimizar = $anonimizar;
        try {
            $t = DB::table('tickets as t')->leftJoin('contacts as c', 'c.id', '=', 't.contact_id')
                ->leftJoin('ticket_categories as cat', 'cat.id', '=', 't.category_id')
                ->where('t.id', $ticketId)
                ->first(['t.id', 't.subject', 't.category_id', 't.channel', 'c.name as contact_name', 'cat.name as category_name']);
            $this->nombreCliente = $anonimizar ? trim((string) ($t->contact_name ?? '')) : '';
            if ($anonimizar) {
                $t->subject = $this->plano((string) $t->subject);
                $t->contact_name = null;
            }

            $conversacion = $this->conversacion($ticketId);
            $pregunta = $this->ultimaDelCliente($conversacion);
            $faqs = $this->faqsParaTexto(($t->subject ?? '') . ' ' . $pregunta);
            $ejemplos = $this->ejemplos($t->category_id ? (int) $t->category_id : null);
            $casos = $this->knowledge->buscar(($t->subject ?? '') . ' ' . $pregunta, $ticketId);

            $fila = ['sources' => json_encode(['faqs' => array_column($faqs, 'id'), 'examples' => array_column($ejemplos, 'id'), 'docs' => array_map(fn ($d) => (int) $d->id, $casos)])];
            try {
                $r = $ai->complete($this->instrucciones($t, $faqs, $ejemplos, $casos), $this->comoTurnos($conversacion));
                if (! empty($r['truncated'])) {
                    // Un texto cortado a medias no se puede enseñar como si estuviera completo.
                    throw new AiException('La respuesta de la IA salió incompleta');
                }
                $texto = trim($r['text']);
                $sabe = $texto !== '' && ! str_contains($texto, self::NO_SABE);
                return $fila + [
                    'status' => $sabe ? 'ready' : 'abstained', 'body' => $sabe ? $texto : null,
                    'model' => $r['model'], 'input_tokens' => $r['input_tokens'], 'output_tokens' => $r['output_tokens'],
                ];
            } catch (Throwable $e) {
                report($e);
                return $fila + ['status' => 'failed', 'body' => null, 'error' => mb_substr($e instanceof AiException ? $e->getMessage() : 'Error inesperado', 0, 250)];
            }
        } finally {
            $this->anonimizar = false;
            $this->nombreCliente = '';
        }
    }

    /**
     * Valora una sugerencia. «down» exige la corrección: sin ella no hay nada de lo que
     * aprender, y es justo lo que se guarda para alimentar a la IA.
     */
    public function rate(int $id, string $rating, ?string $correction, int $userId): object
    {
        if (! in_array($rating, ['up', 'down'], true)) {
            throw new InvalidArgumentException('Valoración no válida');
        }
        $correction = trim((string) $correction);
        if ($rating === 'down' && mb_strlen($correction) < 5) {
            throw new InvalidArgumentException('Escribe qué debía responder (mínimo 5 letras) para poder aprender de ello');
        }

        DB::table('ai_suggestions')->where('id', $id)->update([
            'rating' => $rating, 'correction' => $rating === 'down' ? mb_substr($correction, 0, 4000) : null,
            'rated_by' => $userId, 'rated_at' => now(), 'updated_at' => now(),
        ]);
        // La corrección pasa a ser conocimiento buscable (y deja de serlo si cambia a 👍).
        $this->knowledge->indexarCorreccion($id);
        return DB::table('ai_suggestions')->where('id', $id)->first();
    }

    public function markUsed(int $id): void
    {
        DB::table('ai_suggestions')->where('id', $id)->whereNull('used_at')->update(['used_at' => now(), 'updated_at' => now()]);
    }

    // -- Conocimiento -------------------------------------------------------------

    /** Las últimas mensajes visibles al cliente (sin notas internas), de antiguo a nuevo. */
    private function conversacion(int $ticketId): array
    {
        $rows = DB::table('messages')->where('ticket_id', $ticketId)->where('is_internal_note', 0)
            ->whereNotNull('body')->orderByDesc('id')->limit(self::MAX_MENSAJES)->get(['direction', 'body']);

        return $rows->reverse()->map(fn ($m) => [
            'role' => $m->direction === 'in' ? 'user' : 'assistant',
            'text' => $this->plano((string) $m->body),
        ])->filter(fn ($m) => $m['text'] !== '')->values()->all();
    }

    private function ultimaDelCliente(array $conv): string
    {
        foreach (array_reverse($conv) as $m) {
            if ($m['role'] === 'user') {
                return $m['text'];
            }
        }
        return '';
    }

    /**
     * La API exige que la conversación empiece por el usuario y alterne roles: se juntan
     * mensajes seguidos del mismo lado y se descarta lo que precede al primer mensaje del cliente.
     */
    private function comoTurnos(array $conv): array
    {
        $out = [];
        foreach ($conv as $m) {
            if (! $out && $m['role'] !== 'user') {
                continue;
            }
            if ($out && $out[count($out) - 1]['role'] === $m['role']) {
                $out[count($out) - 1]['content'] .= "\n\n" . $m['text'];
            } else {
                $out[] = ['role' => $m['role'], 'content' => $m['text']];
            }
        }
        // Debe terminar en un mensaje del cliente (es a lo que se contesta).
        while ($out && $out[count($out) - 1]['role'] !== 'user') {
            array_pop($out);
        }
        return $out;
    }

    /** FAQs publicadas que más palabras comparten con el texto. Sin coincidencia, ninguna. */
    public function faqsParaTexto(string $texto): array
    {
        $palabras = $this->palabras($texto);
        if (! $palabras) {
            return [];
        }

        $puntuadas = [];
        foreach (DB::table('faqs')->where('active', 1)->get(['id', 'question', 'answer', 'keywords']) as $f) {
            $heno = $this->palabras($f->question . ' ' . $f->keywords);
            $heno2 = $this->palabras($f->answer);
            $p = count(array_intersect($palabras, $heno)) * 3 + count(array_intersect($palabras, $heno2));
            if ($p >= 3) {
                $puntuadas[] = ['p' => $p, 'id' => (int) $f->id, 'question' => $f->question, 'answer' => $this->plano((string) $f->answer)];
            }
        }
        usort($puntuadas, fn ($a, $b) => $b['p'] <=> $a['p']);
        return array_slice($puntuadas, 0, self::MAX_FAQS);
    }

    /**
     * Correcciones de los agentes (lo que la IA debía haber dicho). Primero las de la misma
     * categoría de ticket; luego las más recientes.
     */
    private function ejemplos(?int $categoryId): array
    {
        $rows = DB::table('ai_suggestions as s')->join('tickets as t', 't.id', '=', 's.ticket_id')
            ->where('s.rating', 'down')->whereNotNull('s.correction')
            ->orderByRaw('(t.category_id <=> ?) DESC', [$categoryId])->orderByDesc('s.rated_at')
            ->limit(self::MAX_EJEMPLOS)->get(['s.id', 's.body', 's.correction', 's.trigger_message_id']);

        return $rows->map(function ($s) {
            $msg = DB::table('messages')->where('id', $s->trigger_message_id)->value('body');
            return ['id' => (int) $s->id, 'pregunta' => $this->plano((string) $msg), 'mala' => (string) $s->body, 'correccion' => (string) $s->correction];
        })->all();
    }

    private function instrucciones(object $t, array $faqs, array $ejemplos, array $casos = []): string
    {
        $ruta = resource_path('ai/prompt-soporte.md');
        $base = is_file($ruta) ? trim((string) file_get_contents($ruta)) : '';
        if ($base === '') {
            $base = "Eres el asistente del equipo de soporte de AEME. Tratas al cliente de usted, en su idioma, con frases cortas y sin inventar nada. Redactas solo borradores para revisión humana.";
        }
        $s = $base . "\n\n"
           . "FORMATO DE SALIDA\n"
           . "- Texto plano, sin Markdown ni HTML. Entrega solo el texto del borrador para el cliente.\n"
           . "- Si el cliente indica su nombre y procede, puedes usarlo en el saludo.\n"
           . "- Solo si no hay nada que responder ni que preguntar con fiabilidad (ni siquiera pedir un dato), responde únicamente con " . self::NO_SABE . "\n"
           . "- Usa solo la información de este ticket y de las secciones de contexto siguientes; el contexto de otros casos son patrones para orientarte, nunca datos para citar.\n\n";

        $s .= 'TICKET: ' . ($t->subject ?? '') . ($t->category_name ? ' (categoría: ' . $t->category_name . ')' : '') . ' · canal ' . ($t->channel ?? '') . "\n";
        if (! empty($t->contact_name)) {
            $s .= 'CLIENTE: ' . $t->contact_name . "\n";
        }

        $s .= "\nBASE DE CONOCIMIENTO (preguntas frecuentes publicadas)\n";
        $s .= $faqs ? implode("\n\n", array_map(fn ($f) => '• ' . $f['question'] . "\n" . $f['answer'], $faqs)) : '(ninguna coincide con esta consulta)';

        // Las correcciones parecidas (indexadas) van aparte y primero; las que ya salen en «errores anteriores» no se repiten.
        $correcciones = array_values(array_filter($casos, fn ($d) => $d->source_type === 'correccion' && ! in_array((int) $d->source_id, array_column($ejemplos, 'id'), true)));
        $casos = array_values(array_filter($casos, fn ($d) => $d->source_type !== 'correccion'));
        if ($correcciones) {
            $s .= "\n\nCORRECCIONES DEL EQUIPO A CONSULTAS PARECIDAS (son indicaciones del equipo sobre cómo responder; aplícalas como criterio y redacta con tus palabras, adaptado a este cliente)\n";
            foreach ($correcciones as $d) {
                $s .= mb_substr((string) $d->content, 0, 1800) . "\n\n";
            }
        }

        if ($casos) {
            $s .= "\n\nCASOS ANTERIORES Y MANUALES (úsalos solo si encajan con esta consulta: de ahí sacas el criterio y el tono del equipo; no copies datos de otros clientes ni mezcles casos distintos)\n";
            foreach ($casos as $d) {
                $tipo = str_contains((string) $d->source_id, '#') ? 'Manual' : 'Caso resuelto';
                $s .= '[' . $tipo . ': ' . $d->title . "]\n" . mb_substr((string) $d->content, 0, 1400) . "\n\n";
            }
        }

        if ($ejemplos) {
            $s .= "\n\nERRORES ANTERIORES (el equipo corrigió estas respuestas; aprende de ellos)\n";
            foreach ($ejemplos as $e) {
                $s .= "- Pregunta del cliente: " . mb_substr($e['pregunta'], 0, 400) . "\n  Respuesta incorrecta: " . mb_substr($e['mala'], 0, 400) . "\n  Indicación del equipo (criterio a aplicar, no texto a copiar): " . mb_substr($e['correccion'], 0, 600) . "\n";
            }
        }
        return $s;
    }

    // -- Utilidades de texto ------------------------------------------------------

    /** HTML de un correo → texto plano acotado (para el prompt). */
    private function plano(string $html): string
    {
        $h = preg_replace('#<(br|/p|/div|/li)\s*/?>#i', "\n", $html);
        $h = html_entity_decode(strip_tags((string) $h), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($this->anonimizar) {
            $h = Scrub::tapar((string) $h, $this->nombreCliente);
        }
        $h = preg_replace("/[ \t]+/", ' ', (string) $h);
        $h = trim((string) preg_replace("/\n{3,}/", "\n\n", (string) $h));
        return mb_substr($h, 0, 2000);
    }

    /** Palabras significativas (≥4 letras, sin tildes, sin las más comunes) para comparar. */
    private function palabras(string $texto): array
    {
        $t = mb_strtolower(preg_replace('/\p{Mn}/u', '', \Normalizer::normalize($texto, \Normalizer::FORM_D) ?: $texto));
        preg_match_all('/[a-z0-9ñ]{4,}/u', $t, $m);
        $vacias = ['para', 'como', 'esta', 'este', 'esto', 'pero', 'porque', 'cuando', 'donde', 'tengo', 'puedo', 'hola', 'buenos', 'buenas', 'gracias', 'saludos', 'favor', 'quiero', 'necesito', 'tiene', 'tienen', 'desde', 'sobre', 'entre', 'unos', 'unas'];
        return array_values(array_unique(array_diff($m[0], $vacias)));
    }
}
