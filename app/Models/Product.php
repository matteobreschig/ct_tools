<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\ConditionType;
use App\Enums\Language;
use App\Models\Concerns\HasCardImageVariants;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class Product extends Model
{
    use HasCardImageVariants;

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
        'meta_name',
        'category_id',
        'expansion_id',
        'collector_number',
        'condition',
        'card_type',
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
            if (!$item->user_id) {
                $item->user_id = auth()->id();
            }
        });
    }

    /**
     * Eredita il tipo carta dalle altre versioni della stessa carta (stesso
     * meta_name): copre le ristampe assenti da TCGdex, come i Prize Pack.
     * Cardtrader il tipo non lo espone, le versioni gemelle sì.
     */
    public static function backfillCardTypeFromSiblings(): void
    {
        DB::statement('
            UPDATE products p
            JOIN (
                SELECT user_id, meta_name, MAX(card_type) AS card_type
                FROM products
                WHERE meta_name IS NOT NULL AND card_type IS NOT NULL
                GROUP BY user_id, meta_name
            ) src ON src.user_id = p.user_id AND src.meta_name = p.meta_name
            SET p.card_type = src.card_type
            WHERE p.card_type IS NULL
        ');
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
