<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Concerns\HasCardImageVariants;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Carta generica: una riga per ogni metacard Cardmarket posseduto da un utente.
 * Modello in sola lettura mappato sulla vista SQL `card_groups`.
 */
class CardGroup extends Model
{
    use HasCardImageVariants;

    protected $table = 'card_groups';

    protected $primaryKey = 'group_key';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'versions_count' => 'integer',
            'total_quantity' => 'integer',
            'on_sale_quantity' => 'integer',
            'price_low' => MoneyCast::class,
        ];
    }

    /**
     * Le copie possedute dall'utente per questo gruppo (le varie versioni).
     * Raggruppa per metacard quando presente, altrimenti per blueprint.
     */
    public function products(): HasMany
    {
        $foreignKey = $this->group_type === 'blueprint'
            ? 'blueprint_id'
            : 'meta_name';

        return $this->hasMany(Product::class, $foreignKey, $foreignKey)
            ->where('user_id', $this->user_id);
    }
}
