<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Vista guardada de la bandeja (personal de cada agente). */
class TicketView extends Model
{
    protected $guarded = [];
    protected $casts = ['filters' => 'array'];
}
