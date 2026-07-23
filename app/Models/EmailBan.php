<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Correo (o dominio) bloqueado: sus correos entrantes no crean ticket. */
class EmailBan extends Model
{
    protected $guarded = [];

    protected $casts = ['active' => 'boolean'];

    /**
     * ¿Está bloqueada esta dirección? Coincide por dirección EXACTA o por DOMINIO
     * (una entrada «@dominio.com» o «dominio.com» bloquea todo el dominio). Solo
     * cuentan las entradas activas.
     */
    public static function isBanned(string $email): bool
    {
        $email = mb_strtolower(trim($email));
        if ($email === '') return false;

        $domain = substr((string) strrchr($email, '@'), 1);

        return self::where('active', true)
            ->whereRaw('LOWER(email) IN (?, ?, ?)', [$email, '@' . $domain, $domain])
            ->exists();
    }
}
