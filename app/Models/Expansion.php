<?php

namespace App\Models;

use Database\Factories\ExpansionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Expansion extends Model
{
    /** @use HasFactory<ExpansionFactory> */
    use HasFactory;

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
            'blueprints_loaded_at' => 'datetime',
        ];
    }
}
