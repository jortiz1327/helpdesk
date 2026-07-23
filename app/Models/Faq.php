<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Faq extends Model
{
    protected $guarded = [];

    protected $casts = [
        'active'      => 'boolean',
        'category_id' => 'integer',
        'position'    => 'integer',
        'views'       => 'integer',
        'helpful_yes' => 'integer',
        'helpful_no'  => 'integer',
    ];
}
