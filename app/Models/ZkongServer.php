<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Servidor ZKONG (cuenta de administrador). Contraseña y token cifrados en reposo y
 * ocultos al serializar: nunca salen hacia el navegador.
 */
class ZkongServer extends Model
{
    protected $fillable = ['name', 'base_url', 'username', 'password', 'active', 'position'];

    protected $hidden = ['password', 'token'];

    protected $casts = [
        'password'         => 'encrypted',
        'token'            => 'encrypted',
        'active'           => 'boolean',
        'token_updated_at' => 'datetime',
    ];
}
