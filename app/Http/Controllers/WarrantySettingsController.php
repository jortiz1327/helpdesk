<?php

namespace App\Http\Controllers;

use App\Models\Warranty;
use App\Models\WarrantyDocument;
use App\Services\WarrantyAlerts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Configuración de Gestión de garantías (permiso warranties.config): estados,
 * categorías, reglas de alerta, plantillas de diagnóstico, tarifa de los documentos
 * (por referencia AEME, por tipo de pila y conceptos sueltos) y ajustes de los
 * documentos (empresa, IVA, condiciones y correo de resolución).
 *
 * Estados: los de serie no se borran (sí se renombran, se recolorean y se desactivan,
 * salvo los dos que usa la propia aplicación: no iniciado y cerrada). Uno propio se
 * borra solo si ninguna garantía lo tiene. Igual con las categorías: renombrar cambia
 * las garantías que la tenían.
 */
class WarrantySettingsController extends Controller
{
    private const FIJOS = [Warranty::STATUS_NEW, Warranty::STATUS_CLOSED, Warranty::STATUS_REOPEN];

    public function handle(Request $request)
    {
        return match ($request->query('action', 'get')) {
            'status_save'     => $this->statusSave($request),
            'status_delete'   => $this->statusDelete($request),
            'category_save'   => $this->categorySave($request),
            'category_delete' => $this->categoryDelete($request),
            'order'           => $this->order($request),
            'alerts_save'     => $this->alertsSave($request),
            'template_save'   => $this->templateSave($request),
            'template_delete' => $this->templateDelete($request),
            'price_save'      => $this->priceSave($request),
            'price_delete'    => $this->priceDelete($request),
            'docs_save'       => $this->docsSave($request),
            default           => $this->get(),
        };
    }

    private function get()
    {
        $usoEstado = DB::table('warranties')->groupBy('status')->selectRaw('status, count(*) as n')->pluck('n', 'status');
        $usoCat = DB::table('warranties')->whereNotNull('category')->groupBy('category')->selectRaw('category, count(*) as n')->pluck('n', 'category');
        $reglas = app(WarrantyAlerts::class)->rules();

        return response()->json([
            'ok' => true,
            'statuses' => DB::table('warranty_statuses')->orderBy('sort')->orderBy('label')->get()
                ->map(fn ($s) => (array) $s + ['used' => (int) ($usoEstado[$s->key] ?? 0), 'fixed' => in_array($s->key, self::FIJOS, true)]),
            'categories' => DB::table('warranty_categories')->orderBy('sort')->orderBy('name')->get()
                ->map(fn ($c) => (array) $c + ['used' => (int) ($usoCat[$c->name] ?? 0)]),
            'alerts' => collect(WarrantyAlerts::RULES)->map(fn ($r, $k) => [
                'key' => $k, 'label' => $r[0], 'description' => $r[1], 'instant' => $r[3],
                'active' => $reglas[$k]['active'] ?? false, 'warn_days' => $reglas[$k]['warn_days'] ?? 0, 'crit_days' => $reglas[$k]['crit_days'] ?? 0,
            ])->values(),
            'templates' => DB::table('warranty_diagnosis_templates')->orderBy('sort')->orderBy('id')->get(['id', 'title', 'body', 'active']),
            'prices' => DB::table('warranty_prices')->orderBy('kind')->orderBy('ref')->get(['id', 'kind', 'ref', 'label', 'price'])
                ->map(fn ($p) => (array) $p + ['price' => (float) $p->price]),
            'price_hints' => $this->pistasTarifa(),
            'docs' => [
                'company'          => WarrantyDocument::setting('company'),
                'documents'        => WarrantyDocument::setting('documents'),
                'resolution_email' => WarrantyDocument::setting('resolution_email'),
            ],
        ]);
    }

    private function error(string $msg)
    {
        return response()->json(['ok' => false, 'error' => $msg], 400);
    }

    /* --- Estados --- */

