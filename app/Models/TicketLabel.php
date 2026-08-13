<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Etiqueta de ticket (nombre, color, orden). Catálogo fijo, gestionado por encargados. */
class TicketLabel extends Model
{
    protected $guarded = [];

    protected $casts = ['active' => 'boolean'];

    /** Las activas, en orden. Se memoiza por petición. */
    protected static ?array $cache = null;

    public static function activas(): array
    {
        return self::$cache ??= self::where('active', true)->orderBy('position')->orderBy('id')
            ->get(['id', 'name', 'color'])->all();
    }

    public static function olvidarCache(): void
    {
        self::$cache = null;
    }
}
