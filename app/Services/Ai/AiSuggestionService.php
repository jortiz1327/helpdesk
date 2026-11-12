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

    public function __construct(protected AiProvider $ai)
    {
    }

    /** Interruptor del panel (ajuste) Y clave puesta. Sin las dos, no sale ningún dato. */
    public function enabled(): bool
    {
        return Setting::get('ai_suggestions_enabled', '0') === '1' && $this->ai->configured();
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
        $t = DB::table('tickets as t')->leftJoin('contacts as c', 'c.id', '=', 't.contact_id')
            ->leftJoin('ticket_categories as cat', 'cat.id', '=', 't.category_id')
            ->where('t.id', $ticketId)
            ->first(['t.id', 't.subject', 't.category_id', 't.channel', 'c.name as contact_name', 'cat.name as category_name']);

        $conversacion = $this->conversacion($ticketId);
        $pregunta = $this->ultimaDelCliente($conversacion);
        $faqs = $this->faqsParaTexto(($t->subject ?? '') . ' ' . $pregunta);
        $ejemplos = $this->ejemplos($t->category_id ? (int) $t->category_id : null);

        $fila = [
            'ticket_id' => $ticketId, 'trigger_message_id' => $messageId, 'mode' => 'suggest',
            'sources' => json_encode(['faqs' => array_column($faqs, 'id'), 'examples' => array_column($ejemplos, 'id')]),
            'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
        ];

        try {
            $r = $this->ai->complete(
                $this->instrucciones($t, $faqs, $ejemplos),
                $this->comoTurnos($conversacion),
            );
            $texto = trim($r['text']);
            $sabe = $texto !== '' && ! str_contains($texto, self::NO_SABE);
            $fila += [
                'status' => $sabe ? 'ready' : 'abstained', 'body' => $sabe ? $texto : null,
                'model' => $r['model'], 'input_tokens' => $r['input_tokens'], 'output_tokens' => $r['output_tokens'],
            ];
        } catch (Throwable $e) {
            report($e);
            $fila += ['status' => 'failed', 'error' => mb_substr($e instanceof AiException ? $e->getMessage() : 'Error inesperado', 0, 250)];
        }

        $id = DB::table('ai_suggestions')->insertGetId($fila);
        return DB::table('ai_suggestions')->where('id', $id)->first();
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

    private function instrucciones(object $t, array $faqs, array $ejemplos): string
    {
        $s = "Eres el asistente del equipo de soporte de AEME. Redactas BORRADORES de respuesta para que una persona del equipo los revise antes de enviarlos; no hablas directamente con el cliente.\n\n"
           . "REGLAS\n"
           . "- Escribe en español, trato cordial y profesional, breve y concreto. Texto plano, sin Markdown ni HTML.\n"
           . "- Responde SOLO con lo que se pueda sostener con la base de conocimiento de abajo o con lo que el cliente ya ha dicho. No inventes pasos, enlaces, nombres de producto ni datos.\n"
           . "- No prometas plazos, precios, descuentos, devoluciones ni compensaciones. Si el cliente los pide, di que el equipo lo revisará.\n"
           . "- Si te falta información para ayudar, pídela con una pregunta concreta.\n"
           . "- Si no tienes base suficiente para contestar de forma fiable, responde únicamente con " . self::NO_SABE . "\n"
           . "- Saluda por el nombre si lo conoces y no incluyas firma: la añade el sistema.\n\n";

        $s .= 'TICKET: ' . ($t->subject ?? '') . ($t->category_name ? ' (categoría: ' . $t->category_name . ')' : '') . ' · canal ' . ($t->channel ?? '') . "\n";
        if (! empty($t->contact_name)) {
            $s .= 'CLIENTE: ' . $t->contact_name . "\n";
        }

        $s .= "\nBASE DE CONOCIMIENTO (preguntas frecuentes publicadas)\n";
        $s .= $faqs ? implode("\n\n", array_map(fn ($f) => '• ' . $f['question'] . "\n" . $f['answer'], $faqs)) : '(ninguna coincide con esta consulta)';

        if ($ejemplos) {
            $s .= "\n\nERRORES ANTERIORES (el equipo corrigió estas respuestas; aprende de ellos)\n";
            foreach ($ejemplos as $e) {
                $s .= "- Pregunta del cliente: " . mb_substr($e['pregunta'], 0, 400) . "\n  Respuesta incorrecta: " . mb_substr($e['mala'], 0, 400) . "\n  Lo correcto: " . mb_substr($e['correccion'], 0, 600) . "\n";
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
