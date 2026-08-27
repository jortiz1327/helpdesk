<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un número de WhatsApp del helpdesk, con su función (a qué módulo enruta) y sus
 * credenciales. Es la pieza de la opción B: el webhook resuelve por `phone_number_id`.
 */
class WhatsAppNumber extends Model
{
    protected $table = 'whatsapp_numbers';
    protected $guarded = [];
    protected $casts = ['active' => 'boolean'];

    public const FUNCIONES = ['soporte', 'campanas'];

    /** ¿Hay al menos un número configurado y activo? Decide si se enruta o se usa el legacy. */
    public static function hayConfigurados(): bool
    {
        return static::where('active', true)->exists();
    }

    /** El número activo dueño de ese phone_number_id, o null. */
    public static function porPhoneId(?string $phoneId): ?self
    {
        if (!$phoneId) return null;
        return static::where('phone_number_id', $phoneId)->where('active', true)->first();
    }

    /** Primer número activo de una función (soporte/campanas), para enviar desde él. */
    public static function porFuncion(string $funcion): ?self
    {
        return static::where('funcion', $funcion)->where('active', true)
            ->orderBy('id')->first();
    }

    /**
     * El número cuyo WABA usar para operaciones sobre la cuenta (plantillas, flows…):
     * el de la función pedida (campañas por defecto) o, si no, cualquiera activo que
     * tenga WABA. La config del WABA vive AQUÍ (por número), no en el ajuste global.
     */
    public static function conWaba(string $funcion = 'campanas'): ?self
    {
        return static::porFuncion($funcion)
            ?? static::whereNotNull('waba_id')->where('active', true)->orderBy('id')->first();
    }
}
