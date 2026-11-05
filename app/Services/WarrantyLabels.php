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
use Throwable;

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
    private const NOT_FOUND_MIN = 15;  // «no aparece», solo para la comprobación del portal
    private const NO_ESTA = 'warranty_label_none:';

    public function __construct(private ZkongDevices $devices)
    {
    }

    /**
     * Lo pegado (una por línea, o separadas por espacios, comas…) → [válidos, no válidos],
     * sin repetidos y en mayúsculas.
     */
    public static function parse(string|array $input): array
    {
        if (is_string($input)) return array_slice(self::parseList($input), 0, 2);
        $tokens = $input;
        $ok = $bad = [];
        foreach ($tokens as $t) {
            if (! is_scalar($t)) continue;   // lo que llega de fuera puede traer listas dentro
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

    private static function limpio(string $t): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $t));
    }

    /** ¿Parece un código (mal copiado)? 6 a 20 letras y números, con alguna cifra. */
    private static function intento(string $t): bool
    {
        $c = self::limpio($t);
        return strlen($c) >= 6 && strlen($c) <= 20 && (bool) preg_match('/\d/', $c);
    }

    /** ¿Es un código de etiqueta o de antena? Devuelve el código limpio o null. */
    private static function codigo(string $t): ?string
    {
        $c = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $t));
        // Con alguna cifra: si no, «pantallas» o «parpadean» (9 letras) pasarían por etiqueta.
        return preg_match('/\d/', $c) && (preg_match(self::PATTERN, $c) || preg_match(self::AP_PATTERN, $c)) ? $c : null;
    }

    /**
     * Lo pegado, también desde Excel (una columna con el código y otra con el problema) →
     * [válidos, no válidos, [código => problema]].
     *   · Una línea solo con códigos (separados por espacios, comas…): todos, sin problema.
     *   · Con código y texto (tabulador, «806301480 - pantalla rota», «…; no enciende»): el
     *     texto es el problema de los códigos de esa línea.
     *   · Sin ningún código (la cabecera «Código | Problema»): se ignora, salvo lo que parezca
     *     un código mal copiado (6 a 20 letras y números con alguna cifra), que sale como no válido.
     */
    public static function parseList(string $text): array
    {
        $ok = $bad = $issues = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $linea) {
            if (trim($linea) === '') continue;
            $celdas = array_map(fn ($c) => trim($c, " \t\"'"), explode("\t", $linea));
            $deLinea = [];
            $textos = [];
            foreach ($celdas as $celda) {
                $resto = $celda;
                $tokens = preg_split('/[\s,;]+/u', $celda, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $codigos = [];
                foreach ($tokens as $t) {
                    if ($c = self::codigo($t)) {
                        $codigos[] = $c;
                        $resto = preg_replace('/' . preg_quote($t, '/') . '/u', ' ', $resto, 1);
                    }
                }
                $deLinea = [...$deLinea, ...$codigos];
                // Lo que no es código: si todo parece un código mal copiado, es eso (no
                // válido); si hay texto, es el problema.
                $otros = array_values(array_filter($tokens, fn ($t) => ! self::codigo($t)));
                if (! $otros) continue;
                if (! array_filter($otros, fn ($t) => ! self::intento($t))) {
                    foreach ($otros as $t) in_array($c = self::limpio($t), $bad, true) || $bad[] = $c;
                    continue;
                }
                $textos[] = $resto;
            }
            if (! $deLinea) {
                // Sin códigos (la cabecera…): solo cuenta lo que parezca un código mal copiado.
                foreach (preg_split('/[\s,;]+/u', $linea, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $t) {
                    if (self::intento($t) && ! in_array($c = self::limpio($t), $bad, true)) $bad[] = $c;
                }
                continue;
            }
            $problema = trim(preg_replace('/\s+/u', ' ', implode(' · ', array_filter(array_map(
                fn ($t) => trim(preg_replace('/^[\s\-–—:|·,;.]+|[\s\-–—:|·,;]+$/u', '', $t)), $textos), fn ($t) => $t !== ''))));
            foreach ($deLinea as $c) {
                in_array($c, $ok, true) || $ok[] = $c;
                if ($problema !== '' && ! isset($issues[$c])) $issues[$c] = mb_substr($problema, 0, 300);
            }
        }
        return [$ok, $bad, $issues];
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

    /**
     * Solo si cada código existe en algún servidor ZKONG (app, app02…): código => true
     * (está), false (no aparece en ninguno) o null (no se pudo consultar). Para el portal:
     * nada de dónde está ni de quién es.
     */
    public function existence(array $codes, ?int $userId = null): array
    {
        if (! $codes) {
            return [];
        }
        // Lo ya sabido no se pregunta. Sin segunda pasada (la del equipo, que da una
        // etiqueta por inexistente solo tras repetir): aquí basta con una, y cuesta la mitad.
        $out = array_map(fn ($c) => self::known($c), array_combine($codes, $codes));
        $faltan = array_keys(array_filter($out, fn ($v) => $v === null));
        if ($faltan) {
            foreach ($this->lookup($faltan, $userId, false, false) as $code => $l) {
                $out[$code] = ['found' => true, 'not_found' => false][$l['lookup_state']] ?? null;
                // El «no aparece» también se recuerda un rato: repetir el mismo código no vuelve a costar.
                if ($out[$code] === false) Cache::put(self::NO_ESTA . $code, true, now()->addMinutes(self::NOT_FOUND_MIN));
            }
        }
        return $out;
    }

    /** Lo que ya se sabe de un código sin preguntar: true (está), false (no aparece) o null. */
    public static function known(string $code): ?bool
    {
        if ((Cache::get("warranty_label:{$code}")['lookup_state'] ?? null) === 'found') return true;
        return Cache::has(self::NO_ESTA . $code) ? false : null;
    }

    /** Batería (%) de una etiqueta según su última comprobación en caché, o null. No pregunta. */
    public static function knownBattery(string $code): ?int
    {
        $c = Cache::get("warranty_label:{$code}");
        return ($c['lookup_state'] ?? null) === 'found' && ($c['kind'] ?? null) === 'esl' && isset($c['battery']) ? (int) $c['battery'] : null;
    }

    public static function contextOf(Warranty $w): array
    {
        return ['warranty_id' => $w->id, 'client' => $w->client_name, 'project' => $w->project_name];
    }

    /* ---------------------------------------------------------------- */

    /** Lo que dicen los servidores de cada código (15 min en caché salvo $fresh). */
    private function lookup(array $codes, ?int $userId, bool $fresh, bool $reintentar = true): array
    {
        $out = [];
        $faltan = [];
        foreach ($codes as $code) {
            $hit = $fresh ? null : Cache::get("warranty_label:{$code}");
            $hit = ($hit['lookup_state'] ?? null) === 'found' ? $hit : null;
            $hit ? $out[$code] = $hit : $faltan[] = $code;
        }
        if ($faltan) {
            $res = $this->devices->labels($faltan, $userId);
            // Un servidor a veces contesta «bien» pero sin filas: antes de dar una etiqueta por
            // inexistente se pregunta otra vez solo por esas.
            $vacias = array_values(array_filter($faltan, fn ($c) => empty($res['labels'][$c]['live'])));
            if ($reintentar && $vacias && ! $res['failed']) {
                $otra = $this->devices->labels($vacias, $userId);
                foreach ($vacias as $c) {
                    if (! empty($otra['labels'][$c]['live'])) {
                        $res['labels'][$c] = $otra['labels'][$c];
                    }
                }
                $res['failed'] = $otra['failed'];
            }
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
                    // Etiqueta: batería y señal con la antena, en % (para el diagnóstico de la ficha).
                    'battery'       => $v && ! $ap ? self::pct($v['battery'] ?? null) : null,
                    'ap_signal'     => $v && ! $ap ? self::pct($v['apSignal'] ?? null) : null,
                    'server_model'  => $v && trim((string) ($v['model'] ?? '')) !== '' ? trim((string) $v['model']) : null,
                    'added_at'      => $v ? self::fecha($v['addTime'] ?? null)?->toDateString() : null,
                    'rows'          => array_map(fn ($x) => [
                        'server' => $x['server'] ?? null, 'url' => $x['server_url'] ?? '',
                        'online' => $vivo($x), 'store' => $x['storeName'] ?? null,
                    ], $l['rows']),
                ];
                // Solo se recuerda lo encontrado: un «no aparece» se vuelve a preguntar siempre.
                if ($state === 'found') {
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
        if ($abierta = $otras->firstWhere(fn ($g) => ! in_array($g->status, Warranty::closedStatuses(), true))) {
            $aviso('open_warranty', 'bad', "Ya está en la garantía abierta {$abierta->code} (" . (Warranty::statuses()[$abierta->status] ?? $abierta->status) . '): no puede entrar hasta que esa se cierre.', true);
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

    /** Un porcentaje de ZKONG (0-100) o null si no lo da. */
    private static function pct(mixed $v): ?int
    {
        return is_numeric($v) ? max(0, min(100, (int) round((float) $v))) : null;
    }

    /** Lo que se guarda de un resultado. */
    private function fila(array $r): array
    {
        return [
            'code' => $r['code'], 'kind' => $r['kind'], 'lookup_state' => $r['lookup_state'], 'server' => $r['server'],
            'store_name' => $r['store_name'], 'merchant_name' => $r['merchant_name'], 'online' => $r['online'],
            'last_commu_at' => $r['last_commu_at'], 'server_model' => $r['server_model'],
            'battery' => $r['battery'] ?? null, 'ap_signal' => $r['ap_signal'] ?? null,
            'device_model_id' => $r['device_model_id'], 'aeme_ref' => $r['aeme_ref'], 'product_name' => $r['product_name'],
            'model_manual' => (bool) $r['model_manual'], 'warnings' => $r['warnings'] ?: null, 'checked_at' => now(),
            'installed_at' => self::instalada($r['code'], $r['added_at'] ?? null),
        ];
    }

    /** La instalación: la primera alta en ZKONG que se conoce (la de ahora o una anterior). */
    private static function instalada(string $code, ?string $ahora): ?string
    {
        $antes = DeviceSighting::where('code', $code)->whereNotNull('added_at')->min('added_at');
        $fechas = array_filter([$ahora, $antes ? substr((string) $antes, 0, 10) : null]);
        return $fechas ? min($fechas) : null;
    }

    private function desdeFila(WarrantyItem $i): array
    {
        return [
            'code' => $i->code, 'kind' => $i->kind, 'lookup_state' => $i->lookup_state, 'failed' => [], 'server' => $i->server,
            'store_name' => $i->store_name, 'merchant_name' => $i->merchant_name, 'online' => $i->online,
            'last_commu_at' => $i->last_commu_at?->toDateTimeString(), 'server_model' => $i->server_model,
            'battery' => $i->battery, 'ap_signal' => $i->ap_signal,
            'device_model_id' => $i->device_model_id, 'aeme_ref' => $i->aeme_ref, 'product_name' => $i->product_name,
            'model_manual' => $i->model_manual, 'added_at' => $i->installed_at?->toDateString(),
            // Las filas por servidor no se guardan: el servidor vivo basta para el aviso de app02.
            'rows' => $i->server ? [['server' => $i->server, 'url' => (string) ZkongServer::where('name', $i->server)->value('base_url'), 'online' => (bool) $i->online, 'store' => $i->store_name]] : [],
        ];
    }
}
