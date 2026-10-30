<?php

namespace App\Services\Zkong;

use App\Models\DeviceSighting;
use App\Models\ZkongServer;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Centro de dispositivos: etiquetas (ESL) y antenas (AP) de los servidores ZKONG.
 * SOLO LECTURA: nada de aquí cambia nada en los servidores.
 */
class ZkongDevices
{
    public const MAX_PAGINA = 100;

    /** Servidores activos, en su orden. */
    public function servers()
    {
        return ZkongServer::where('active', true)->orderBy('position')->orderBy('id')->get();
    }

    /**
     * Tiendas del servidor, aplanadas: storeId => [storeName, merchantName, agencyName].
     * El árbol viene agencia → comercio → tienda; las etiquetas «[...]» se quitan.
     */
    public function stores(ZkongServer $s): array
    {
        return Cache::remember($this->clave($s, 'stores'), 3600, function () use ($s) {
            $limpiar = fn ($l) => trim(preg_replace('/\s*\[.*?\]/', '', (string) $l));
            $out = [];
            foreach ((new ZkongClient($s))->get('/store/storeTree')['data']['storeTree'] ?? [] as $agencia) {
                foreach ($agencia['children'] ?? [] as $comercio) {
                    foreach ($comercio['children'] ?? [] as $tienda) {
                        $out[(string) $tienda['id']] = [
                            'storeName'    => $limpiar($tienda['label'] ?? ''),
                            'merchantName' => $limpiar($comercio['label'] ?? ''),
                            'agencyName'   => $limpiar($agencia['label'] ?? ''),
                        ];
                    }
                }
            }
            return $out;
        });
    }

    /** Opciones de los filtros: tiendas, modelos y versiones de firmware del tipo pedido. */
    public function filters(ZkongServer $s, string $kind): array
    {
        $c = new ZkongClient($s);
        $tiendas = collect($this->stores($s))
            ->map(fn ($t, $id) => ['value' => (string) $id, 'label' => $t['storeName'], 'sub' => $t['merchantName']])
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();

        if ($kind === DeviceSighting::AP) {
            $modelos = Cache::remember($this->clave($s, 'ap_models'), 86400, fn () => collect($c->get('/apModel/findAll')['data'] ?? [])->pluck('model')->filter()->unique()->sort()->values()->all());
            $versiones = Cache::remember($this->clave($s, 'ap_versions'), 86400, fn () => collect($c->get('/apStation/softVersion')['data'] ?? [])->filter()->unique()->sort()->values()->all());
        } else {
            $modelos = Cache::remember($this->clave($s, 'esl_models'), 86400, fn () => collect($c->get('/pricetagModel/getAll')['data'] ?? [])->pluck('model')->filter()->unique()->sort()->values()->all());
            $versiones = Cache::remember($this->clave($s, 'esl_versions'), 86400, fn () => collect($c->get('/pricetag/allEslSoftVersion')['data'] ?? [])->filter()->unique()->sort()->values()->all());
        }

        return ['stores' => $tiendas, 'models' => $modelos, 'versions' => $versiones];
    }

    /**
     * Página de etiquetas. $f admite: code, stores[], models[], versions[], state (0/1),
     * battery (1-5), signal_min, signal_max.
     */
    public function esl(ZkongServer $s, array $f, int $page, int $size): array
    {
        $tiendas = $this->stores($s);
        $json = (new ZkongClient($s))->post('/pricetag/adminEsl?page=' . $page . '&size=' . $this->tam($size), [
            'batteryLevel'  => $this->int($f['battery'] ?? null),
            'rebootStatus'  => null,
            'apMacList'     => [],
            'state'         => $this->int($f['state'] ?? null),
            'upgradeState'  => null,
            'model'         => array_values((array) ($f['models'] ?? [])),
            'softVersion'   => array_values((array) ($f['versions'] ?? [])),
            'hardVersion'   => [],
            'apSignalStart' => $this->int($f['signal_min'] ?? null),
            'apSignalEnd'   => $this->int($f['signal_max'] ?? null),
            'barCode'       => $this->codigo($f['code'] ?? '') ?: null,
            'storeIdList'   => $this->tiendasFiltro($f['stores'] ?? [], $tiendas),
        ]);

        // Sin el artículo: la tabla sale ya y el navegador lo pide aparte con items().
        $rows = array_map(fn ($r) => $this->conTienda($r, $s, $tiendas), $json['data']['rows'] ?? []);
        return ['rows' => $rows, 'total' => (int) ($json['data']['total'] ?? 0)];
    }

