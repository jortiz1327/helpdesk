<?php

namespace App\Services\Ai;

use App\Models\Setting;
use Carbon\Carbon;
use FilesystemIterator;
use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Catálogo de manuales de la IA por ÁREA (config('ai.areas')): qué documentación hay de cada una, cuándo cambió, cuándo se revisó por
 * última vez y si ya está en la memoria de la IA. Alimenta la tarjeta «Manuales por área» de Ajustes › Asistente IA.
 *
 * Proceso para mantenerlos al día (ver docs/manuales-ia.md): cuando cambia la aplicación se edita el .md de su área en
 * resources/ai-docs/<carpeta>/, se despliega, se pulsa «Actualizar conocimiento» y se marca el área como «Revisado hoy».
 * La fecha de revisión vive en la base de datos (no en el repositorio): marcarla en producción no ensucia el despliegue.
 */
class ManualesCatalogo
{
    private const AJUSTE = 'ai_manuales_revisado';

    /** @return array<string,string> área => fecha Y-m-d de la última revisión */
    public static function revisados(): array
    {
        $v = json_decode((string) Setting::get(self::AJUSTE, '{}'), true);
        return is_array($v) ? array_filter($v, 'is_string') : [];
    }

    /** Marca un área como revisada hoy. false si el área no existe. */
    public static function marcarRevisado(string $area): bool
    {
        if (! array_key_exists($area, (array) config('ai.areas', []))) {
            return false;
        }
        $r = self::revisados();
        $r[$area] = now()->toDateString();
        Setting::put(self::AJUSTE, json_encode($r));
        return true;
    }

    /** Área a la que pertenece un documento de la memoria (por su source_id: «doc:hoteles/03-usuarios.md#2» → hoteles). */
    public static function areaDe(string $sourceId): ?string
    {
        foreach ((array) config('ai.areas', []) as $clave => $a) {
            if (! empty($a['carpeta']) && str_starts_with($sourceId, 'doc:' . $a['carpeta'] . '/')) {
                return $clave;
            }
            if (! empty($a['manual']) && str_starts_with($sourceId, $a['manual'] . '#')) {
                return $clave;
            }
        }
        return null;
    }

    /** Archivos .md / .txt de una carpeta de resources/ai-docs: ruta relativa a ai-docs => fecha de modificación. */
    private static function archivos(string $carpeta): array
    {
        $base = resource_path('ai-docs');
        $dir = $base . DIRECTORY_SEPARATOR . $carpeta;
        $out = [];
        if (! is_dir($dir)) {
            return $out;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile() && in_array(strtolower($f->getExtension()), ['md', 'txt'], true)) {
                $out[str_replace('\\', '/', substr($f->getPathname(), strlen($base) + 1))] = $f->getMTime();
            }
        }
        return $out;
    }

    /**
     * @return array<int, array{clave:string, nombre:string, descripcion:string, archivos:int, trozos:int, estado:string, nivel:string,
     *   cambiado:?string, indexado:?string, revisado:?string, dias_revision:?int, revision_dias:int, pendientes:array{nuevos:int,cambiados:int,borrados:int}}>
     */
    public static function resumen(): array
    {
        $revisados = self::revisados();
        $out = [];
        foreach ((array) config('ai.areas', []) as $clave => $a) {
            $archivos = [];
            $ult = null;
            $trozos = 0;
            $p = ['nuevos' => 0, 'cambiados' => 0, 'borrados' => 0];
            if (! empty($a['carpeta'])) {
                $archivos = self::archivos($a['carpeta']);
                $indexado = DB::table('ai_documents')->where('source_type', 'manual')->where('source_id', 'like', 'doc:' . $a['carpeta'] . '/%')
                    ->selectRaw("SUBSTRING_INDEX(source_id, '#', 1) as k, MAX(indexed_at) as ult, COUNT(*) as n")->groupBy('k')->get();
                $trozos = (int) $indexado->sum('n');
                $ult = $indexado->max('ult');
                $porRuta = $indexado->pluck('ult', 'k')->all();
                foreach ($archivos as $rel => $mt) {
                    $i = $porRuta['doc:' . $rel] ?? null;
                    if ($i === null) {
                        $p['nuevos']++;
                    } elseif ($mt > Carbon::parse($i)->getTimestamp() + 2) {
                        $p['cambiados']++;
                    }
                }
                $p['borrados'] = count(array_diff_key($porRuta, array_combine(array_map(fn ($r) => 'doc:' . $r, array_keys($archivos)), array_keys($archivos))));
            } elseif (! empty($a['manual'])) {
                $m = config('manuals.catalog.' . $a['manual']);
                $ruta = $m ? resource_path('manuals/' . $m['file']) : null;
                if ($ruta && is_file($ruta)) {
                    $archivos = [$m['file'] => filemtime($ruta)];
                }
                $q = DB::table('ai_documents')->where('source_type', 'manual')->where('source_id', 'like', $a['manual'] . '#%');
                $trozos = (int) (clone $q)->count();
                $ult = $q->max('indexed_at');
                if ($archivos && $ult === null) {
                    $p['nuevos'] = 1;
                }
            }

            $rev = $revisados[$clave] ?? null;
            $dias = $rev ? (int) Carbon::parse($rev)->startOfDay()->diffInDays(now()->startOfDay()) : null;
            $limite = (int) ($a['revision_dias'] ?? 60);
            $hay = count($archivos) > 0;
            $pend = array_sum($p) > 0;
            $estado = $a['estado'] ?? 'activa';
            $nivel = ! $hay ? 'vacia' : ($pend ? 'pendiente' : ($dias === null || $dias > $limite ? 'revisar' : 'ok'));
            $out[] = [
                'clave' => $clave, 'nombre' => (string) $a['nombre'], 'descripcion' => (string) ($a['descripcion'] ?? ''),
                'archivos' => count($archivos), 'trozos' => $trozos, 'estado' => $estado, 'nivel' => $nivel,
                'cambiado' => $archivos ? date('Y-m-d', max($archivos)) : null,
                'indexado' => $ult ? Carbon::parse($ult)->toDateString() : null,
                'revisado' => $rev, 'dias_revision' => $dias, 'revision_dias' => $limite, 'pendientes' => $p,
            ];
        }
        return $out;
    }
}
