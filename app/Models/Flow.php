<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Flow extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'graph'  => 'array',
        'active' => 'integer',
    ];
}
