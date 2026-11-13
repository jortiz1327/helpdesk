<?php

namespace App\Console\Commands;

use App\Services\Ai\AiProviders;
use App\Services\Ai\AiSuggestionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Pasa por la IA el conjunto de pruebas (casos reales anonimizados que una persona ha marcado con la decisión
 * correcta: responder / pedir_info / derivar) y mide cuánto coincide. NO guarda sugerencias ni toca tickets, y no
 * consulta datos de ningún hotel; cada caso se lee sin poder «copiarse» a sí mismo del conocimiento.
 *
 * ¡Gasta tokens del proveedor activo! Cada caso es una llamada. Ver con --limite=3 antes de lanzar los 110.
 */
class AiEvaluar extends Command
{
    protected $signature = 'ai:evaluar
        {--limite= : Máximo de casos (para probar con pocos)}
        {--decision= : Solo los casos con esa decisión esperada (responder, pedir_info, derivar)}';

    protected $description = 'Evalúa la IA con el conjunto de pruebas marcado por el equipo (gasta tokens)';

    /** Lo que HIZO la IA, a partir de su texto: derivar, pedir un dato o responder. */
    public static function clasificar(string $status, ?string $body): string
    {
        if ($status !== 'ready' || trim((string) $body) === '') {
            return 'derivar';   // «no sé» o fallo: nada llega al cliente, lo atiende una persona
        }
        $t = mb_strtolower((string) $body);
        if (preg_match('/un compañero|nuestro equipo (revisar|se pondr|le atend)|lo revisar[aá] (un|nuestro)|te atender[aá] personalmente|le atender[aá] personalmente/u', $t)) {
            return 'derivar';
        }
        $preguntas = substr_count($t, '?') + substr_count($t, '¿');
        $instruye = preg_match('/\b(pulsa|entra|accede|haz clic|ve a|configura|reinicia|prueba a|desvincula|vincula|selecciona)\b/u', $t);
        if ($preguntas >= 1 && mb_strlen($t) < 450 && ! $instruye) {
            return 'pedir_info';
        }
        return 'responder';
    }

    /**
     * Lo que hizo la IA, juzgado por el propio modelo (una llamada corta): más fiable que mirar palabras sueltas.
     * Si el juez falla o no contesta algo válido, se cae a la regla por palabras (clasificar).
     *
     * @return array{0:string, 1:int, 2:int} decisión, tokens de entrada, tokens de salida
     */
    public static function juzgar(\App\Services\Ai\AiProvider $ai, string $cliente, string $status, ?string $body): array
    {
        if ($status !== 'ready' || trim((string) $body) === '') {
            return ['derivar', 0, 0];   // «no sé» o fallo: nada llega al cliente
        }
        $sistema = "Eres un revisor de calidad de un servicio de soporte. Se te da el mensaje de un cliente y el borrador que ha escrito un asistente. "
            . "Clasifica lo que HACE el borrador, con UNA sola palabra:\n"
            . "responder = da una respuesta, solución o confirmación al problema del cliente.\n"
            . "pedir_info = su objetivo principal es pedir al cliente datos que faltan (hotel, código, foto, detalle) antes de poder ayudar.\n"
            . "derivar = dice que lo atenderá, revisará o gestionará una persona del equipo, sin resolver ni preguntar nada esencial.\n"
            . "Contesta solo con la palabra, sin nada más.";
        try {
            $r = $ai->complete($sistema, [['role' => 'user', 'content' => "MENSAJE DEL CLIENTE:\n" . mb_substr($cliente, 0, 1500) . "\n\nBORRADOR DEL ASISTENTE:\n" . mb_substr((string) $body, 0, 2500)]]);
            $palabra = mb_strtolower(trim((string) ($r['text'] ?? '')));
            foreach (['pedir_info', 'responder', 'derivar'] as $d) {
                if (str_contains($palabra, $d)) {
                    return [$d, (int) ($r['input_tokens'] ?? 0), (int) ($r['output_tokens'] ?? 0)];
                }
            }
            return [self::clasificar($status, $body), (int) ($r['input_tokens'] ?? 0), (int) ($r['output_tokens'] ?? 0)];
        } catch (\Throwable $e) {
            return [self::clasificar($status, $body), 0, 0];
        }
    }

