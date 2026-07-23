<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FlowResponse extends Model
{
    public $timestamps = false; // solo created_at (BD)
    protected $guarded = ['id'];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
