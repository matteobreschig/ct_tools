<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrackedBlueprintNotification extends Model
{
    protected $fillable = [
        'tracked_blueprint_id',
        'price',
        'datetime',
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
        ];
    }

    public function trackedBlueprint(): BelongsTo
    {
        return $this->belongsTo(TrackedBlueprint::class);
    }
}
