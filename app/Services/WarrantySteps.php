<?php

namespace App\Services;

use App\Models\Warranty;
use Illuminate\Support\Facades\DB;

/**
 * Pasos por tipo de cliente (Configuración): listas de pasos que se marcan en cada
 * garantía. Una lista vale para todas, para unas categorías (Hoteles) o para clientes
 * cuyo nombre o proyecto contiene un texto (Expofarm). A una garantía le tocan todas las
 * que coinciden, en el orden de Configuración.
 */
class WarrantySteps
{
    public const MAX_LISTS = 20;
    public const MAX_STEPS = 30;

    // De serie, sacados de los procedimientos de garantías: se cambian en Configuración.
    public const DEFAULTS = [
        ['id' => 'general', 'name' => 'Todas', 'active' => true, 'all' => true, 'categories' => [], 'clients' => [], 'steps' => [
            ['id' => 'g1', 'text' => 'Comprobar las etiquetas en el Cloud', 'auto' => 'cloud'],
            ['id' => 'g2', 'text' => 'Revisar los dispositivos y hacer las fotos', 'auto' => 'revision'],
            ['id' => 'g3', 'text' => 'Avisar a Finanzas de los presupuestos'],
            ['id' => 'g4', 'text' => 'Enviar el correo de resolución'],
        ]],
        ['id' => 'hoteles', 'name' => 'Hoteles', 'active' => true, 'all' => false, 'categories' => ['Hoteles'], 'clients' => [], 'steps' => [
            ['id' => 'h1', 'text' => 'Pasar las posiciones de las etiquetas sustituidas'],
        ]],
        ['id' => 'expofarm', 'name' => 'Expofarm', 'active' => true, 'all' => false, 'categories' => [], 'clients' => ['Expofarm'], 'steps' => [
            ['id' => 'e1', 'text' => 'Postman'],
            ['id' => 'e2', 'text' => 'Actualizar el firmware'],
            ['id' => 'e3', 'text' => 'Reiniciar el contador de cambios'],
        ]],
    ];

    public static function settings(): array
    {
        // Guardado como {lists: [...]}: así «sin ninguna lista» no vuelve a las de serie.
        $v = \App\Models\WarrantyDocument::setting('checklists');
        return array_key_exists('lists', $v) ? (array) $v['lists'] : self::DEFAULTS;
    }

    /** Si la lista le toca a esta garantía. */
    public static function applies(array $l, Warranty $w): bool
    {
        if (empty($l['active']) || empty($l['steps'])) return false;
        if (! empty($l['all'])) return true;
        $cat = mb_strtolower(trim((string) $w->category));
        if ($cat !== '' && collect($l['categories'] ?? [])->contains(fn ($c) => mb_strtolower(trim($c)) === $cat)) return true;
        $quien = mb_strtolower($w->client_name . ' ' . $w->project_name);
        return collect($l['clients'] ?? [])->contains(fn ($c) => ($c = mb_strtolower(trim($c))) !== '' && str_contains($quien, $c));
    }

    /** Las listas de la garantía con lo marcado: [{id, name, steps: [{id, text, done_at, done_by}]}]. */
    public static function forWarranty(Warranty $w): array
    {
        $hechos = DB::table('warranty_step_checks as c')->leftJoin('users as u', 'u.id', '=', 'c.user_id')
            ->where('c.warranty_id', $w->id)->get(['c.list_id', 'c.step_id', 'c.checked_at', 'u.name'])
            ->keyBy(fn ($c) => "{$c->list_id}|{$c->step_id}");
        $out = [];
        foreach (self::settings() as $l) {
            if (! self::applies($l, $w)) continue;
            $out[] = ['id' => $l['id'], 'name' => $l['name'], 'steps' => array_map(function ($s) use ($l, $hechos) {
                $h = $hechos["{$l['id']}|{$s['id']}"] ?? null;
                return ['id' => $s['id'], 'text' => $s['text'], 'auto' => self::autoDe($l, $s),
                    'done_at' => $h ? (string) $h->checked_at : null, 'done_by' => $h ? ($h->name ?? 'Automático') : null];
            }, $l['steps'])];
        }
        return $out;
    }

    public const AUTOS = ['cloud' => 'Al comprobar las etiquetas en el Cloud', 'revision' => 'Al revisar todas las etiquetas'];

    /*
     * Qué marca solo un paso: 'cloud' (se comprueban las etiquetas en el Cloud) o 'revision'
     * (todas revisadas). Los dos primeros de «Todas» lo traen de serie aunque la lista se
     * guardara antes de existir esto.
     */
    private static function autoDe(array $l, array $s): ?string
    {
        if (array_key_exists('auto', $s)) return isset(self::AUTOS[$s['auto']]) ? $s['auto'] : null;
        return $l['id'] === 'general' ? (['g1' => 'cloud', 'g2' => 'revision'][$s['id']] ?? null) : null;
    }

