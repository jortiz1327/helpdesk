<?php

namespace App\Services;

use App\Models\DeviceSighting;
use App\Models\Warranty;
use App\Models\WarrantyItem;
use App\Models\ZkongServer;
use App\Services\Zkong\ZkongDevices;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Los dispositivos de una garantía (etiquetas y antenas), como en la app de garantías
 * pero sin fotos: el agente solo da los códigos y aquí se comprueba cada uno en todos los
 * servidores ZKONG (dónde está, si está vivo, qué modelo dice el servidor), se saca de
 * ese modelo el producto del portal (referencia AEME) y se avisa de lo raro: que ya esté
 * en otra garantía abierta (eso lo bloquea), que no aparezca, que esté en otro cliente…
 *
 * Si el modelo del servidor no está en el catálogo, se queda ese modelo de ZKONG y el
 * producto se puede elegir entre todas las etiquetas y antenas del portal.
 */
class WarrantyLabels
{
    public const PATTERN = '/^[A-Z0-9]{9}$/';           // etiqueta
    public const AP_PATTERN = '/^[0-9A-F]{12}$/';       // antena (MAC, sin separadores)
    public const AP_REF = 'AMAP-C';                     // la antena en el portal
    public const MAX = 300;            // códigos por comprobación
    public const RECENT_MONTHS = 12;
    public const SILENT_DAYS = 30;
    private const CACHE_MIN = 15;      // lo que dijo el servidor, para no repetir al guardar

    public function __construct(private ZkongDevices $devices)
    {
    }

