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
        'image_url',
        'description',
        'quantity',
        'price',
        'sale',
        'blueprint_id',
        'cardmarket_product_id',
        'category_id',
        'expansion_id',
        'collector_number',
        'condition',
        'regulation_mark',
        'tournament_legal',
        'tournament_extended_legal',
        'reverse',
        'language',
        'signed',
        'rarity',
        'altered',
        'first_edition',
        'cardmarket_price_avg',
        'cardmarket_price_low',
        'cardmarket_price_trend',
        'cardmarket_price_avg1',
        'cardmarket_price_avg7',
        'cardmarket_price_avg30',
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
            'tournament_extended_legal' => 'boolean',
            'reverse' => 'boolean',
            'signed' => 'boolean',
            'altered' => 'boolean',
            'first_edition' => 'boolean',
            'condition' => ConditionType::class,
            'language' => Language::class,
            'cardmarket_price_avg' => MoneyCast::class,
            'cardmarket_price_low' => MoneyCast::class,
            'cardmarket_price_trend' => MoneyCast::class,
            'cardmarket_price_avg1' => MoneyCast::class,
            'cardmarket_price_avg7' => MoneyCast::class,
            'cardmarket_price_avg30' => MoneyCast::class,
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
