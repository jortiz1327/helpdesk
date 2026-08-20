<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Definición de un campo personalizado de ticket (ver migración). */
class TicketCustomField extends Model
{
    protected $guarded = ['id'];

    public $timestamps = false;

    protected $casts = [
        'options'  => 'array',
        'required' => 'boolean',
        'active'   => 'boolean',
    ];

    /** Tipos admitidos. */
    public const TIPOS = ['text', 'textarea', 'number', 'select', 'checkbox', 'date'];
}
