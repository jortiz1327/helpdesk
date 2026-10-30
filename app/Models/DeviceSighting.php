<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Un estado de un dispositivo tal como lo contó un servidor ZKONG. Solo se abre fila nueva
 * cuando cambia algo de TRACKED, así la lista de un código se lee como su historia:
 * cambios de tienda, de firmware, de servidor o de antena.
 */
class DeviceSighting extends Model
{
    public const ESL = 'esl';
    public const AP  = 'ap';

    /** Campos cuyo cambio abre una fila nueva. */
    public const TRACKED = ['server', 'store_id', 'model', 'firmware', 'ap_mac'];

    public $timestamps = false;

    protected $fillable = [
        'code', 'kind', 'server', 'store_id', 'store_name', 'merchant_name', 'model', 'firmware', 'ap_mac',
        'online', 'last_commu_at', 'added_at', 'first_seen_at', 'seen_at', 'seen_by',
    ];

    protected $casts = [
        'online'        => 'boolean',
        'last_commu_at' => 'datetime',
        'added_at'      => 'datetime',
        'first_seen_at' => 'datetime',
        'seen_at'       => 'datetime',
    ];

    /**
     * Guarda lo que un servidor acaba de decir de un dispositivo. Nunca lanza: perder un
     * apunte del historial no debe romper la consulta.
     */
    public static function record(string $kind, array $device, ?int $userId = null): ?self
    {
        try {
            $state = self::stateFrom($kind, $device);
            if ($state['code'] === '') {
                return null;
            }

            $last = self::where('code', $state['code'])->orderByDesc('seen_at')->orderByDesc('id')->first();
            if ($last && $last->sameState($state)) {
                $last->forceFill([
                    'online'        => $state['online'],
                    'last_commu_at' => $state['last_commu_at'],
                    'added_at'      => $state['added_at'] ?? $last->added_at,
                    'seen_at'       => now(),
                    'seen_by'       => $userId,
                ])->save();
                return $last;
            }

            return self::create($state + ['first_seen_at' => now(), 'seen_at' => now(), 'seen_by' => $userId]);
        } catch (Throwable $e) {
            report($e);
            return null;
        }
    }

    public static function stateFrom(string $kind, array $d): array
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($kind === self::AP ? ($d['mac'] ?? '') : ($d['barCode'] ?? ''))));
        $online = (int) ($kind === self::AP ? ($d['online'] ?? 0) : ($d['state'] ?? 0)) === 1;

        return [
            'code'          => $code,
            'kind'          => $kind,
            'server'        => self::text($d['server'] ?? null, 60),
            'store_id'      => isset($d['storeId']) ? (int) $d['storeId'] : null,
            'store_name'    => self::text($d['storeName'] ?? null, 120),
            'merchant_name' => self::text($d['merchantName'] ?? null, 120),
            'model'         => self::text($d['model'] ?? null, 60),
            'firmware'      => self::text($d['softVersion'] ?? null, 60),
            'ap_mac'        => $kind === self::ESL ? self::text($d['apMac'] ?? null, 40) : null,
            'online'        => $online,
            'last_commu_at' => self::fecha($d['lastCommuTime'] ?? null),
            'added_at'      => self::fecha($d['addTime'] ?? null),
        ];
    }

    public function sameState(array $state): bool
    {
        foreach (self::TRACKED as $f) {
            if ((string) ($this->{$f} ?? '') !== (string) ($state[$f] ?? '')) {
                return false;
            }
        }
        return true;
    }

    /** Qué cambió respecto a la fila anterior, campo a campo (la tienda se cuenta por su nombre). */
    public function changesFrom(?self $prev): array
    {
        if (! $prev) {
            return [];
        }
        $out = [];
        foreach (self::TRACKED as $f) {
            if ((string) ($prev->{$f} ?? '') !== (string) ($this->{$f} ?? '')) {
                $out[] = $f === 'store_id'
                    ? ['field' => 'store', 'before' => $prev->store_name, 'after' => $this->store_name]
                    : ['field' => $f, 'before' => $prev->{$f}, 'after' => $this->{$f}];
            }
        }
        return $out;
    }

    private static function text(mixed $v, int $max): ?string
    {
        $v = trim((string) ($v ?? ''));
        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    private static function fecha(mixed $v): ?Carbon
    {
        if ($v === null || $v === '') {
            return null;
        }
        try {
            // Los servidores mandan a veces la fecha y a veces milisegundos desde 1970.
            return is_numeric($v) ? Carbon::createFromTimestampMs((int) $v) : Carbon::parse((string) $v);
        } catch (Throwable) {
            return null;
        }
    }
}
