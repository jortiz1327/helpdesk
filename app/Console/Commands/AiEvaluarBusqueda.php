<?php

namespace App\Console\Commands;

use App\Services\Ai\AiKnowledge;
use Illuminate\Console\Command;

/**
 * Mide la BÚSQUEDA de la memoria (sin IA, sin gasto): para cada caso real etiquetado con el manual que respondía a la pregunta,
 * ¿sale ese manual entre los que recibe la sugerencia? Sirve para decidir si hace falta una búsqueda mejor (por significado)
 * y para comprobar que un cambio en la búsqueda no empeora nada. Las etiquetas están fuera de git, con los casos:
 * storage/app/ai-eval/busqueda-marcas.json  ({"idCaso": ["título del manual", …]}).
 */
class AiEvaluarBusqueda extends Command
{
    protected $signature = 'ai:evaluar-busqueda {--manuales=4 : Cuántos manuales recibe la sugerencia} {--fallos : Lista solo los casos que fallan}';
    protected $description = 'Mide cuántas veces la búsqueda encuentra el manual correcto (sin IA, sin gasto)';

    public function handle(AiKnowledge $k): int
    {
        $dir = (string) config('ai.eval_dir');
        $casos = @json_decode((string) @file_get_contents($dir . '/candidatos.json'), true);
        $marcas = @json_decode((string) @file_get_contents($dir . '/busqueda-marcas.json'), true);
        if (! is_array($casos) || ! is_array($marcas) || ! $marcas) {
            $this->error('Faltan candidatos.json o busqueda-marcas.json en ' . $dir);
            return self::FAILURE;
        }
        $porId = [];
        foreach ($casos as $c) {
            $porId[(string) $c['id']] = $c;
        }
        $n = (int) $this->option('manuales');
        $aciertos = 0;
        $total = 0;
        foreach ($marcas as $id => $esperados) {
            $c = $porId[(string) $id] ?? null;
            if (! $c) {
                continue;
            }
            $total++;
            $r = $k->buscar((string) $c['pregunta'], null, 0, $n, 0, (string) $c['asunto'], 0);
            $titulos = array_values(array_unique(array_map(fn ($d) => $d->title, array_filter($r, fn ($d) => $d->source_type === 'manual'))));
            $ok = (bool) array_intersect($titulos, $esperados);
            $aciertos += $ok ? 1 : 0;
            if (! $this->option('fallos') || ! $ok) {
                $this->line(($ok ? 'OK    ' : 'FALLA ') . $id . ' · esperaba: ' . implode(' | ', $esperados) . ($ok ? '' : ' · salió: ' . (implode(' | ', $titulos) ?: '(nada)')));
            }
        }
        $this->info(sprintf('Aciertos: %d de %d (%d %%)', $aciertos, $total, $total ? round($aciertos / $total * 100) : 0));
        return self::SUCCESS;
    }
}