    /**
     * Artículo de varias etiquetas: [{storeId, barCode}] → barCode => {itemTitle, itemBarCode}.
     * Va aparte de la lista porque cada consulta tarda ~0,3 s en el servidor.
     */
    public function items(ZkongServer $s, array $items): array
    {
        $rows = array_map(fn ($i) => ['bindState' => 1, 'storeId' => $i['storeId'] ?? null, 'barCode' => $i['barCode'] ?? null], array_slice($items, 0, 50));
        $out = [];
        foreach ($this->conArticulo($s, $rows) as $r) {
            if ($r['barCode']) {
                $out[(string) $r['barCode']] = ['itemTitle' => $r['itemTitle'], 'itemBarCode' => $r['itemBarCode']];
            }
        }
        return $out;
    }

    /** Página de antenas. $f admite: code (MAC), stores[], models[], versions[], online (0/1). */
    public function ap(ZkongServer $s, array $f, int $page, int $size): array
    {
        $tiendas = $this->stores($s);
        $json = (new ZkongClient($s))->post('/apStation/adminAp?page=' . $page . '&size=' . $this->tam($size), [
            'storeIdList'  => $this->tiendasFiltro($f['stores'] ?? [], $tiendas),
            'online'       => $this->int($f['online'] ?? null),
            'upgradeState' => null,
            'model'        => array_values((array) ($f['models'] ?? [])),
            'softVersion'  => array_values((array) ($f['versions'] ?? [])),
            'mac'          => $this->mac($f['code'] ?? ''),
            'sort'         => null,
            'sortType'     => null,
            'rebootState'  => null,
        ]);

        return [
            'rows'  => array_map(fn ($r) => $this->conTienda($r, $s, $tiendas), $json['data']['rows'] ?? []),
            'total' => (int) ($json['data']['total'] ?? 0),
        ];
    }

    /**
     * Busca varios códigos en TODOS los servidores. Un código de 12 caracteres hexadecimales
     * se trata como MAC de antena; el resto, como etiqueta.
     *
     * @return array{results: array, failed: array<int, string>}
     */
    public function search(array $codes): array
    {
        $codes = array_values(array_unique(array_filter(array_map(fn ($c) => $this->codigo((string) $c), $codes))));
        $out = array_fill_keys($codes, []);
        $fallidos = [];

        foreach ($this->servers() as $s) {
            try {
                $tiendas = $this->stores($s);
                $todas = [];   // sin filtro de tienda: busca en todo el servidor
                $c = new ZkongClient($s);
                $etiquetas = [];
                foreach ($codes as $code) {
                    if (self::kindOf($code) === DeviceSighting::AP) {
                        // APP02 guarda la MAC sin dos puntos; se prueban los dos formatos.
                        $row = null;
                        foreach ([$code, $this->mac($code)] as $mac) {
                            $json = $c->post('/apStation/adminAp?page=0&size=10', ['storeIdList' => $todas, 'mac' => $mac, 'model' => [], 'softVersion' => []]);
                            if ($row = $json['data']['rows'][0] ?? null) {
                                break;
                            }
                        }
                        if ($row) {
                            $out[$code][] = ['kind' => DeviceSighting::AP] + $this->conTienda($row, $s, $tiendas);
                        }
                    } else {
                        $json = $c->post('/pricetag/adminEsl?page=0&size=10', ['barCode' => $code, 'storeIdList' => $todas, 'model' => [], 'softVersion' => [], 'hardVersion' => [], 'apMacList' => []]);
                        $row = $json['data']['rows'][0] ?? null;
                        if ($row) {
                            $etiquetas[$code] = ['kind' => DeviceSighting::ESL] + $this->conTienda($row, $s, $tiendas);
                        }
                    }
                }
                foreach ($this->conArticulo($s, array_values($etiquetas)) as $row) {
                    $out[$this->codigo((string) $row['barCode'])][] = $row;
                }
            } catch (Throwable $e) {
                report($e);
                $fallidos[] = $s->name;
            }
        }

        $results = [];
        foreach ($out as $code => $rows) {
            $results[] = ['code' => $code, 'kind' => self::kindOf($code), 'rows' => $rows];
        }
        return ['results' => $results, 'failed' => $fallidos];
    }

