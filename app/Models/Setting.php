<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    protected $guarded = [];

    /**
     * Caché del mapa completo de ajustes. La tabla es diminuta (~30 filas) y Setting::get
     * se llama por todas partes (SLA, candado, CSAT, pie de correo, zona horaria…): antes
     * cada llamada era una consulta suelta. Se carga entera UNA vez y las demás lecturas
     * salen del mapa.
     *
     * Se guarda como instancia «scoped» del contenedor —NO un `static`—, que Laravel
     * vacía entre PETICIONES y entre TRABAJOS DE COLA (forgetScopedInstances). Con un
     * `static` quedaría congelado toda la vida del proceso y un worker de cola no se
     * enteraría de un cambio de ajuste (mismo motivo que SlaService::activo()).
     */
    protected static function mapa(): array
    {
        $app = app();
        if (!$app->bound('settings.mapa')) {
            $app->scoped('settings.mapa', fn () => static::query()->pluck('value', 'key')->all());
        }
        return $app->make('settings.mapa');
    }

    /** Lee un ajuste con valor por defecto. */
    public static function get(string $key, ?string $default = null): ?string
    {
        $mapa = static::mapa();
        return array_key_exists($key, $mapa) ? ($mapa[$key] ?? $default) : $default;
    }

    /** Guarda (upsert) un ajuste e invalida la caché para que la próxima lectura la relea. */
    public static function put(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        static::flushCache();
    }

    /** Olvida el mapa cacheado (la siguiente lectura lo reconstruye desde la BD). */
    public static function flushCache(): void
    {
        app()->forgetInstance('settings.mapa');
    }
}
