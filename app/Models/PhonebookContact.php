<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhonebookContact extends Model
{
    public $timestamps = false; // solo created_at (BD)
    protected $guarded = ['id'];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function phonebook(): BelongsTo
    {
        return $this->belongsTo(Phonebook::class);
    }
}
