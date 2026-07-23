<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Regla automática de tickets: si casan las condiciones, aplica las acciones. */
class TicketRule extends Model
{
    protected $guarded = [];

    protected $casts = [
        'active'     => 'boolean',
        'stop'       => 'boolean',
        'conditions' => 'array',
        'actions'    => 'array',
    ];

    /** Campos del ticket sobre los que se puede condicionar. */
    public const FIELDS = [
        'subject' => 'Asunto',
        'body'    => 'Mensaje/Cuerpo',
        'email'   => 'Correo del remitente',
        'domain'  => 'Dominio del remitente',
    ];

    /** Operadores disponibles. */
    public const OPS = [
        'contains'     => 'Contiene',
        'not_contains' => 'No contiene',
        'equals'       => 'Es igual a',
        'starts_with'  => 'Empieza por',
    ];

    public const CHANNELS = ['any' => 'Cualquiera', 'email' => 'Correo', 'whatsapp' => 'WhatsApp', 'web' => 'Web'];
}
