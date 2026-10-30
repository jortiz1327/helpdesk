<?php

namespace App\Http\Controllers;

use App\Services\AemePortal;
use App\Services\WarrantyLabels;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * device_catalog.php — catálogo de modelos de etiqueta: tamaño, colores, pilas (tipo y
 * cantidad, de donde salen los presupuestos de pilas) y sus referencias ZKONG ↔ AEME.
 * La referencia ZKONG es la que se casa con el modelo que da el servidor; la AEME, el
 * producto del portal (item_ref). Consultarlo: warranties.access. Cambiarlo:
 * warranties.config.
 *
 * Al guardar un modelo, las etiquetas de las garantías con una de sus referencias AEME
 * pasan a ese modelo (así el tipo de pila llega a los presupuestos ya hechos de nuevo).
 */
class DeviceCatalogController extends Controller
{
    public const EINK = ['BW' => 'Blanco y negro', 'BWR' => 'Tricolor', 'BWRY' => 'Cuatro colores'];
    public const COLORS = ['white' => 'Blanco', 'black' => 'Negro'];

    public function handle(Request $request)
    {
        $action = $request->query('action', 'list');
        if ($action !== 'list' && ! $request->user()->can('warranties.config')) {
            return response()->json(['ok' => false, 'error' => 'Solo quien tiene Configuración de garantías cambia el catálogo'], 403);
        }
        return match ($action) {
            'save'   => $this->save($request),
            'delete' => $this->delete($request),
            default  => $this->list($request),
        };
    }

    private function list(Request $request)
    {
        $refs = DB::table('device_model_references')->orderBy('id')->get()->groupBy('device_model_id');
        $uso = DB::table('warranty_items')->whereNotNull('device_model_id')->groupBy('device_model_id')
            ->selectRaw('device_model_id, count(*) as n')->pluck('n', 'device_model_id');
        $portal = AemePortal::products();
        $enPortal = $portal !== null ? collect($portal)->keyBy('ref') : null;

        $models = DB::table('device_models')->orderBy('size')->orderBy('eink_colors')->orderBy('id')->get()
            ->map(fn ($m) => [
                'id' => $m->id, 'size' => $m->size !== null ? (float) $m->size : null, 'eink_colors' => $m->eink_colors,
                'color' => $m->color, 'variant' => $m->variant, 'has_batteries' => (bool) $m->has_batteries,
                'battery_type' => $m->battery_type, 'battery_quantity' => $m->battery_quantity,
                'description' => WarrantyLabels::describe((array) $m),
                'refs' => $this->filas($refs[$m->id] ?? collect()),
                'used' => (int) ($uso[$m->id] ?? 0),
            ]);

        // Etiquetas del portal que no están en ningún modelo (las antenas no van en el catálogo).
        $conModelo = DB::table('device_model_references')->where('provider', 'aeme')->pluck('name')->map(fn ($n) => strtoupper($n))->flip();
        $usoRef = DB::table('warranty_items')->whereNotNull('aeme_ref')->groupBy('aeme_ref')->selectRaw('aeme_ref, count(*) as n')->pluck('n', 'aeme_ref')
            ->mapWithKeys(fn ($n, $r) => [strtoupper($r) => $n]);
        $sinModelo = $portal === null ? [] : collect($portal)->where('kind', 'esl')->reject(fn ($p) => isset($conModelo[$p['ref']]))
            ->map(fn ($p) => ['ref' => $p['ref'], 'name' => $p['name'], 'used' => (int) ($usoRef[$p['ref']] ?? 0)])
            ->sortByDesc('used')->values()->all();

        return response()->json([
            'ok' => true, 'models' => $models,
            'portal' => $enPortal ? $enPortal->map(fn ($p) => ['name' => $p['name'], 'kind' => $p['kind']]) : null,
            'unmapped' => $sinModelo,
            'battery_types' => DB::table('device_models')->whereNotNull('battery_type')->where('battery_type', '!=', '')->distinct()->orderBy('battery_type')->pluck('battery_type'),
            'eink' => self::EINK, 'colors' => self::COLORS,
            'can_edit' => $request->user()->can('warranties.config'),
        ]);
    }

    /** Referencias de un modelo como parejas [zkong, aeme]; las sueltas, con el otro lado vacío. */
    private function filas($refs): array
    {
        $porId = $refs->keyBy('id');
        $usadas = [];
        $filas = [];
        foreach ($refs->where('provider', 'zkong') as $z) {
            $a = $z->pair_id ? $porId[$z->pair_id] ?? null : null;
            $a = $a && $a->provider === 'aeme' ? $a : null;
            if ($a) $usadas[$a->id] = true;
            $filas[] = ['zkong' => $z->name, 'aeme' => $a?->name ?? ''];
        }
        foreach ($refs->where('provider', 'aeme') as $a) {
            if (! isset($usadas[$a->id])) $filas[] = ['zkong' => '', 'aeme' => $a->name];
        }
        return $filas;
    }

    private function error(string $msg)
    {
        return response()->json(['ok' => false, 'error' => $msg], 400);
    }

