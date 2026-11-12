<?php

namespace App\Models;

use Database\Factories\CtExpansionBlueprintFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Blueprint di carta singola di un'espansione CardTrader, da
 * `/blueprints/export`. Serve solo agli abbinamenti con Pokecardex: è separata
 * da `ct_blueprints`, le cui righe guidano il recupero dei `meta_name`.
 */
class CtExpansionBlueprint extends Model
{
    /** @use HasFactory<CtExpansionBlueprintFactory> */
    use HasFactory;

    protected $primaryKey = 'blueprint_id';

    public $incrementing = false;

    protected $fillable = [
        'blueprint_id',
        'expansion_ct_id',
        'name',
        'collector_number',
        'version',
        'cardmarket_product_id',
        'cardmarket_product_ids',
    ];

    protected function casts(): array
    {
        return [
            'cardmarket_product_ids' => 'array',
        ];
    }

    /**
     * Variante dichiarata da CardTrader nel campo `version`, prima della barra:
     * "Cosmos Holo | 150/159" → "cosmosholo". Null se il campo non ne indica una.
     */
    public function variantLabel(): ?string
    {
        if (! str_contains((string) $this->version, '|')) {
            return null;
        }

        return PokecardexCard::normalizeVariantLabel(explode('|', $this->version)[0]);
    }
}
