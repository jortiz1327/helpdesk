<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Respuesta efectiva aprendida de un envío real (ver migración). */
class EffectiveResponse extends Model
{
    protected $guarded = ['id'];

    public $timestamps = false;   // solo created_at, sin updated_at
}
