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
        {--decision= : Solo los casos con esa decisión esperada (responder, pedir_info, derivar)}
        {--sin-revisar : Incluir también los casos aún sin revisar, solo para ver lo que responde la IA (la nota que salga no vale)}
        {--lote= : Solo los casos de este lote (ver ai:nuevos-casos); sin esto, los de todos los lotes}
        {--caso=* : Solo estos casos (id del ticket; se puede repetir: --caso=5669 --caso=6223)}
        {--sin-consulta : No consultar AdminHoteles en los casos [API] (como antes)}';

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
     * ¿Derivó la IA? Se lee de las MARCAS que ella misma deja, sin llamar a nadie: «no sé» o fallo (sin borrador) o la línea
     * interna `[[DERIVAR:categoria|motivo]]` que queda guardada en `sources.derivar`. Null = no hay marca: es un borrador
     * normal, que hay que clasificar como pedir_info o responder.
     */
    public static function porMarca(string $status, ?string $body, ?string $fuentes = null): ?string
    {
        if ($status !== 'ready' || trim((string) $body) === '') {
            return 'derivar';
        }
        $f = $fuentes ? json_decode($fuentes, true) : null;
        return is_array($f) && ! empty($f['derivar']) ? 'derivar' : null;
    }

    /**
     * Lo que hizo la IA. Primero por la marca (derivar / no sabe, sin gastar nada); solo los borradores sin marca se juzgan por el
     * propio modelo (una llamada corta, más fiable que mirar palabras sueltas) como pedir_info o responder. Si el juez falla o
     * no contesta algo válido, se cae a la regla por palabras (clasificar).
     *
     * @return array{0:string, 1:int, 2:int, 3:bool} decisión, tokens de entrada, tokens de salida, ¿salió de la marca?
     */
    public static function juzgar(\App\Services\Ai\AiProvider $ai, string $cliente, string $status, ?string $body, ?string $fuentes = null): array
    {
        if ($marca = self::porMarca($status, $body, $fuentes)) {
            return [$marca, 0, 0, true];
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
                    return [$d, (int) ($r['input_tokens'] ?? 0), (int) ($r['output_tokens'] ?? 0), false];
                }
            }
            return [self::clasificar($status, $body), (int) ($r['input_tokens'] ?? 0), (int) ($r['output_tokens'] ?? 0), false];
        } catch (\Throwable $e) {
            return [self::clasificar($status, $body), 0, 0, false];
        }
    }

    public function handle(AiSuggestionService $svc): int
    {
        $dir = (string) config('ai.eval_dir');
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
                && ($this->option('sin-revisar') || ! empty($marcas[$c['id']]['revisado']))   // un caso sin revisar no tiene decisión del equipo con la que comparar
                && (! $this->option('lote') || ($c['lote'] ?? '') === $this->option('lote'))
                && (! $this->option('decision') || $marcas[$c['id']]['decision'] === $this->option('decision'))
                && (! $this->option('caso') || in_array((string) $c['id'], array_map('strval', (array) $this->option('caso')), true))
        ));
        if ($this->option('limite') !== null) {
            $casos = array_slice($casos, 0, max(1, (int) $this->option('limite')));
        }
        $this->info(count($casos) . ' casos con ' . config('ai.provider') . ' (' . config('ai.providers.' . config('ai.provider') . '.model') . ')');

        $locales = DB::table('tickets')->whereIn('code', array_column($casos, 'code'))->pluck('id', 'code');
        $filas = [];
        $conf = [];      // casos que la IA puede resolver con lo que tiene (la nota principal)
        $confApi = [];   // casos marcados [API]: dependen de consultar AdminHoteles, que esta prueba no hace
        $in = $out = 0;
        $porMarca = $sinMarca = 0;   // decisiones tomadas por la marca (sin llamada) / derivaciones que solo el juez vio
        $bar = $this->output->createProgressBar(count($casos));
        foreach ($casos as $c) {
            $esperado = $marcas[$c['id']]['decision'];
            // Los casos [API] se miden CON la consulta real (solo lectura) a AdminHoteles del hotel que nombra el mensaje.
            $conApi = ! $this->option('sin-consulta') && self::necesitaApi($marcas[$c['id']]['comentario'] ?? null);
            $r = $svc->evaluarCaso((string) $c['asunto'], (string) $c['pregunta'], isset($locales[$c['code']]) ? (int) $locales[$c['code']] : null, $ai, $conApi, $c['conversacion'] ?? null);
            [$obtenido, $jin, $jout, $deMarca] = self::juzgar($ai, (string) $c['pregunta'], (string) $r['status'], $r['body'] ?? null, $r['sources'] ?? null);
            $porMarca += $deMarca ? 1 : 0;
            // Derivó con palabras pero SIN dejar la marca: la IA se ha saltado la regla de añadir `[[DERIVAR:…]]` (a vigilar).
            $sinMarca += (! $deMarca && $obtenido === 'derivar') ? 1 : 0;
            // [VERIF] en el comentario: «responder, pero solo tras verificar hotel y correo». Sin correo conocido, que la IA lo pida es acierto.
            if ($esperado === 'responder' && $obtenido === 'pedir_info' && preg_match('/\[\s*VERIF\s*\]/i', (string) ($marcas[$c['id']]['comentario'] ?? ''))) {
                $esperado = 'pedir_info';
            }
            $in += (int) ($r['input_tokens'] ?? 0) + $jin;
            $out += (int) ($r['output_tokens'] ?? 0) + $jout;
            $api = self::necesitaApi($marcas[$c['id']]['comentario'] ?? null);
            if ($api) {
                $confApi[$esperado][$obtenido] = ($confApi[$esperado][$obtenido] ?? 0) + 1;
            } else {
                $conf[$esperado][$obtenido] = ($conf[$esperado][$obtenido] ?? 0) + 1;
            }
            $filas[] = [$c['id'], $c['canal'], $esperado, $obtenido, $esperado === $obtenido ? 'sí' : 'no',
                // Lo grave: la IA contesta sola un caso que una persona debía llevar o aclarar.
                $esperado !== 'responder' && $obtenido === 'responder' ? 'INDEBIDA' : '',
                $r['status'], (string) ($r['body'] ?? $r['error'] ?? ''), $c['pregunta'], $c['respuesta_humana'], $marcas[$c['id']]['comentario'] ?? '', $api ? 'sí' : 'no'];
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);
        $this->line(sprintf('Decididos por la marca (derivar / no sabe, sin llamada extra): %d de %d · derivaciones SIN marca (solo las vio el juez): %d', $porMarca, count($casos), $sinMarca));

        $this->info('— SIN API (la nota principal: casos que la IA puede resolver sin consultar AdminHoteles) —');
        $this->resumen($conf, $in, $out);
        if ($confApi) {
            $this->newLine();
            $this->info($this->option('sin-consulta')
                ? '— NECESITAN API (marcados [API]: aquí la IA va a ciegas, no es un fallo suyo) —'
                : '— CON API (marcados [API]: la IA ve lo que AdminHoteles dice del hotel que nombra el mensaje; no hay correo, así que no se verifica quién escribe) —');
            $this->resumen($confApi, null, null);
        }

        $f = "$dir/resultados-" . now()->format('Ymd-His') . '.csv';
        $h = fopen($f, 'w');
        fwrite($h, "\xEF\xBB\xBF");
        fputcsv($h, ['caso', 'canal', 'esperado', 'obtenido', 'coincide', 'alerta', 'estado', 'texto_ia', 'cliente', 'respuesta_humana', 'comentario', 'necesita_api'], ';');
        foreach ($filas as $fila) {
            fputcsv($h, $fila, ';');
        }
        fclose($h);
        $this->line('Detalle caso a caso: ' . $f);
        return self::SUCCESS;
    }

    /** ¿El comentario del revisor marca el caso como dependiente de AdminHoteles? (etiqueta [API] en cualquier sitio) */
    public static function necesitaApi(?string $comentario): bool
    {
        return (bool) preg_match('/\[\s*API\s*\]/i', (string) $comentario);
    }

    private function resumen(array $conf, ?int $in, ?int $out): void
    {
        $total = array_sum(array_map('array_sum', $conf));
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

        if ($in === null) {
            return;   // el coste se enseña una sola vez, en el bloque principal
        }
        [$pi, $po] = config('ai.prices.' . config('ai.providers.' . config('ai.provider') . '.model')) ?? config('ai.prices.default', [0, 0]);
        $this->line(sprintf('Tokens: %s entrada, %s salida · coste estimado %.3f $', number_format($in), number_format($out), $in / 1e6 * $pi + $out / 1e6 * $po));
    }
}