    private function statusSave(Request $request)
    {
        $key = (string) $request->input('key', '');
        $label = trim((string) $request->input('label', ''));
        $color = (string) $request->input('color', '#64748b');
        if ($label === '' || mb_strlen($label) > 60) return $this->error('Escribe el nombre del estado (hasta 60 letras)');
        if (! preg_match('/^#[0-9a-f]{6}$/i', $color)) return $this->error('Color no válido');
        $repetido = DB::table('warranty_statuses')->whereRaw('lower(label) = ?', [mb_strtolower($label)])->where('key', '!=', $key)->exists();
        if ($repetido) return $this->error('Ya hay un estado con ese nombre');

        $datos = ['label' => $label, 'color' => strtolower($color), 'closed' => $request->boolean('closed'), 'active' => $request->boolean('active', true)];
        $actual = $key !== '' ? DB::table('warranty_statuses')->where('key', $key)->first() : null;
        if ($key !== '' && ! $actual) return $this->error('Ese estado ya no existe');

        // Los que usa la aplicación siguen activos y con su sentido (cerrada es cerrada).
        if ($actual && in_array($key, self::FIJOS, true)) {
            $datos['active'] = true;
            $datos['closed'] = $key === Warranty::STATUS_CLOSED;
        }
        if ($actual) {
            DB::table('warranty_statuses')->where('key', $key)->update($datos);
        } else {
            $key = $this->clave($label);
            DB::table('warranty_statuses')->insert($datos + ['key' => $key, 'system' => false,
                'sort' => (int) DB::table('warranty_statuses')->max('sort') + 1]);
        }
        Warranty::forgetStatuses();
        return response()->json(['ok' => true, 'key' => $key]);
    }

    /** Clave para un estado nuevo a partir de su nombre: «Pte. revisión» → pte_revision. */
    private function clave(string $label): string
    {
        $base = substr(Str::slug($label, '_'), 0, 24) ?: 'estado';
        $k = $base;
        for ($n = 2; DB::table('warranty_statuses')->where('key', $k)->exists(); $n++) $k = "{$base}_{$n}";
        return $k;
    }

    private function statusDelete(Request $request)
    {
        $s = DB::table('warranty_statuses')->where('key', (string) $request->input('key'))->first();
        if (! $s) return $this->error('Ese estado ya no existe');
        if ($s->system) return $this->error('Los estados de serie no se borran: desactívalo si no lo usáis');
        $n = DB::table('warranties')->where('status', $s->key)->count();
        if ($n) return $this->error("Hay {$n} " . ($n === 1 ? 'garantía' : 'garantías') . ' en este estado: desactívalo en lugar de borrarlo');
        DB::table('warranty_statuses')->where('key', $s->key)->delete();
        Warranty::forgetStatuses();
        return response()->json(['ok' => true]);
    }

    /* --- Categorías --- */

    private function categorySave(Request $request)
    {
        $id = (int) $request->input('id');
        $name = trim((string) $request->input('name', ''));
        if ($name === '' || mb_strlen($name) > 60) return $this->error('Escribe el nombre de la categoría (hasta 60 letras)');
        if (DB::table('warranty_categories')->whereRaw('lower(name) = ?', [mb_strtolower($name)])->where('id', '!=', $id)->exists()) {
            return $this->error('Ya hay una categoría con ese nombre');
        }
        $activa = $request->boolean('active', true);
        if (! $id) {
            $id = DB::table('warranty_categories')->insertGetId(['name' => $name, 'active' => $activa,
                'sort' => (int) DB::table('warranty_categories')->max('sort') + 1]);
            return response()->json(['ok' => true, 'id' => $id]);
        }
        $antes = DB::table('warranty_categories')->where('id', $id)->value('name');
        if ($antes === null) return $this->error('Esa categoría ya no existe');
        DB::transaction(function () use ($id, $name, $activa, $antes) {
            DB::table('warranty_categories')->where('id', $id)->update(['name' => $name, 'active' => $activa]);
            // Renombrar se lleva las garantías que la tenían.
            if ($antes !== $name) DB::table('warranties')->where('category', $antes)->update(['category' => $name]);
        });
        return response()->json(['ok' => true, 'id' => $id]);
    }

