<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FlowSession extends Model
{
    public $timestamps = false; // updated_at lo gestiona la BD (ON UPDATE)
    protected $guarded = ['id'];

    protected $casts = [
        'variables' => 'array',
        'resume_at' => 'datetime',
    ];
}