    /**
     * Muchas etiquetas o antenas a la vez (las de una garantía) en TODOS los servidores: en cada
     * servidor, una consulta por código y todas en paralelo. De cada código devuelve las
     * filas de cada servidor donde sale y cuál es la viva, y apunta su historial.
     *
     * @return array{labels: array<string, array{rows: array, live: ?array}>, failed: array<int, string>}
     */
    public function labels(array $codes, ?int $userId = null): array
    {
        $codes = array_values(array_unique(array_filter(array_map(fn ($c) => $this->codigo((string) $c), $codes))));
        $filas = array_fill_keys($codes, []);
        $fallidos = [];

        foreach ($this->servers() as $s) {
            if (! $codes) {
                break;
            }
            try {
                $tiendas = $this->stores($s);
                $c = new ZkongClient($s);
                // Etiqueta: por código de barras. Antena: por MAC, sin y con dos puntos
                // (cada servidor la guarda a su manera).
                $llamadas = [];
                foreach ($codes as $code) {
                    if (self::kindOf($code) === DeviceSighting::AP) {
                        foreach (array_unique([$code, $this->mac($code)]) as $j => $mac) {
                            $llamadas["{$code}|{$j}"] = ['/apStation/adminAp?page=0&size=10', ['storeIdList' => [], 'mac' => $mac, 'model' => [], 'softVersion' => []]];
                        }
                    } else {
                        $llamadas["{$code}|0"] = ['/pricetag/adminEsl?page=0&size=10', ['barCode' => $code, 'storeIdList' => [], 'model' => [], 'softVersion' => [], 'hardVersion' => [], 'apMacList' => []]];
                    }
                }
                // La primera, normal (renueva el token si caducó); el resto, en paralelo.
                $primera = array_key_first($llamadas);
                $res = [$primera => $c->post(...$llamadas[$primera])];
                $res += $c->postMany(array_slice($llamadas, 1, null, true));
                $vistos = [];
                foreach ($res as $clave => $json) {
                    $code = explode('|', (string) $clave)[0];
                    if ($json === null) {
                        throw new \RuntimeException("Sin respuesta de {$s->name} para {$code}");
                    }
                    $ap = self::kindOf($code) === DeviceSighting::AP;
                    // La búsqueda es por «contiene»: solo vale la fila con el código exacto.
                    foreach ($json['data']['rows'] ?? [] as $row) {
                        if ($this->codigo((string) ($row[$ap ? 'mac' : 'barCode'] ?? '')) === (string) $code && empty($vistos[$code])) {
                            $vistos[$code] = true;
                            $filas[$code][] = ['kind' => $ap ? DeviceSighting::AP : DeviceSighting::ESL, 'server_url' => $s->base_url] + $this->conTienda($row, $s, $tiendas);
                        }
                    }
                }
            } catch (Throwable $e) {
                report($e);
                $fallidos[] = $s->name;
            }
        }

        $out = [];
        foreach ($filas as $code => $rows) {
            $ap = self::kindOf((string) $code) === DeviceSighting::AP;
            $vivo = $rows ? ($ap ? $this->antenaViva($rows) : $this->etiquetaViva($rows)) : null;
            if ($vivo) {
                DeviceSighting::record($ap ? DeviceSighting::AP : DeviceSighting::ESL, $vivo, $userId);
            }
            $out[$code] = ['rows' => $rows, 'live' => $vivo];
        }
        return ['labels' => $out, 'failed' => $fallidos];
    }

