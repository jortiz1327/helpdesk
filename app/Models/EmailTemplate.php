<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Plantilla de aviso por correo (ticket creado / cerrado / asignado). */
class EmailTemplate extends Model
{
    protected $guarded = [];

    protected $casts = ['active' => 'boolean', 'recipients' => 'array'];

    /** A quién se puede avisar, además del destinatario natural de la plantilla. */
    public const RECIPIENTS = [
        'client'   => 'El cliente',
        'agent'    => 'El agente asignado',
        'category' => 'Los agentes del área',
        'admins'   => 'Los administradores',
    ];

    /** Descripción de cada plantilla, para la pantalla de configuración. */
    public const INFO = [
        'ticket_created'  => ['Ticket creado',   'Acuse de recibo al CLIENTE cuando se abre su ticket.'],
        'ticket_closed'   => ['Ticket cerrado',  'Aviso al CLIENTE cuando su ticket se resuelve o se cierra.'],
        'ticket_assigned' => ['Ticket asignado', 'Aviso al AGENTE cuando se le asigna un ticket.'],
    ];

    /** Variables que se pueden usar en el asunto y el contenido. */
    public const VARS = ['{{codigo}}', '{{asunto}}', '{{cliente}}', '{{agente}}', '{{estado}}', '{{soporte}}'];
}