    private function save(Request $request)
    {
        $id = (int) $request->input('id');
        if ($id && ! DB::table('device_models')->where('id', $id)->exists()) return $this->error('Ese modelo ya no existe');

        $size = trim(str_replace(',', '.', (string) $request->input('size', '')));
        if ($size !== '' && (! is_numeric($size) || (float) $size <= 0 || (float) $size > 100)) return $this->error('Tamaño no válido (pulgadas, p. ej. 2,6)');
        $eink = (string) $request->input('eink_colors');
        if (! isset(self::EINK[$eink])) return $this->error('Elige los colores de la pantalla');
        $color = (string) $request->input('color');
        if (! isset(self::COLORS[$color])) return $this->error('Elige el color de la carcasa');
        $pilas = $request->boolean('has_batteries');
        $tipo = strtoupper(trim((string) $request->input('battery_type', '')));
        $cant = (int) $request->input('battery_quantity', 0);
        if ($pilas && ($tipo === '' || mb_strlen($tipo) > 30)) return $this->error('Indica el tipo de pila (p. ej. CR2450)');
        if ($pilas && ($cant < 1 || $cant > 9)) return $this->error('Indica cuántas pilas lleva (de 1 a 9)');

        // Referencias: parejas sin repetir, ni aquí ni en otro modelo.
        $filas = [];
        $vistas = ['zkong' => [], 'aeme' => []];
        foreach ((array) $request->input('refs', []) as $f) {
            $z = mb_substr(trim((string) ($f['zkong'] ?? '')), 0, 80);
            $a = mb_substr(strtoupper(trim((string) ($f['aeme'] ?? ''))), 0, 80);
            if ($z === '' && $a === '') continue;
            foreach (['zkong' => $z, 'aeme' => $a] as $p => $n) {
                if ($n === '') continue;
                $k = mb_strtoupper($n);
                if (isset($vistas[$p][$k])) return $this->error("La referencia {$n} está repetida");
                $vistas[$p][$k] = true;
                $otro = DB::table('device_model_references as r')->where('r.provider', $p)->whereRaw('upper(r.name) = ?', [$k])
                    ->when($id, fn ($q) => $q->where('r.device_model_id', '!=', $id))->value('r.device_model_id');
                if ($otro) return $this->error("La referencia {$n} ya está en otro modelo");
            }
            $filas[] = [$z, $a];
        }
        if (! $filas) return $this->error('Añade al menos una referencia ZKONG o AEME');

        $datos = [
            'size' => $size === '' ? null : round((float) $size, 2), 'eink_colors' => $eink, 'color' => $color,
            'variant' => ($v = trim((string) $request->input('variant', ''))) !== '' ? mb_substr($v, 0, 60) : null,
            'has_batteries' => $pilas, 'battery_type' => $pilas ? $tipo : null, 'battery_quantity' => $pilas ? $cant : null,
            'updated_at' => now(),
        ];

        $movidas = DB::transaction(function () use ($id, $datos, $filas) {
            if ($id) DB::table('device_models')->where('id', $id)->update($datos);
            else $id = DB::table('device_models')->insertGetId($datos + ['created_at' => now()]);

            DB::table('device_model_references')->where('device_model_id', $id)->delete();
            $ahora = now();
            foreach ($filas as [$z, $a]) {
                $zid = $z !== '' ? DB::table('device_model_references')->insertGetId(['device_model_id' => $id, 'provider' => 'zkong', 'name' => $z, 'created_at' => $ahora, 'updated_at' => $ahora]) : null;
                $aid = $a !== '' ? DB::table('device_model_references')->insertGetId(['device_model_id' => $id, 'provider' => 'aeme', 'name' => $a, 'pair_id' => $zid, 'created_at' => $ahora, 'updated_at' => $ahora]) : null;
                if ($zid && $aid) DB::table('device_model_references')->where('id', $zid)->update(['pair_id' => $aid]);
            }

            // Las etiquetas de las garantías siguen a su referencia AEME.
            $aemes = array_values(array_filter(array_column($filas, 1)));
            $movidas = $aemes ? DB::table('warranty_items')->whereIn(DB::raw('upper(aeme_ref)'), $aemes)
                ->where(fn ($q) => $q->whereNull('device_model_id')->orWhere('device_model_id', '!=', $id))->update(['device_model_id' => $id]) : 0;
            DB::table('warranty_items')->where('device_model_id', $id)->whereNotNull('aeme_ref')
                ->when($aemes, fn ($q) => $q->whereNotIn(DB::raw('upper(aeme_ref)'), $aemes))->update(['device_model_id' => null]);
            return [$id, $movidas];
        });

        return response()->json(['ok' => true, 'id' => $movidas[0], 'relinked' => $movidas[1]]);
    }

    private function delete(Request $request)
    {
        $id = (int) $request->input('id');
        $n = DB::table('warranty_items')->where('device_model_id', $id)->count();
        if ($n && ! $request->boolean('force')) {
            return response()->json(['ok' => false, 'error' => "Lo tienen {$n} etiquetas de garantías", 'used' => $n], 409);
        }
        DB::table('device_models')->where('id', $id)->delete();   // referencias en cascada; las etiquetas se quedan sin modelo
        return response()->json(['ok' => true]);
    }
}
