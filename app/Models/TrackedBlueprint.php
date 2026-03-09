<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\ConditionType;
use App\Enums\Language;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrackedBlueprint extends Model
{
    protected $fillable = [
        'user_id',
        'blueprint_id',
        'blueprint_url',
        'blueprint_name',
        'blueprint_expansion_id',
        'blueprint_collector_number',
        'blueprint_image_url',
        'active',
        'price',
        'minimum_quantity',
        'conditions',
        'languages',
        'ct_zero_only',
        'cardmarket_product_id',
        'cardmarket_price_avg',
        'cardmarket_price_low',
        'cardmarket_price_trend',
        'cardmarket_price_avg1',
        'cardmarket_price_avg7',
        'cardmarket_price_avg30',
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
            'active' => 'boolean',
            'ct_zero_only' => 'boolean',
            'conditions' => AsEnumCollection::class::of(ConditionType::class),
            'languages' => AsEnumCollection::class::of(Language::class),
            'cardmarket_price_avg' => MoneyCast::class,
            'cardmarket_price_low' => MoneyCast::class,
            'cardmarket_price_trend' => MoneyCast::class,
            'cardmarket_price_avg1' => MoneyCast::class,
            'cardmarket_price_avg7' => MoneyCast::class,
            'cardmarket_price_avg30' => MoneyCast::class,
        ];
    }

    public static function booted(): void
    {
        static::creating(function (TrackedBlueprint $item) {
            $item->user_id = auth()->id();
        });
    }

    public function blueprintExpansion(): BelongsTo
    {
        return $this->belongsTo(Expansion::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function trackedBlueprintNotifications(): HasMany
    {
        return $this->hasMany(TrackedBlueprintNotification::class);
    }
}
