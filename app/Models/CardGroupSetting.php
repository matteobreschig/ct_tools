<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Deroga per gruppo alla vendita dinamica: copie minime da tenere per
 * questa carta, al posto del minimo globale dell'utente.
 */
class CardGroupSetting extends Model
{
    protected $fillable = [
        'user_id',
        'group_key',
        'keep_quantity',
    ];

    protected function casts(): array
    {
        return [
            'keep_quantity' => 'integer',
        ];
    }
}
