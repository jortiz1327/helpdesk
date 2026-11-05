<?php

namespace App\Services\Zkong;

use App\Models\ZkongServer;

/**
 * Órdenes a UNA etiqueta de un servidor ZKONG (Centro de dispositivos): forzar que se
 * actualice, actualizar el firmware y reiniciar. Esto SÍ cambia cosas en el servidor.
 *
 * La etiqueta se busca otra vez por su código en ese servidor: no se usa el id que mande
 * el navegador, así la orden solo va a la etiqueta que se ve.
 */
class ZkongEslActions
{
    /** Fila de la etiqueta en el servidor (código exacto) o excepción si no está. */
    public function find(ZkongServer $s, string $code): array
    {
        $code = $this->codigo($code);
        if ($code === '') {
            throw new ZkongException('Falta el código de la etiqueta.');
        }
        $json = (new ZkongClient($s))->post('/pricetag/adminEsl?page=0&size=10', [
            'barCode' => $code, 'storeIdList' => [], 'model' => [], 'softVersion' => [], 'hardVersion' => [], 'apMacList' => [],
        ]);
        foreach ($json['data']['rows'] ?? [] as $row) {
            if ($this->codigo((string) ($row['barCode'] ?? '')) === $code && ! empty($row['id'])) {
                return $row;
            }
        }
        throw new ZkongException("La etiqueta {$code} no está en {$s->name}.");
    }

    public function reboot(ZkongServer $s, array $row): void
    {
        (new ZkongClient($s))->post('/pricetag/adminEslReboot', [(int) $row['id']]);
    }

    /**
     * Pide a la etiqueta que vuelva a pintar lo que tiene vinculado. Va por tienda: con la
     * cuenta de administración puede que ZKONG no lo deje (en la app de garantías se hacía
     * con la cuenta del comercio).
     */
    public function force(ZkongServer $s, array $row): void
    {
        if (empty($row['storeId'])) {
            throw new ZkongException('La etiqueta no está en ninguna tienda: no hay nada que actualizar.');
        }
        try {
            (new ZkongClient($s))->post('/bind/updateForceByBarCodes', [
                'storeId' => (string) $row['storeId'], 'barCodeList' => [(string) $row['barCode']],
            ]);
        } catch (ZkongException $e) {
            throw new ZkongException("{$s->name} no ha dejado forzar la etiqueta ({$e->getMessage()}). Puede que la cuenta del servidor no tenga permiso sobre esa tienda.");
        }
    }

    /**
     * Versiones de firmware a las que puede pasar la etiqueta.
     *
     * @return array<int, array{path: string, version: string, model: ?string, num: mixed}>
     */
    public function firmwares(ZkongServer $s, array $row): array
    {
        $json = (new ZkongClient($s))->post('/pricetag/upgradeInfo/', [(int) $row['id']]);
        $out = [];
        foreach ($json['data'][0]['versionList'] ?? [] as $v) {
            $path = (string) ($v['actual_path'] ?? '');
            if ($path === '') continue;
            $out[] = [
                'path'    => $path,
                'version' => (string) ($v['softVersion'] ?? $v['version'] ?? $path),
                'model'   => $v['model'] ?? null,
                'num'     => $v['num'] ?? null,
            ];
        }
        return $out;
    }

    /** Actualiza a una de las versiones que da firmwares() (se comprueba otra vez aquí). */
    public function upgrade(ZkongServer $s, array $row, string $path): array
    {
        $elegida = collect($this->firmwares($s, $row))->firstWhere('path', $path);
        if (! $elegida) {
            throw new ZkongException('Esa versión ya no está disponible para esta etiqueta.');
        }
        $model = (string) ($row['model'] ?? $elegida['model'] ?? '');
        (new ZkongClient($s))->post('/pricetag/upgrade', [
            'idList' => [(int) $row['id']],
            'upgradeInfoList' => [[
                'model' => $model,
                'modelVersion' => $model . '/' . ($row['softVersion'] ?? ''),
                'actual_path' => $path,
            ]],
        ]);
        return $elegida;
    }

    private function codigo(string $c): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $c));
    }
}
