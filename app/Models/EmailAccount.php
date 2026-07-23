<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Buzón de soporte (IMAP entrante + SMTP saliente). Contraseñas encriptadas en reposo. */
class EmailAccount extends Model
{
    protected $guarded = [];

    protected $casts = [
        'imap_password' => 'encrypted',
        'smtp_password' => 'encrypted',
        'active'        => 'boolean',
        'last_check_at' => 'datetime',
    ];
}
