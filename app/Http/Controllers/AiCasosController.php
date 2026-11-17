<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Revisión del conjunto de pruebas de la IA (página /ia/casos). SOLO en local (config ai.lab): en cualquier otro
 * entorno todo devuelve 404. Lee y escribe storage/app/ai-eval/{candidatos,marcas}.json: cada caso es un ticket real
 * ya anonimizado y la persona marca qué debería hacer la IA (responder / pedir_info / derivar / descartar).
 * No llama a ningún proveedor de IA ni envía nada a nadie.
 */
class AiCasosController extends Controller
{
    private const DECISIONES = ['responder', 'pedir_info', 'derivar', 'descartar'];

    public function __construct()
    {
        abort_unless(config('ai.lab'), 404);
    }

    private function dir(): string
    {
        return (string) config('ai.eval_dir');
    }

    public function page()
    {
        return response()->view('ia-casos');
    }

    /** El JavaScript de la página: aparte porque la CSP de la app no permite scripts dentro del HTML. */
    public function script()
    {
        return response(file_get_contents(resource_path('ia/casos.js')), 200, ['Content-Type' => 'application/javascript; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    /** Lo que hizo la IA en la última evaluación: [id => [obtenido, estado, texto]]. */
    private function ultimaEvaluacion(): array
    {
        $archivos = glob($this->dir() . '/resultados-*.csv') ?: [];
        if (! $archivos) {
            return [];
        }
        sort($archivos);   // del más viejo al más nuevo: de cada caso vale lo último que la IA hizo con él (una pasada parcial no borra el resto)
        $ia = [];
        foreach ($archivos as $archivo) {
            $cuando = preg_match('/resultados-(\d{4})(\d\d)(\d\d)-(\d\d)(\d\d)/', basename($archivo), $t) ? "{$t[3]}/{$t[2]}/{$t[1]} {$t[4]}:{$t[5]}" : null;   // la hora está en el nombre del archivo
            $h = fopen($archivo, 'r');
            if (fread($h, 3) !== "\xEF\xBB\xBF") {
                rewind($h);
            }
            fgetcsv($h, 0, ';', '"', '\\');   // cabecera
            while (($f = fgetcsv($h, 0, ';', '"', '\\')) !== false) {
                if (isset($f[7])) {
                    $ia[(int) $f[0]] = ['obtenido' => $f[3], 'estado' => $f[6], 'texto' => $f[7], 'cuando' => $cuando];
                }
            }
            fclose($h);
        }
        return $ia;
    }

    public function lista()
    {
        $cand = $this->dir() . '/candidatos.json';
        $marcas = $this->dir() . '/marcas.json';
        abort_unless(is_file($cand) && is_file($marcas), 404, 'Faltan candidatos.json y marcas.json');
        $c = json_decode((string) file_get_contents($cand), true);
        $m = json_decode((string) file_get_contents($marcas), true);
        $ia = $this->ultimaEvaluacion();
        $pf = $this->dir() . '/propuestas.json';   // propuestas de la IA para casos sin revisar (ai:prerrellenar)
        $p = is_file($pf) ? (json_decode((string) file_get_contents($pf), true) ?: []) : [];

        // Notas de Claude sobre un caso (storage/app/ai-eval/notas.json: {id: texto}); se enseñan en gris bajo lo que hizo la IA.
        $nf = $this->dir() . '/notas.json';
        $notas = is_file($nf) ? (json_decode((string) file_get_contents($nf), true) ?: []) : [];

        return response()->json(array_map(fn ($x) => [
            'nota' => $notas[$x['id']] ?? null,
            'propuesta' => empty($m[$x['id']]['revisado']) ? ($p[$x['id']] ?? null) : null,
            'id' => $x['id'], 'code' => $x['code'] ?? '', 'lote' => $x['lote'] ?? '', 'canal' => $x['canal'], 'asunto' => $x['asunto'], 'pregunta' => $x['pregunta'], 'historial' => $x['historial'] ?? null, 'humana' => $x['respuesta_humana'],
            'decision' => $m[$x['id']]['decision'] ?? 'responder', 'comentario' => $m[$x['id']]['comentario'] ?? '',
            'revisado' => (bool) ($m[$x['id']]['revisado'] ?? false), 'dudoso' => (bool) ($m[$x['id']]['dudoso'] ?? false), 'ia' => $ia[$x['id']] ?? null,
        ], $c));
    }

    public function guardar(Request $r, int $id)
    {
        $d = $r->validate(['decision' => 'required|in:' . implode(',', self::DECISIONES), 'comentario' => 'nullable|string|max:10000', 'revisado' => 'boolean', 'dudoso' => 'boolean']);
        $f = $this->dir() . '/marcas.json';
        abort_unless(is_file($f), 404);
        if (! is_file($f . '.bak')) {
            copy($f, $f . '.bak');   // copia de lo que había antes de tocar nada
        }
        $m = json_decode((string) file_get_contents($f), true);
        abort_unless(isset($m[$id]), 404);
        $m[$id] = ['decision' => $d['decision'], 'comentario' => trim((string) ($d['comentario'] ?? '')), 'revisado' => (bool) ($d['revisado'] ?? true)]
            + (! empty($d['dudoso']) ? ['dudoso' => true] : []);   // «dudoso»: el caso no le dice nada o está obsoleto y lo revisa después
        file_put_contents($f, json_encode($m, JSON_UNESCAPED_UNICODE), LOCK_EX);
        return response()->json(['ok' => true]);
    }
}
