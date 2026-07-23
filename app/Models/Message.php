<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    public $timestamps = false; // solo created_at (BD)
    protected $guarded = ['id'];

    // payload se guarda/lee como string JSON crudo (fiel al original; el
    // frontend hace JSON.parse). No lo casteamos a array para no re-codificar.
    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
