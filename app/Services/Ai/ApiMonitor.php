<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Seguimiento de cada API/herramienta que usa la IA (AdminHoteles, proveedor de IA, memoria propia): cuánto tarda, cuántas veces
 * falla, cuántas lentas y cuántas respuestas salen de la caché. Se guarda en la caché del servidor (últimos 400 sucesos de cada una,
 * se leen las últimas 24 h) y se enseña en Ajustes › Asistente IA. Solo se anota el NOMBRE de la operación y la duración:
 * nunca correos, nombres ni textos. Anotar jamás debe romper una sugerencia.
 */
class ApiMonitor
{
    private const MAX = 400;
    private const TTL = 604800;   // una semana

    /** Umbral (segundos) a partir del cual una llamada se cuenta como «lenta», por API. */
    public const LENTA = ['adminhoteles' => 6.0, 'proveedor' => 25.0, 'memoria' => 1.0];

    public static function registrar(string $api, string $operacion, float $segundos, bool $ok = true, ?string $nota = null): void
    {
        try {
            $l = (array) Cache::get("ai_api_$api", []);
            array_unshift($l, ['t' => time(), 'op' => mb_substr($operacion, 0, 80), 'ms' => (int) round($segundos * 1000), 'ok' => $ok, 'nota' => $nota]);
            Cache::put("ai_api_$api", array_slice($l, 0, self::MAX), self::TTL);
        } catch (Throwable $e) {
            // sin caché disponible no hay estadística; la sugerencia sigue
        }
    }

    /** Una consulta que se resolvió con (o sin) lo que había guardado. */
    public static function cache(string $api, bool $acierto): void
    {
        try {
            $l = (array) Cache::get("ai_api_cache_$api", []);
            array_unshift($l, [time(), $acierto ? 1 : 0]);
            Cache::put("ai_api_cache_$api", array_slice($l, 0, self::MAX), self::TTL);
        } catch (Throwable $e) {
        }
    }

    /** Mide $f, lo anota y devuelve su resultado; si lanza, anota el fallo y vuelve a lanzar. */
    public static function medir(string $api, string $operacion, callable $f)
    {
        $t0 = microtime(true);
        try {
            $r = $f();
        } catch (Throwable $e) {
            self::registrar($api, $operacion, microtime(true) - $t0, false, class_basename($e));
            throw $e;
        }
        self::registrar($api, $operacion, microtime(true) - $t0, true);
        return $r;
    }

    /**
     * Resumen de las últimas 24 h.
     *
     * @return array{nivel:string, llamadas:int, errores:int, lentas:int, media_ms:?int, p50_ms:?int, p95_ms:?int, max_ms:?int, cache:?array, operaciones:array, ultimo_error:?array}
     */
    public static function resumen(string $api): array
    {
        $desde = time() - 86400;
        $l = array_values(array_filter((array) Cache::get("ai_api_$api", []), fn ($x) => ($x['t'] ?? 0) >= $desde));
        $c = array_values(array_filter((array) Cache::get("ai_api_cache_$api", []), fn ($x) => ($x[0] ?? 0) >= $desde));
        $umbral = (int) round((self::LENTA[$api] ?? 6.0) * 1000);

        $n = count($l);
        $errores = count(array_filter($l, fn ($x) => ! $x['ok']));
        $lentas = count(array_filter($l, fn ($x) => $x['ok'] && $x['ms'] > $umbral));
        $ms = array_column($l, 'ms');
        sort($ms);
        $pct = fn (array $v, float $p) => $v ? $v[(int) min(count($v) - 1, floor($p * count($v)))] : null;

        $ops = [];
        foreach ($l as $x) {
            $ops[$x['op']][] = $x;
        }
        $porOp = [];
        foreach ($ops as $op => $xs) {
            $m = array_column($xs, 'ms');
            sort($m);
            $porOp[] = ['op' => $op, 'llamadas' => count($xs), 'errores' => count(array_filter($xs, fn ($x) => ! $x['ok'])),
                'media_ms' => (int) round(array_sum($m) / count($m)), 'p95_ms' => $pct($m, 0.95)];
        }
        usort($porOp, fn ($a, $b) => $b['llamadas'] <=> $a['llamadas']);

        $err = null;
        foreach ($l as $x) {
            if (! $x['ok']) {
                $err = ['at' => date('c', $x['t']), 'op' => $x['op'], 'nota' => $x['nota'], 'ms' => $x['ms']];
                break;
            }
        }
        $nivel = $n === 0 ? 'sin_uso'
            : ($errores >= 3 || ($n >= 8 && $errores / $n > 0.25) ? 'mal' : ($errores > 0 || $lentas / $n > 0.2 ? 'aviso' : 'ok'));

        return [
            'nivel' => $nivel, 'llamadas' => $n, 'errores' => $errores, 'lentas' => $lentas,
            'media_ms' => $n ? (int) round(array_sum($ms) / $n) : null,
            'p50_ms' => $pct($ms, 0.5), 'p95_ms' => $pct($ms, 0.95), 'max_ms' => $ms ? end($ms) : null,
            'cache' => $c ? ['consultas' => count($c), 'aciertos' => array_sum(array_column($c, 1)), 'pct' => (int) round(array_sum(array_column($c, 1)) / count($c) * 100)] : null,
            'operaciones' => array_slice($porOp, 0, 6),
            'ultimo_error' => $err,
        ];
    }
}
