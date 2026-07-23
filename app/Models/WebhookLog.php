<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookLog extends Model
{
    protected $table = 'webhook_log';
    public $timestamps = false; // solo created_at (BD)
    protected $guarded = ['id'];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