    /**
     * Ficha de un dispositivo: dónde está ahora (si sale en varios servidores, cuál es el
     * vivo), en qué otros aparece y su historial guardado aquí.
     */
    public function sheet(string $code, ?int $userId): array
    {
        $code = $this->codigo($code);
        $kind = self::kindOf($code);
        $r = $this->search([$code]);
        $rows = $r['results'][0]['rows'] ?? [];

        $vivo = null;
        if ($rows) {
            $vivo = $kind === DeviceSighting::AP ? $this->antenaViva($rows) : $this->etiquetaViva($rows);
            DeviceSighting::record($kind, $vivo, $userId);
        }

        $hist = DeviceSighting::where('code', $code)->orderBy('seen_at')->orderBy('id')->get();
        $historial = [];
        $prev = null;
        foreach ($hist as $h) {
            $historial[] = [
                'server' => $h->server, 'store' => $h->store_name, 'merchant' => $h->merchant_name,
                'model' => $h->model, 'firmware' => $h->firmware, 'ap_mac' => $h->ap_mac, 'online' => $h->online,
                'first_seen_at' => $h->first_seen_at?->toDateTimeString(), 'seen_at' => $h->seen_at?->toDateTimeString(),
                'changes' => $h->changesFrom($prev),
            ];
            $prev = $h;
        }

        return [
            'code'    => $code,
            'kind'    => $kind,
            'found'   => $vivo !== null,
            'device'  => $vivo,
            'others'  => array_values(array_filter($rows, fn ($x) => $x !== $vivo)),
            'failed'  => $r['failed'],
            'history' => array_reverse($historial),
        ];
    }

    /** 12 caracteres hexadecimales que no sean 9 dígitos = MAC de antena. */
    public static function kindOf(string $code): string
    {
        return preg_match('/^[0-9A-F]{12}$/', $code) && ! preg_match('/^\d{9}$/', $code) ? DeviceSighting::AP : DeviceSighting::ESL;
    }

    /**
     * Una etiqueta que sale en varios servidores (tienda migrada): la conectada; si lo
     * está en varios o en ninguno, la de la tienda con antenas conectadas; si sigue
     * empatado, la que habló con el servidor más recientemente.
     */
    private function etiquetaViva(array $rows): array
    {
        if (count($rows) === 1) {
            return $rows[0];
        }
        $online = array_values(array_filter($rows, fn ($r) => (int) ($r['state'] ?? 0) === 1));
        if (count($online) === 1) {
            return $online[0];
        }
        $pool = $online ?: $rows;
        $conAntenas = array_values(array_filter($pool, fn ($r) => $this->tiendaConAntenas($r)));
        $pool = count($conAntenas) ? $conAntenas : $pool;
        usort($pool, fn ($a, $b) => strcmp((string) ($b['lastCommuTime'] ?? ''), (string) ($a['lastCommuTime'] ?? '')));
        return $pool[0];
    }

    private function antenaViva(array $rows): array
    {
        $online = array_values(array_filter($rows, fn ($r) => (int) ($r['online'] ?? 0) === 1));
        $pool = $online ?: $rows;
        usort($pool, fn ($a, $b) => strcmp((string) ($b['lastCommuTime'] ?? $b['offLineTime'] ?? ''), (string) ($a['lastCommuTime'] ?? $a['offLineTime'] ?? '')));
        return $pool[0];
    }

