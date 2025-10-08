<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrackedBlueprintNotification extends Model
{
    protected $fillable = [
        'tracked_blueprint_id',
        'datetime',
        'price',
        'quantity',
        'condition',
        'language',
        'ct_zero',
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
            'datetime' => 'datetime',
            'ct_zero' => 'boolean',
        ];
    }

    public function trackedBlueprint(): BelongsTo
    {
        return $this->belongsTo(TrackedBlueprint::class);
    }
}
