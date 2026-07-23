<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Prioridad configurable de un ticket (nombre, color, orden). */
class TicketPriority extends Model
{
    protected $guarded = [];

    protected $casts = ['active' => 'boolean', 'is_default' => 'boolean'];

    /** Las activas, en orden. Se memoiza por petición (se consulta en cada pantalla). */
    protected static ?array $cache = null;

    public static function activas(): array
    {
        return self::$cache ??= self::where('active', true)->orderBy('position')->orderBy('id')
            ->get(['key', 'name', 'color'])->keyBy('key')->toArray();
    }

    public static function olvidarCache(): void
    {
        self::$cache = null;
    }

    /** Clave de la prioridad por defecto para un ticket nuevo. */
    public static function porDefecto(): string
    {
        return (string) (self::where('active', true)->where('is_default', true)->value('key')
            ?: self::where('active', true)->orderBy('position')->value('key')
            ?: 'media');
    }
}
