<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una etiqueta afectada de una garantía: dónde estaba al comprobarla, su modelo y el
 * producto del portal que es (referencia AEME).
 */
class WarrantyItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'online'        => 'boolean',
        'model_manual'  => 'boolean',
        'warnings'      => 'array',
        'last_commu_at' => 'datetime',
        'checked_at'    => 'datetime',
    ];
}