    private function categoryDelete(Request $request)
    {
        $c = DB::table('warranty_categories')->where('id', (int) $request->input('id'))->first();
        if (! $c) return $this->error('Esa categoría ya no existe');
        $n = DB::table('warranties')->where('category', $c->name)->count();
        if ($n) return $this->error("Hay {$n} " . ($n === 1 ? 'garantía' : 'garantías') . ' con esta categoría: desactívala en lugar de borrarla');
        DB::table('warranty_categories')->where('id', $c->id)->delete();
        return response()->json(['ok' => true]);
    }

    /** Nuevo orden de estados, categorías o plantillas: ids (o claves) en el orden visto. */
    private function order(Request $request)
    {
        [$tabla, $col] = match ((string) $request->input('what')) {
            'statuses'   => ['warranty_statuses', 'key'],
            'categories' => ['warranty_categories', 'id'],
            'templates'  => ['warranty_diagnosis_templates', 'id'],
            default      => [null, null],
        };
        if (! $tabla) return $this->error('Nada que ordenar');
        DB::transaction(function () use ($tabla, $col, $request) {
            foreach (array_values((array) $request->input('ids', [])) as $n => $id) {
                DB::table($tabla)->where($col, $id)->update(['sort' => $n]);
            }
        });
        Warranty::forgetStatuses();
        return response()->json(['ok' => true]);
    }

    /* --- Alertas --- */

    private function alertsSave(Request $request)
    {
        $filas = [];
        foreach ((array) $request->input('rules', []) as $r) {
            $k = (string) ($r['key'] ?? '');
            if (! isset(WarrantyAlerts::RULES[$k])) continue;
            $aviso = max(0, min(365, (int) ($r['warn_days'] ?? 0)));
            $critico = max(0, min(365, (int) ($r['crit_days'] ?? 0)));
            if (WarrantyAlerts::RULES[$k][3]) {
                $aviso = $critico = 0;   // al momento
            } elseif ($critico < $aviso) {
                return $this->error(WarrantyAlerts::RULES[$k][0] . ': los días de crítico no pueden ser menos que los de aviso');
            }
            $filas[$k] = ['active' => ! empty($r['active']), 'warn_days' => $aviso, 'crit_days' => $critico];
        }
        foreach ($filas as $k => $f) {
            DB::table('warranty_alert_rules')->updateOrInsert(['key' => $k], $f);
        }
        return response()->json(['ok' => true]);
    }

    /* --- Plantillas de diagnóstico --- */

    private function templateSave(Request $request)
    {
        $id = (int) $request->input('id');
        $title = trim((string) $request->input('title', ''));
        $body = trim((string) $request->input('body', ''));
        if ($title === '' || mb_strlen($title) > 120) return $this->error('Escribe el título (hasta 120 letras)');
        if ($body === '') return $this->error('Escribe el texto de la plantilla');
        $datos = ['title' => $title, 'body' => mb_substr($body, 0, 2000), 'active' => $request->boolean('active', true), 'updated_at' => now()];
        if ($id) {
            if (! DB::table('warranty_diagnosis_templates')->where('id', $id)->update($datos)
                && ! DB::table('warranty_diagnosis_templates')->where('id', $id)->exists()) {
                return $this->error('Esa plantilla ya no existe');
            }
        } else {
            $id = DB::table('warranty_diagnosis_templates')->insertGetId($datos + ['created_at' => now(),
                'sort' => (int) DB::table('warranty_diagnosis_templates')->max('sort') + 1]);
        }
        return response()->json(['ok' => true, 'id' => $id]);
    }

    private function templateDelete(Request $request)
    {
        DB::table('warranty_diagnosis_templates')->where('id', (int) $request->input('id'))->delete();
        return response()->json(['ok' => true]);
    }

    /* --- Tarifa y documentos --- */

