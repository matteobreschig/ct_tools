<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrackedBlueprintNotification extends Model
{
    protected $fillable = [
        'tracked_blueprint_id',
        'product_id',
        'datetime',
        'price',
        'quantity',
        'condition',
        'language',
        'ct_zero',
        'ct_user_name',
        'ct_user_country_code',
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

    public static function booted(): void
    {
        static::creating(function (TrackedBlueprintNotification $item) {
            Notification::make()
                ->title('Il prodotto ' . $item->trackedBlueprint->blueprint_name . ' ha raggiunto il prezzo desiderato!')
                ->body($item->trackedBlueprint->blueprint_name . ' ora è venduto a ' . $item->price . '€ dal venditore ' . $item->ct_user_name)
                ->success()
                ->sendToDatabase($item->trackedBlueprint->user)
                ->send();
        });

        static::updating(function (TrackedBlueprintNotification $item) {
            if ($item->isDirty('price') && $item->status < $item->getOriginal('price')) {
                Notification::make()
                    ->title('Il prodotto ' . $item->trackedBlueprint->blueprint_name . ' è sceso di prezzo!')
                    ->body($item->trackedBlueprint->blueprint_name . ' ora è venduto a ' . $item->price . '€ dal venditore ' . $item->ct_user_name)
                    ->success()
                    ->sendToDatabase($item->trackedBlueprint->user)
                    ->send();
            }
        });
    }

    public function trackedBlueprint(): BelongsTo
    {
        return $this->belongsTo(TrackedBlueprint::class);
    }
}