    /**
     * Marca solos los pasos de ese disparador que aún no están hechos (con una línea en el
     * historial). Con 'revision' solo si todas las etiquetas tienen ya su revisión.
     */
    public static function auto(Warranty $w, string $trigger, ?int $userId): void
    {
        if ($trigger === 'revision') {
            $q = DB::table('warranty_items')->where('warranty_id', $w->id);
            if (! (clone $q)->exists() || (clone $q)->where('resolution', 'pendiente')->exists()) return;
        }
        foreach (self::settings() as $l) {
            if (! self::applies($l, $w)) continue;
            foreach ($l['steps'] as $s) {
                if (self::autoDe($l, $s) !== $trigger) continue;
                $clave = ['warranty_id' => $w->id, 'list_id' => $l['id'], 'step_id' => $s['id']];
                if (DB::table('warranty_step_checks')->where($clave)->exists()) continue;
                DB::table('warranty_step_checks')->insert($clave + ['user_id' => $userId, 'checked_at' => now()]);
                DB::table('warranty_events')->insert(['warranty_id' => $w->id, 'user_id' => $userId, 'field' => 'steps',
                    'from_value' => null, 'to_value' => mb_substr("✓ {$s['text']} ({$l['name']}) · automático", 0, 500), 'created_at' => now()]);
            }
        }
    }

    /** Marca o desmarca un paso (deja línea en el historial). Error en texto, o null. */
    public static function toggle(Warranty $w, string $listId, string $stepId, bool $done, int $userId): ?string
    {
        $l = collect(self::settings())->firstWhere('id', $listId);
        $s = $l ? collect($l['steps'])->firstWhere('id', $stepId) : null;
        if (! $s || ! self::applies($l, $w)) return 'Ese paso ya no está en la lista de esta garantía';
        $clave = ['warranty_id' => $w->id, 'list_id' => $listId, 'step_id' => $stepId];
        $habia = DB::table('warranty_step_checks')->where($clave)->exists();
        if ($habia === $done) return null;
        $done ? DB::table('warranty_step_checks')->insert($clave + ['user_id' => $userId, 'checked_at' => now()])
            : DB::table('warranty_step_checks')->where($clave)->delete();
        DB::table('warranty_events')->insert(['warranty_id' => $w->id, 'user_id' => $userId, 'field' => 'steps',
            'from_value' => null, 'to_value' => mb_substr(($done ? '✓ ' : 'Desmarcado: ') . "{$s['text']} ({$l['name']})", 0, 500), 'created_at' => now()]);
        Warranty::whereKey($w->id)->update(['last_activity_at' => now()]);
        return null;
    }

    /** Lo de Configuración, validado. [listas, error]. */
    public static function clean(array $in, array $categorias): array
    {
        if (count($in) > self::MAX_LISTS) return [null, 'Como mucho ' . self::MAX_LISTS . ' listas'];
        $cats = collect($categorias)->mapWithKeys(fn ($n) => [mb_strtolower($n) => $n]);
        $out = [];
        $ids = [];
        foreach ($in as $l) {
            $nombre = mb_substr(trim((string) ($l['name'] ?? '')), 0, 60);
            if ($nombre === '') return [null, 'Cada lista necesita un nombre'];
            $id = preg_match('/^[a-z0-9_-]{1,20}$/', (string) ($l['id'] ?? '')) ? $l['id'] : substr('l' . bin2hex(random_bytes(6)), 0, 13);
            if (isset($ids[$id])) $id = substr('l' . bin2hex(random_bytes(6)), 0, 13);
            $ids[$id] = true;
            $lc = [];
            foreach ((array) ($l['categories'] ?? []) as $c) {
                $n = $cats[mb_strtolower(trim((string) $c))] ?? null;
                if (! $n) return [null, "«{$nombre}»: la categoría «{$c}» no existe"];
                $lc[$n] = $n;
            }
            $clientes = array_values(array_unique(array_filter(array_map(fn ($c) => mb_substr(trim((string) $c), 0, 60), (array) ($l['clients'] ?? [])))));
            $todas = ! empty($l['all']);
            if (! $todas && ! $lc && ! $clientes) return [null, "«{$nombre}»: elige a qué garantías se aplica (todas, categorías o clientes)"];
            $pasos = [];
            $pids = [];
            foreach ((array) ($l['steps'] ?? []) as $s) {
                $t = mb_substr(trim((string) ($s['text'] ?? '')), 0, 160);
                if ($t === '') continue;
                $pid = preg_match('/^[a-z0-9_-]{1,20}$/', (string) ($s['id'] ?? '')) && ! isset($pids[$s['id']]) ? $s['id'] : substr('s' . bin2hex(random_bytes(6)), 0, 13);
                $pids[$pid] = true;
                $pasos[] = ['id' => $pid, 'text' => $t] + (array_key_exists('auto', $s) ? ['auto' => isset(self::AUTOS[$s['auto']]) ? $s['auto'] : null] : []);
            }
            if (! $pasos) return [null, "«{$nombre}» no tiene pasos"];
            if (count($pasos) > self::MAX_STEPS) return [null, "«{$nombre}»: como mucho " . self::MAX_STEPS . ' pasos'];
            $out[] = ['id' => $id, 'name' => $nombre, 'active' => ! empty($l['active']), 'all' => $todas,
                'categories' => $todas ? [] : array_values($lc), 'clients' => $todas ? [] : $clientes, 'steps' => $pasos];
        }
        return [$out, null];
    }
}