    /**
     * Lo que se puede tarifar y aún no tiene precio: referencias que salen en las
     * garantías (con cuántos dispositivos) y tipos de pila del catálogo.
     */
    private function pistasTarifa(): array
    {
        $con = DB::table('warranty_prices')->get(['kind', 'ref'])->map(fn ($p) => $p->kind . '|' . strtoupper($p->ref))->flip();
        $productos = DB::table('warranty_items')->whereNotNull('aeme_ref')->where('aeme_ref', '!=', '')
            ->groupBy('aeme_ref')->selectRaw('aeme_ref, max(product_name) as name, max(kind) as kind, count(*) as n')->orderByDesc('n')->get()
            ->reject(fn ($r) => isset($con['product|' . strtoupper($r->aeme_ref)]))
            ->map(fn ($r) => ['kind' => 'product', 'ref' => strtoupper($r->aeme_ref), 'label' => $r->name, 'used' => (int) $r->n])->values();
        $pilas = DB::table('device_models')->whereNotNull('battery_type')->where('battery_type', '!=', '')->distinct()->pluck('battery_type')
            ->map(fn ($t) => strtoupper($t))->unique()->reject(fn ($t) => isset($con["battery|{$t}"]))
            ->map(fn ($t) => ['kind' => 'battery', 'ref' => $t, 'label' => "Pila {$t}", 'used' => null])->values();
        return $productos->concat($pilas)->all();
    }

    private function priceSave(Request $request)
    {
        $kind = (string) $request->input('kind');
        if (! in_array($kind, ['product', 'battery', 'service'], true)) return $this->error('Tipo no válido');
        $ref = trim((string) $request->input('ref', ''));
        $ref = $kind === 'service' ? Str::slug($ref ?: (string) $request->input('label', ''), '_') : strtoupper($ref);
        if ($ref === '' || mb_strlen($ref) > 80) return $this->error($kind === 'service' ? 'Escribe el concepto' : 'Escribe la referencia');
        $precio = str_replace(',', '.', trim((string) $request->input('price', '')));
        if (! is_numeric($precio) || (float) $precio < 0 || (float) $precio > 999999) return $this->error('Escribe un precio válido (0 o más)');

        $id = (int) $request->input('id');
        $repe = DB::table('warranty_prices')->where('kind', $kind)->where('ref', $ref)->where('id', '!=', $id)->exists();
        if ($repe) return $this->error('Esa referencia ya tiene precio');
        $datos = ['kind' => $kind, 'ref' => $ref, 'label' => ($l = trim((string) $request->input('label', ''))) !== '' ? mb_substr($l, 0, 160) : null,
            'price' => round((float) $precio, 2), 'updated_at' => now()];
        $id ? DB::table('warranty_prices')->where('id', $id)->update($datos)
            : DB::table('warranty_prices')->insert($datos + ['created_at' => now()]);
        return response()->json(['ok' => true]);
    }

    private function priceDelete(Request $request)
    {
        DB::table('warranty_prices')->where('id', (int) $request->input('id'))->delete();
        return response()->json(['ok' => true]);
    }

    private function docsSave(Request $request)
    {
        $t = fn ($v, $max) => mb_substr(trim((string) $v), 0, $max);
        $c = (array) $request->input('company', []);
        $d = (array) $request->input('documents', []);
        $e = (array) $request->input('resolution_email', []);
        if (trim((string) ($c['name'] ?? '')) === '') return $this->error('Escribe el nombre de la empresa');
        if (trim((string) ($e['subject'] ?? '')) === '' || trim((string) ($e['body'] ?? '')) === '') return $this->error('El correo de resolución necesita asunto y texto');

        $valores = [
            'company' => ['name' => $t($c['name'], 120), 'tax_id' => $t($c['tax_id'] ?? '', 30), 'address' => $t($c['address'] ?? '', 300),
                'email' => $t($c['email'] ?? '', 120), 'phone' => $t($c['phone'] ?? '', 40)],
            'documents' => ['vat' => round(max(0, min(100, (float) ($d['vat'] ?? 21))), 2),
                'validity_days' => max(0, min(365, (int) ($d['validity_days'] ?? 30))), 'conditions' => $t($d['conditions'] ?? '', 3000)],
            'resolution_email' => ['subject' => $t($e['subject'], 200), 'body' => $t($e['body'], 5000)],
        ];
        foreach ($valores as $k => $v) {
            DB::table('warranty_settings')->updateOrInsert(['key' => $k], ['value' => json_encode($v), 'updated_at' => now()]);
        }
        return response()->json(['ok' => true]);
    }
}
