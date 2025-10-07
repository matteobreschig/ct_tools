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
        'blueprint_expansion_code',
        'blueprint_collector_number',
        'active',
        'price',
        'minimum_quantity',
        'conditions',
        'languages',
        'ct_zero_only',
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
        ];
    }

    public static function booted(): void
    {
        static::addGlobalScope('user_id', function (Builder $builder) {
            $builder->where('user_id' , auth()->id());
        });

        static::creating(function (TrackedBlueprint $item) {
            $item->user_id = auth()->id();
        });
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