    public function handle(AiSuggestionService $svc): int
    {
        $dir = storage_path('app/ai-eval');
        if (! is_file("$dir/candidatos.json") || ! is_file("$dir/marcas.json")) {
            $this->error('Faltan storage/app/ai-eval/candidatos.json y marcas.json (conjunto de pruebas).');
            return self::FAILURE;
        }
        $ai = AiProviders::activo();
        if (! $ai->configured()) {
            $this->error('El proveedor de IA activo no tiene clave en el .env.');
            return self::FAILURE;
        }

        $marcas = json_decode((string) file_get_contents("$dir/marcas.json"), true);
        $casos = array_values(array_filter(
            json_decode((string) file_get_contents("$dir/candidatos.json"), true),
            fn ($c) => in_array($marcas[$c['id']]['decision'] ?? 'descartar', ['responder', 'pedir_info', 'derivar'], true)
                && (! $this->option('decision') || $marcas[$c['id']]['decision'] === $this->option('decision'))
        ));
        if ($this->option('limite') !== null) {
            $casos = array_slice($casos, 0, max(1, (int) $this->option('limite')));
        }
        $this->info(count($casos) . ' casos con ' . config('ai.provider') . ' (' . config('ai.providers.' . config('ai.provider') . '.model') . ')');

        $locales = DB::table('tickets')->whereIn('code', array_column($casos, 'code'))->pluck('id', 'code');
        $filas = [];
        $conf = [];
        $in = $out = 0;
        $bar = $this->output->createProgressBar(count($casos));
        foreach ($casos as $c) {
            $esperado = $marcas[$c['id']]['decision'];
            $r = $svc->evaluarCaso((string) $c['asunto'], (string) $c['pregunta'], isset($locales[$c['code']]) ? (int) $locales[$c['code']] : null, $ai);
            [$obtenido, $jin, $jout] = self::juzgar($ai, (string) $c['pregunta'], (string) $r['status'], $r['body'] ?? null);
            $in += (int) ($r['input_tokens'] ?? 0) + $jin;
            $out += (int) ($r['output_tokens'] ?? 0) + $jout;
            $conf[$esperado][$obtenido] = ($conf[$esperado][$obtenido] ?? 0) + 1;
            $filas[] = [$c['id'], $c['canal'], $esperado, $obtenido, $esperado === $obtenido ? 'sí' : 'no',
                // Lo grave: la IA contesta sola un caso que una persona debía llevar o aclarar.
                $esperado !== 'responder' && $obtenido === 'responder' ? 'INDEBIDA' : '',
                $r['status'], (string) ($r['body'] ?? $r['error'] ?? ''), $c['pregunta'], $c['respuesta_humana'], $marcas[$c['id']]['comentario'] ?? ''];
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);

        $this->resumen($conf, count($filas), $in, $out);

        $f = "$dir/resultados-" . now()->format('Ymd-His') . '.csv';
        $h = fopen($f, 'w');
        fwrite($h, "\xEF\xBB\xBF");
        fputcsv($h, ['caso', 'canal', 'esperado', 'obtenido', 'coincide', 'alerta', 'estado', 'texto_ia', 'cliente', 'respuesta_humana', 'comentario'], ';');
        foreach ($filas as $fila) {
            fputcsv($h, $fila, ';');
        }
        fclose($h);
        $this->line('Detalle caso a caso: ' . $f);
        return self::SUCCESS;
    }

    private function resumen(array $conf, int $total, int $in, int $out): void
    {
        $ok = 0;
        $tipos = ['responder', 'pedir_info', 'derivar'];
        $filas = [];
        foreach ($tipos as $e) {
            $n = array_sum($conf[$e] ?? []);
            $ok += $conf[$e][$e] ?? 0;
            $filas[] = [$e . " ($n)", $conf[$e]['responder'] ?? 0, $conf[$e]['pedir_info'] ?? 0, $conf[$e]['derivar'] ?? 0];
        }
        $this->table(['Esperado ↓ / La IA hizo →', 'responder', 'pedir_info', 'derivar'], $filas);

        $indebidas = ($conf['pedir_info']['responder'] ?? 0) + ($conf['derivar']['responder'] ?? 0);
        $debiaRespo = array_sum($conf['responder'] ?? []);
        $this->line(sprintf('Coincide con la decisión del equipo: %d de %d (%d %%)', $ok, $total, $total ? round($ok / $total * 100) : 0));
        $this->line(sprintf('Respuestas INDEBIDAS (contestó sola algo que debía pedir dato o derivar): %d', $indebidas));
        $this->line(sprintf('Demasiado prudente (derivó o preguntó cuando podía responder): %d de %d', $debiaRespo - ($conf['responder']['responder'] ?? 0), $debiaRespo));

        [$pi, $po] = config('ai.prices.' . config('ai.providers.' . config('ai.provider') . '.model')) ?? config('ai.prices.default', [0, 0]);
        $this->line(sprintf('Tokens: %s entrada, %s salida · coste estimado %.3f $', number_format($in), number_format($out), $in / 1e6 * $pi + $out / 1e6 * $po));
    }
}
