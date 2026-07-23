<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    public $timestamps = false; // solo created_at (BD)
    protected $guarded = ['id'];

    protected $casts = [
        'last_time'    => 'datetime',
        'opted_out_at' => 'datetime',
        'consent_at'   => 'datetime',
        'created_at'   => 'datetime',
        'opted_out'    => 'integer',
        'consent'      => 'integer',
        'bot_off'      => 'integer',
        'unread'       => 'integer',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class, 'contact_labels');
    }

    public function agent()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
