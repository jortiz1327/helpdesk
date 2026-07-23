<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Phonebook extends Model
{
    protected $guarded = ['id'];

    public function contacts(): HasMany
    {
        return $this->hasMany(PhonebookContact::class);
    }
}
