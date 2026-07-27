<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Grupo extends Model
{
    protected $table = 'grupos';
    protected $guarded = [];
    protected $casts = ['active' => 'boolean'];

    public function marcas(): HasMany
    {
        return $this->hasMany(Marca::class);
    }
}
