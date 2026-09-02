<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Buzón de soporte (IMAP entrante + SMTP saliente). Contraseñas encriptadas en reposo. */
class EmailAccount extends Model
{
    /**
     * Solo los campos de CONFIGURACIÓN que edita el admin son asignables en masa. Los
     * contadores de sistema (last_check_at, last_uid, fail_uid, fail_count) los escribe
     * el cron por query builder, así que quedan FUERA a propósito: nunca por $request.
     */
    protected $fillable = [
        'email', 'from_name', 'active',
        'imap_host', 'imap_port', 'imap_encryption', 'imap_user', 'imap_password',
        'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_user', 'smtp_password',
    ];

    protected $casts = [
        'imap_password' => 'encrypted',
        'smtp_password' => 'encrypted',
        'active'        => 'boolean',
        'last_check_at' => 'datetime',
    ];
}
