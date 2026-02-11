<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Expansion extends Model
{
    protected $fillable = [
        'ct_id',
        'code',
        'tcgdex_code',
        'name',
        'name_en',
        'release_date',
        'image_url',
        'symbol_url',
    ];

    protected function casts(): array
    {
        return [
            'release_date' => 'datetime',
        ];
    }
}
