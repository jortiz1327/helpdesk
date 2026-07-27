<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Sede extends Model
{
    protected $table = 'sedes';
    protected $guarded = [];
    protected $casts = ['active' => 'boolean', 'marca_id' => 'integer'];

    public function marca(): BelongsTo
    {
        return $this->belongsTo(Marca::class);
    }
}
