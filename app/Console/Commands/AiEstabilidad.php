<?php

namespace App\Console\Commands;

use App\Services\Ai\AiProviders;
use App\Services\Ai\AiSuggestionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Mide la ESTABILIDAD de la IA: el mismo caso, varias veces, ¿decide siempre lo mismo?
 * Usa los casos ya revisados de un lote (con consulta real a AdminHoteles en los marcados [API]) y cuenta
 * cuántos cambian de decisión entre repeticiones. Gasta tokens: cada caso se repite --veces veces.
 */
class AiEstabilidad extends Command
{
    protected $signature = 'ai:estabilidad {--lote= : Nombre completo del lote} {--veces=4 : Repeticiones por caso} {--limite= : Máximo de casos}';

    protected $description = 'Repite cada caso revisado varias veces y cuenta cuántos cambian de decisión (gasta tokens)';

    public function handle(AiSuggestionService $svc): int
    {
        $dir = (string) config('ai.eval_dir');
        $cand = json_decode((string) @file_get_contents("$dir/candidatos.json"), true) ?: [];
        $marcas = json_decode((string) @file_get_contents("$dir/marcas.json"), true) ?: [];
        $lote = (string) $this->option('lote');
        $casos = array_values(array_filter($cand, fn ($c) => ($lote === '' || ($c['lote'] ?? '') === $lote)
            && in_array($marcas[$c['id']]['decision'] ?? 'descartar', ['responder', 'pedir_info', 'derivar'], true)
            && ! empty($marcas[$c['id']]['revisado'])));
        if ($this->option('limite') !== null) {
            $casos = array_slice($casos, 0, max(1, (int) $this->option('limite')));
        }
        $veces = max(2, (int) $this->option('veces'));
        $ai = AiProviders::activo();
        if (! $ai->configured() || ! $casos) {
            $this->error('Sin proveedor de IA configurado o sin casos revisados en ese lote.');
            return self::FAILURE;
        }
        $this->info(count($casos) . " casos × $veces repeticiones con " . config('ai.providers.' . config('ai.provider') . '.model'));

        $locales = DB::table('tickets')->whereIn('code', array_column($casos, 'code'))->pluck('id', 'code');
        $filas = [];
        $inestables = $aciertoTotal = $intentos = 0;
        foreach ($casos as $c) {
            $m = $marcas[$c['id']];
            $conApi = AiEvaluar::necesitaApi($m['comentario'] ?? null);
            $vistas = [];
            for ($i = 0; $i < $veces; $i++) {
                $r = $svc->evaluarCaso((string) $c['asunto'], (string) $c['pregunta'], isset($locales[$c['code']]) ? (int) $locales[$c['code']] : null, $ai, $conApi, $c['conversacion'] ?? null);
                [$obt] = AiEvaluar::juzgar($ai, (string) $c['pregunta'], (string) $r['status'], $r['body'] ?? null, $r['sources'] ?? null);
                $vistas[] = $obt;
            }
            $esperado = $m['decision'];
            $aciertos = count(array_filter($vistas, fn ($v) => $v === $esperado || ($esperado === 'responder' && $v === 'pedir_info' && preg_match('/\[\s*VERIF\s*\]/i', (string) ($m['comentario'] ?? '')))));
            $distintas = count(array_unique($vistas));
            $inestables += $distintas > 1 ? 1 : 0;
            $aciertoTotal += $aciertos;
            $intentos += $veces;
            $filas[] = [$c['id'], $esperado, implode(' ', array_map(fn ($v) => substr($v, 0, 4), $vistas)), "$aciertos/$veces", $distintas > 1 ? 'INESTABLE' : ''];
        }
        $this->table(['caso', 'esperado', 'decisiones', 'aciertos', ''], $filas);
        $this->info(sprintf('Casos que cambian de decisión entre repeticiones: %d de %d · aciertos totales: %d de %d (%d %%)',
            $inestables, count($casos), $aciertoTotal, $intentos, round(100 * $aciertoTotal / max(1, $intentos))));
        return self::SUCCESS;
    }
}
