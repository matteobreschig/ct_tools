<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\ConditionType;
use App\Enums\Language;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    protected $fillable = [
        'user_id',
        'product_id',
        'name',
        'description',
        'quantity',
        'price',
        'sale',
        'blueprint_id',
        'category_id',
        'expansion_id',
        'collector_number',
        'condition',
        'tournament_legal',
        'reverse',
        'language',
        'signed',
        'rarity',
        'altered',
        'first_edition',
        'last_update',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => MoneyCast::class,
            'sale' => 'boolean',
            'tournament_legal' => 'boolean',
            'reverse' => 'boolean',
            'signed' => 'boolean',
            'altered' => 'boolean',
            'first_edition' => 'boolean',
            'condition' => ConditionType::class,
            'language' => Language::class,
            'last_update' => 'datetime',
        ];
    }

    public static function booted(): void
    {
        static::creating(function (Product $item) {
            $item->user_id = auth()->id();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function expansion(): BelongsTo
    {
        return $this->belongsTo(Expansion::class);
    }
}
