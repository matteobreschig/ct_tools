<?php

namespace App\Models;

use App\Enums\ConditionType;
use App\Enums\Language;
use App\Services\Pokecardex\CardKey;
use Illuminate\Database\Eloquent\Model;

/**
 * Quantità concordata all'ultimo giro riuscito per una chiave carta (§ 4.2
 * del design): la base del confronto a tre.
 */
class PokecardexSyncState extends Model
{
    protected $fillable = [
        'user_id',
        'blueprint_id',
        'condition',
        'language',
        'reverse',
        'first_edition',
        'quantity',
    ];

    protected function casts(): array
    {
        return [
            'condition' => ConditionType::class,
            'language' => Language::class,
            'reverse' => 'boolean',
            'first_edition' => 'boolean',
            'quantity' => 'integer',
        ];
    }

    public function key(): CardKey
    {
        return new CardKey($this->blueprint_id, $this->condition, $this->language, $this->reverse, $this->first_edition);
    }
}
