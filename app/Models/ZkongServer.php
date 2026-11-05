<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Servidor ZKONG (cuenta de administrador). Contraseña y token cifrados en reposo y
 * ocultos al serializar: nunca salen hacia el navegador.
 */
class ZkongServer extends Model
{
    protected $fillable = ['name', 'base_url', 'username', 'password', 'active', 'position', 'product_store_id'];

    // product_*: la segunda cuenta, la que crea productos (garantías en el cloud).
    protected $hidden = ['password', 'token', 'product_password', 'product_token'];

    protected $casts = [
        'password'         => 'encrypted',
        'token'            => 'encrypted',
        'product_password' => 'encrypted',
        'product_token'    => 'encrypted',
        'product_token_updated_at' => 'datetime',
        'active'           => 'boolean',
        'token_updated_at' => 'datetime',
    ];
}
