<?php

namespace App\Models;

use Database\Factories\VersionKeepSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Deroga per versione alla vendita dinamica: copie da tenere ferme per
 * questa versione (carta, lingua, condizione, reverse), in aggiunta al
 * minimo del gruppo.
 */
class VersionKeepSetting extends Model
{
    /** @use HasFactory<VersionKeepSettingFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'blueprint_id',
        'language',
        'condition',
        'reverse',
        'keep_quantity',
    ];

    protected function casts(): array
    {
        return [
            'reverse' => 'boolean',
            'keep_quantity' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Chiave di versione di un prodotto, uguale a quella della deroga.
     */
    public static function keyFor(Product $product): string
    {
        return implode(':', [
            $product->blueprint_id,
            $product->language->value,
            $product->condition->value,
            (int) $product->reverse,
        ]);
    }

    public function key(): string
    {
        return implode(':', [
            $this->blueprint_id,
            $this->language,
            $this->condition,
            (int) $this->reverse,
        ]);
    }
}