    private function tiendaConAntenas(array $row): bool
    {
        $s = ZkongServer::find($row['server_id'] ?? 0);
        if (! $s || empty($row['storeId'])) {
            return false;
        }
        try {
            $json = (new ZkongClient($s))->post('/apStation/adminAp?page=0&size=1', ['storeIdList' => [(int) $row['storeId']], 'online' => 1, 'model' => [], 'softVersion' => []]);
            return (int) ($json['data']['total'] ?? 0) > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /** Añade servidor, tienda y comercio a una fila del servidor. */
    private function conTienda(array $row, ZkongServer $s, array $tiendas): array
    {
        $t = $tiendas[(string) ($row['storeId'] ?? '')] ?? null;
        return $row + [
            'server'       => $s->name,
            'server_id'    => $s->id,
            'storeName'    => $t['storeName'] ?? null,
            'merchantName' => $t['merchantName'] ?? null,
        ];
    }

    /**
     * Artículo vinculado a cada etiqueta (título y código del producto): una consulta POR
     * CÓDIGO, todas a la vez. Antes se pedía la lista entera de cada tienda y una tienda
     * de 8.700 etiquetas tardaba 7 s; por código son unos 60 ms y van en paralelo.
     */
    private function conArticulo(ZkongServer $s, array $rows): array
    {
        $calls = [];
        foreach ($rows as $i => $r) {
            if ((int) ($r['bindState'] ?? 0) === 1 && ! empty($r['storeId']) && ! empty($r['barCode'])) {
                $calls[$i] = ['/erp/esl/list?size=1', ['storeId' => (string) $r['storeId'], 'priceTagCode' => (string) $r['barCode']]];
            }
        }
        try {
            $res = (new ZkongClient($s))->postMany($calls);
        } catch (Throwable $e) {
            report($e);
            $res = [];
        }
        foreach ($rows as $i => &$r) {
            $b = $res[$i]['data']['list'][0] ?? null;
            $r['itemTitle'] = $b['itemTitle'] ?? null;
            $r['itemBarCode'] = $b['itemBarCode'] ?? null;
        }
        return $rows;
    }

    /**
     * Cifras de cabecera del servidor: total, conectados, desconectados y (etiquetas)
     * batería baja. Cada una es una consulta de 1 fila, todas a la vez; 2 min en caché.
     */
    public function stats(ZkongServer $s, string $kind): array
    {
        return Cache::remember($this->clave($s, "stats_{$kind}"), 120, function () use ($s, $kind) {
            $c = new ZkongClient($s);
            if ($kind === DeviceSighting::AP) {
                $uri = '/apStation/adminAp?page=0&size=1';
                $base = ['storeIdList' => [], 'model' => [], 'softVersion' => []];
                $q = ['total' => $base, 'online' => $base + ['online' => 1], 'offline' => $base + ['online' => 0]];
            } else {
                $uri = '/pricetag/adminEsl?page=0&size=1';
                $base = ['storeIdList' => [], 'model' => [], 'softVersion' => [], 'hardVersion' => [], 'apMacList' => []];
                $q = ['total' => $base, 'online' => $base + ['state' => 1], 'offline' => $base + ['state' => 0], 'low_battery' => $base + ['batteryLevel' => 1]];
            }
            $c->post($uri, $base);   // asegura un token válido antes de lanzarlas en paralelo
            $res = $c->postMany(array_map(fn ($body) => [$uri, $body], $q));
            return array_map(fn ($r) => $r === null ? null : (int) ($r['data']['total'] ?? 0), $res);
        });
    }

    /**
     * Tiendas elegidas en el filtro. Sin filtro va vacío: medido en APP02, el servidor
     * devuelve el mismo total y no hace falta mandarle las ~1.000 tiendas en cada llamada.
     */
    private function tiendasFiltro(mixed $elegidas, array $tiendas = []): array
    {
        return array_values(array_filter(array_map('intval', (array) $elegidas)));
    }

    private function codigo(string $c): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $c));
    }

    /** Los servidores guardan la MAC de las antenas con dos puntos. */
    private function mac(string $c): string
    {
        $c = $this->codigo($c);
        return strlen($c) === 12 ? implode(':', str_split($c, 2)) : $c;
    }

    private function int(mixed $v): ?int
    {
        return $v === null || $v === '' ? null : (int) $v;
    }

    private function tam(int $size): int
    {
        return max(1, min(self::MAX_PAGINA, $size));
    }

    /** La clave lleva la fecha de la última edición: cambiar el servidor invalida su caché. */
    private function clave(ZkongServer $s, string $que): string
    {
        return "zkong:{$s->id}:" . optional($s->updated_at)->timestamp . ":{$que}";
    }
}
