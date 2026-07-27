<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Marca extends Model
{
    protected $table = 'marcas';
    protected $guarded = [];
    protected $casts = ['active' => 'boolean', 'grupo_id' => 'integer'];

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(Grupo::class);
    }

    public function sedes(): HasMany
    {
        return $this->hasMany(Sede::class);
    }
}