    /**
     * Lo pegado (una por línea, o separadas por espacios, comas…) → [válidos, no válidos],
     * sin repetidos y en mayúsculas.
     */
    public static function parse(string|array $input): array
    {
        $tokens = is_array($input) ? $input : (preg_split('/[\s,;]+/', $input) ?: []);
        $ok = $bad = [];
        foreach ($tokens as $t) {
            $c = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $t));
            if ($c === '') {
                continue;
            }
            if (preg_match(self::PATTERN, $c) || preg_match(self::AP_PATTERN, $c)) {
                in_array($c, $ok, true) || $ok[] = $c;
            } else {
                in_array($c, $bad, true) || $bad[] = $c;
            }
        }
        return [$ok, $bad];
    }

    /**
     * Comprueba los códigos. $context: warranty_id (para no contarse a sí misma), client y
     * project (para avisar si la etiqueta está en una tienda que no parece la suya).
     */
    public function check(array $codes, array $context = [], ?int $userId = null, bool $fresh = false): array
    {
        $look = $this->lookup($codes, $userId, $fresh);
        $models = $this->models();
        $refs = [];
        $out = [];
        foreach ($codes as $code) {
            $l = $look[$code];
            $ref = $l['kind'] === 'esl' && $l['server_model'] ? $this->matchReference($l['server_model'], self::canBeFourColour($l['rows']), $models) : null;
            // Antena: los servidores no dan su modelo; en el portal es AMAP-C (se puede cambiar).
            $aeme = $ref ? $this->aemeOf($ref, $models) : ($l['kind'] === 'ap' ? self::AP_REF : null);
            $out[$code] = $l + [
                'code'            => $code,
                'device_model_id' => $ref['device_model_id'] ?? null,
                'aeme_ref'        => $aeme,
                'model_manual'    => false,
            ];
            $aeme && $refs[] = $aeme;
        }
        $items = AemePortal::items($refs);
        foreach ($out as $code => &$r) {
            $r['product_name'] = $r['aeme_ref'] ? ($items[$r['aeme_ref']]['name'] ?? null) : null;
            $r['warnings'] = $this->warnings($r, $context, $models);
            $r['blocked'] = collect($r['warnings'])->contains('blocks', true);
        }
        return array_values($out);
    }

    /**
     * Añade etiquetas a una garantía (las bloqueadas y las que ya tiene no entran).
     * $manual: código => referencia AEME elegida a mano.
     *
     * @return array{added: array<int, string>, skipped: array<int, array{code: string, reason: string}>}
     */
    public function add(Warranty $w, array $codes, array $manual, ?int $userId): array
    {
        $ya = WarrantyItem::where('warranty_id', $w->id)->pluck('code')->all();
        $added = $skipped = [];
        $results = $this->check($codes, self::contextOf($w), $userId);
        $models = $this->models();
        foreach ($results as $r) {
            if (in_array($r['code'], $ya, true)) {
                $skipped[] = ['code' => $r['code'], 'reason' => 'Ya estaba en esta garantía'];
                continue;
            }
            if ($r['blocked']) {
                $skipped[] = ['code' => $r['code'], 'reason' => collect($r['warnings'])->firstWhere('blocks', true)['text']];
                continue;
            }
            $elegida = strtoupper(trim((string) ($manual[$r['code']] ?? '')));
            if ($elegida !== '' && $elegida !== $r['aeme_ref'] && ($m = $this->byAeme($elegida, $models))) {
                $r = $this->conModelo($r, $m, $models);
                $r['warnings'] = $this->warnings($r, self::contextOf($w), $models);
            }
            WarrantyItem::create($this->fila($r) + ['warranty_id' => $w->id, 'created_by' => $userId]);
            $added[] = $r['code'];
        }
        return ['added' => $added, 'skipped' => $skipped];
    }

    /** Cambia a mano el modelo (producto) de una etiqueta. */
    public function setModel(WarrantyItem $item, string $aemeRef): bool
    {
        $models = $this->models();
        $m = $this->byAeme(strtoupper(trim($aemeRef)), $models);
        if (! $m) {
            return false;
        }
        $r = $this->conModelo($this->desdeFila($item), $m, $models);
        $w = Warranty::find($item->warranty_id);
        $r['warnings'] = $this->warnings($r, $w ? self::contextOf($w) : [], $models);
        $item->fill($this->fila($r))->save();
        return true;
    }

    /** Vuelve a preguntar a los servidores por todas las etiquetas (el modelo elegido a mano se respeta). */
    public function recheck(Warranty $w, ?int $userId): void
    {
        $items = WarrantyItem::where('warranty_id', $w->id)->get();
        if ($items->isEmpty()) {
            return;
        }
        $models = $this->models();
        $results = collect($this->check($items->pluck('code')->all(), self::contextOf($w), $userId, true))->keyBy('code');
        foreach ($items as $item) {
            $r = $results[$item->code];
            if ($item->model_manual && $item->aeme_ref && ($m = $this->byAeme($item->aeme_ref, $models))) {
                $r = $this->conModelo($r, $m, $models);
                $r['warnings'] = $this->warnings($r, self::contextOf($w), $models);
            }
            $item->fill($this->fila($r))->save();
        }
    }

    /**
     * Productos para elegir a mano: todas las etiquetas y antenas del portal, con la
     * descripción del catálogo cuando la tienen. Sin portal, solo las del catálogo.
     * [ref, kind, description, product_name]
     */
    public function catalog(): array
    {
        $models = $this->models();
        $refs = collect($models['refs'])->where('provider', 'aeme')->keyBy(fn ($r) => strtoupper($r['name']));
        $desc = fn ($ref) => isset($refs[$ref]) ? self::describe($models['models'][$refs[$ref]['device_model_id']] ?? null) : '';
        $portal = AemePortal::products();
        $lista = $portal !== null
            ? collect($portal)->map(fn ($p) => ['ref' => $p['ref'], 'kind' => $p['kind'], 'description' => $desc($p['ref']), 'product_name' => $p['name']])
            : $refs->map(fn ($r, $ref) => ['ref' => $r['name'], 'kind' => 'esl', 'description' => $desc($ref), 'product_name' => null])->values();
        return $lista->sortBy('ref', SORT_NATURAL)->values()->all();
    }

    public static function contextOf(Warranty $w): array
    {
        return ['warranty_id' => $w->id, 'client' => $w->client_name, 'project' => $w->project_name];
    }

    /* ---------------------------------------------------------------- */

    /** Lo que dicen los servidores de cada código (15 min en caché salvo $fresh). */
    private function lookup(array $codes, ?int $userId, bool $fresh): array
    {
        $out = [];
        $faltan = [];
        foreach ($codes as $code) {
            $hit = $fresh ? null : Cache::get("warranty_label:{$code}");
            $hit ? $out[$code] = $hit : $faltan[] = $code;
        }
        if ($faltan) {
            $res = $this->devices->labels($faltan, $userId);
            $sinServidores = $this->devices->servers()->isEmpty();
            foreach ($faltan as $code) {
                $l = $res['labels'][$code] ?? ['rows' => [], 'live' => null];
                $v = $l['live'];
                $state = $v ? 'found' : ($res['failed'] || $sinServidores ? 'failed' : 'not_found');
                $kind = self::kindOf($code);
                $ap = $kind === 'ap';
                // Antena: conectada ahora o desde cuándo no; etiqueta: su última comunicación.
                $vivo = fn ($x) => (int) ($x[$ap ? 'online' : 'state'] ?? 0) === 1;
                $ultima = ! $v ? null : ($ap ? ($vivo($v) ? now() : self::fecha($v['offLineTime'] ?? null)) : self::fecha($v['lastCommuTime'] ?? null));
                $out[$code] = [
                    'kind'          => $kind,
                    'lookup_state'  => $state,
                    'failed'        => $res['failed'],
                    'server'        => $v['server'] ?? null,
                    'store_name'    => $v['storeName'] ?? null,
                    'merchant_name' => ($v['merchantName'] ?? null) ?: null,
                    'online'        => $v ? $vivo($v) : null,
                    'last_commu_at' => $ultima?->toDateTimeString(),
                    'server_model'  => $v && trim((string) ($v['model'] ?? '')) !== '' ? trim((string) $v['model']) : null,
                    'rows'          => array_map(fn ($x) => [
                        'server' => $x['server'] ?? null, 'url' => $x['server_url'] ?? '',
                        'online' => $vivo($x), 'store' => $x['storeName'] ?? null,
                    ], $l['rows']),
                ];
                if ($state !== 'failed') {
                    Cache::put("warranty_label:{$code}", $out[$code], now()->addMinutes(self::CACHE_MIN));
                }
            }
        }
        return $out;
    }

    /** Catálogo en memoria: modelos por id y todas las referencias. */
    private function models(): array
    {
        return [
            'models' => DB::table('device_models')->get()->keyBy('id')->map(fn ($m) => (array) $m)->all(),
            'refs'   => DB::table('device_model_references')->get()->map(fn ($r) => (array) $r)->all(),
        ];
    }

    /**
     * La referencia ZKONG cuyo nombre empieza por el modelo que da el servidor (o al revés):
     * el servidor dice «ZKC42V-N» y la referencia es «ZKC42V-NE4 (White)». Con varias, la
     * de longitud más parecida. Las de cuatro colores solo viven en app02.
     */
    private function matchReference(string $serverModel, bool $allowFour, array $models): ?array
    {
        $wanted = self::normalize($serverModel);
        if ($wanted === '') {
            return null;
        }
        return collect($models['refs'])->where('provider', 'zkong')
            ->reject(fn ($r) => ! $allowFour && ($models['models'][$r['device_model_id']]['eink_colors'] ?? null) === 'BWRY')
            ->map(function ($r) use ($wanted) {
                $n = self::normalize($r['name']);
                return $n === $wanted || str_starts_with($n, $wanted) || str_starts_with($wanted, $n) ? $r + ['dist' => abs(strlen($n) - strlen($wanted))] : null;
            })
            ->filter()->sortBy('dist')->first();
    }

    /** La referencia AEME de una ZKONG: su pareja o, si no tiene, la primera AEME del modelo. */
    private function aemeOf(array $zk, array $models): ?string
    {
        $refs = collect($models['refs']);
        $pair = $zk['pair_id'] ? $refs->firstWhere('id', $zk['pair_id']) : null;
        if ($pair && $pair['provider'] === 'aeme') {
            return $pair['name'];
        }
        return $refs->where('provider', 'aeme')->where('device_model_id', $zk['device_model_id'])->first()['name'] ?? null;
    }

    /** Lo elegido a mano: una referencia del catálogo o cualquier etiqueta o antena del portal. */
    private function byAeme(string $ref, array $models): ?array
    {
        $cat = collect($models['refs'])->where('provider', 'aeme')->first(fn ($r) => strtoupper($r['name']) === $ref);
        if ($cat) {
            return $cat;
        }
        $p = collect(AemePortal::products() ?? [])->firstWhere('ref', $ref);
        return $p ? ['name' => $p['ref'], 'device_model_id' => null, 'product_name' => $p['name']] : null;
    }

    private function conModelo(array $r, array $aeme, array $models): array
    {
        $r['device_model_id'] = $aeme['device_model_id'];
        $r['aeme_ref'] = $aeme['name'];
        $r['product_name'] = $aeme['product_name'] ?? AemePortal::items([$aeme['name']])[strtoupper($aeme['name'])]['name'] ?? null;
        $r['model_manual'] = true;
        // Lo que habría salido solo, para avisar si se eligió otro modelo.
        $auto = $r['kind'] === 'esl' && $r['server_model'] ? $this->matchReference($r['server_model'], self::canBeFourColour($r['rows']), $models) : null;
        $r['auto_model_id'] = $auto['device_model_id'] ?? null;
        return $r;
    }

    /** Avisos de una etiqueta. Solo bloquea estar ya en otra garantía abierta. */
    private function warnings(array $r, array $ctx, array $models): array
    {
        $w = [];
        $aviso = function (string $key, string $tone, string $text, bool $blocks = false) use (&$w) {
            $w[] = ['key' => $key, 'tone' => $tone, 'text' => $text, 'blocks' => $blocks];
        };

        $otras = DB::table('warranty_items as i')->join('warranties as g', 'g.id', '=', 'i.warranty_id')
            ->where('i.code', $r['code'])
            ->when($ctx['warranty_id'] ?? null, fn ($q, $id) => $q->where('g.id', '!=', $id))
            ->orderByDesc('g.received_at')
            ->get(['g.id', 'g.code', 'g.status', 'g.received_at', 'g.closed_at']);

        // 1. Ya está en otra garantía abierta.
        if ($abierta = $otras->firstWhere(fn ($g) => $g->status !== 'cerrada')) {
            $aviso('open_warranty', 'bad', "Ya está en la garantía abierta {$abierta->code} (" . (Warranty::STATUSES[$abierta->status] ?? $abierta->status) . '): no puede entrar hasta que esa se cierre.', true);
        }
        // 2. Varias garantías en el último año.
        $recientes = $otras->filter(fn ($g) => $g->received_at && Carbon::parse($g->received_at)->gte(now()->subMonths(self::RECENT_MONTHS)));
        if ($recientes->count() >= 2) {
            $aviso('recent_warranties', 'warn', $recientes->count() . ' garantías en los últimos ' . self::RECENT_MONTHS . ' meses: ' . $recientes->pluck('code')->join(', ') . '.');
        } elseif (! $abierta && ($antes = $otras->first())) {
            $aviso('previous_warranty', 'info', "Ya pasó por la garantía {$antes->code}" . ($antes->closed_at ? ', cerrada el ' . Carbon::parse($antes->closed_at)->format('d/m/Y') : '') . '.');
        }

        // 3. Dónde está.
        if ($r['lookup_state'] === 'not_found') {
            $aviso('not_found', 'warn', 'No aparece en ningún servidor: revisa el código.');
        } elseif ($r['lookup_state'] === 'failed') {
            $aviso('failed', 'warn', $r['failed'] ? 'No se pudo consultar ' . implode(', ', $r['failed']) . '.' : 'No hay servidores ZKONG configurados.');
        }
        $lugar = trim(($r['store_name'] ?? '') . ' ' . ($r['merchant_name'] ?? ''));
        $suyas = self::words(implode(' ', array_filter([$ctx['project'] ?? null, $ctx['client'] ?? null])));
        if ($lugar !== '' && $suyas && ! array_intersect(self::words($lugar), $suyas)) {
            $aviso('other_client', 'warn', "En los servidores está en «{$r['store_name']}" . ($r['merchant_name'] ? " · {$r['merchant_name']}" : '') . '», que no parece de este cliente.');
        }
        $conectada = array_filter($r['rows'], fn ($x) => $x['online']);
        if (count($conectada) >= 2) {
            $aviso('both_servers', 'warn', 'Conectada en ' . implode(' y ', array_column($conectada, 'server')) . ' a la vez: puede que una migración de tienda esté a medias.');
        }
        if ($r['kind'] === 'ap' && $r['lookup_state'] === 'found' && ! $r['online']) {
            $aviso('ap_offline', 'warn', 'Antena desconectada' . ($r['last_commu_at'] ? ' desde el ' . Carbon::parse($r['last_commu_at'])->format('d/m/Y') : '') . '.');
        } elseif ($r['last_commu_at'] && Carbon::parse($r['last_commu_at'])->lt(now()->subDays(self::SILENT_DAYS))) {
            $c = Carbon::parse($r['last_commu_at']);
            $aviso('silent', 'warn', 'Sin comunicar desde el ' . $c->format('d/m/Y') . ' (' . (int) $c->diffInDays(now()) . ' días).');
        }

        // 4. El modelo.
        if ($r['lookup_state'] === 'found' && ! $r['aeme_ref']) {
            $aviso('no_model', 'info', $r['server_model']
                ? "«{$r['server_model']}» no está en el catálogo: se queda ese modelo de ZKONG. Si quieres, elige el producto del portal."
                : 'El servidor no dice qué modelo es: elige el producto del portal.');
        }
        if (! empty($r['model_manual']) && ! empty($r['auto_model_id']) && (int) $r['auto_model_id'] !== (int) $r['device_model_id']) {
            $aviso('model_changed', 'warn', "El servidor dice «{$r['server_model']}»; el modelo elegido a mano es otro.");
        }
        $colores = $r['kind'] === 'esl' && $r['device_model_id'] ? ($models['models'][$r['device_model_id']]['eink_colors'] ?? null) : null;
        if ($colores === 'BWRY' && $r['rows'] && ! self::canBeFourColour($r['rows'])) {
            $aviso('four_colour_on_app', 'bad', 'Es un modelo de cuatro colores, pero solo responde desde ' . implode(', ', array_column($r['rows'], 'server')) . ': esos solo viven en app02.');
        }
        return $w;
    }

    public static function kindOf(string $code): string
    {
        return preg_match(self::PATTERN, $code) ? 'esl' : 'ap';
    }

    private static function fecha(mixed $v): ?Carbon
    {
        if ($v === null || $v === '') {
            return null;
        }
        try {
            return is_numeric($v) ? Carbon::createFromTimestampMs((int) $v) : Carbon::parse((string) $v);
        } catch (Throwable) {
            return null;
        }
    }

    /** Las de cuatro colores solo viven en app02. */
    private static function canBeFourColour(array $rows): bool
    {
        return ! $rows || collect($rows)->contains(fn ($x) => Str::contains(strtolower(($x['url'] ?? '') . ' ' . ($x['server'] ?? '')), 'app02'));
    }

    /** «ZKC42V-NE4 (White)» → «ZKC42VNE4». */
    public static function normalize(string $name): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', preg_replace('/\s*\(.*?\)\s*/', '', $name)));
    }

    /** Palabras que distinguen un sitio de otro (sin acentos, ni cortas, ni genéricas). */
    public static function words(string $text): array
    {
        $generic = ['hotel', 'hotels', 'farmacia', 'pharmacy', 'garantia', 'material', 'proyecto', 'comanda', 'tienda', 'store', 'apartamentos', 'resort', 'club', 'group', 'grupo', 'robotics', 'suites', 'beach', 'playa', 'puerto'];
        $words = preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii($text))) ?: [];
        return array_values(array_unique(array_filter($words, fn ($w) => strlen($w) >= 4 && ! in_array($w, $generic, true))));
    }

    /** «4,2" · Tricolor · Blanco» */
    public static function describe(?array $m): string
    {
        if (! $m) {
            return '';
        }
        $colores = ['BW' => 'Blanco y negro', 'BWR' => 'Tricolor', 'BWRY' => 'Cuatro colores'];
        $color = ['white' => 'Blanco', 'black' => 'Negro'];
        return implode(' · ', array_filter([
            $m['size'] ? str_replace('.', ',', rtrim(rtrim((string) $m['size'], '0'), '.')) . '"' : null,
            $colores[$m['eink_colors']] ?? $m['eink_colors'],
            $color[$m['color']] ?? ($m['color'] ? ucfirst($m['color']) : null),
            $m['variant'] ?: null,
            $m['has_batteries'] && $m['battery_type'] ? $m['battery_type'] . ($m['battery_quantity'] > 1 ? ' ×' . $m['battery_quantity'] : '') : null,
        ]));
    }

    /** Lo que se guarda de un resultado. */
    private function fila(array $r): array
    {
        return [
            'code' => $r['code'], 'kind' => $r['kind'], 'lookup_state' => $r['lookup_state'], 'server' => $r['server'],
            'store_name' => $r['store_name'], 'merchant_name' => $r['merchant_name'], 'online' => $r['online'],
            'last_commu_at' => $r['last_commu_at'], 'server_model' => $r['server_model'],
            'device_model_id' => $r['device_model_id'], 'aeme_ref' => $r['aeme_ref'], 'product_name' => $r['product_name'],
            'model_manual' => (bool) $r['model_manual'], 'warnings' => $r['warnings'] ?: null, 'checked_at' => now(),
        ];
    }

    private function desdeFila(WarrantyItem $i): array
    {
        return [
            'code' => $i->code, 'kind' => $i->kind, 'lookup_state' => $i->lookup_state, 'failed' => [], 'server' => $i->server,
            'store_name' => $i->store_name, 'merchant_name' => $i->merchant_name, 'online' => $i->online,
            'last_commu_at' => $i->last_commu_at?->toDateTimeString(), 'server_model' => $i->server_model,
            'device_model_id' => $i->device_model_id, 'aeme_ref' => $i->aeme_ref, 'product_name' => $i->product_name,
            'model_manual' => $i->model_manual,
            // Las filas por servidor no se guardan: el servidor vivo basta para el aviso de app02.
            'rows' => $i->server ? [['server' => $i->server, 'url' => (string) ZkongServer::where('name', $i->server)->value('base_url'), 'online' => (bool) $i->online, 'store' => $i->store_name]] : [],
        ];
    }
}
