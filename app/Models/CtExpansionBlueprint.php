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
        'reverse_choosable',
        'cardmarket_product_id',
        'cardmarket_product_ids',
    ];

    protected function casts(): array
    {
        return [
            'reverse_choosable' => 'boolean',
            'cardmarket_product_ids' => 'array',
        ];
    }

    /**
     * Blueprint su cui CardTrader decide da solo il reverse: `pokemon_reverse`
     * non è tra le proprietà modificabili e il valore inviato viene ignorato
     * (le "Cosmos Holo" diventano reverse, le altre normali). Lì reverse e
     * normale sono la stessa inserzione.
     *
     * @return array<int, true>
     */
    public static function fixedReverseIds(): array
    {
        return self::query()
            ->where('reverse_choosable', false)
            ->pluck('blueprint_id')
            ->mapWithKeys(fn (int $id): array => [$id => true])
            ->all();
    }

    /**
     * Numero di collezione, o quello scritto nel campo `version` dopo la barra
     * quando CardTrader lascia vuoto il numero: "Holo | MEE 009" → "MEE 009".
     */
    public function displayedNumber(): ?string
    {
        if (filled($this->collector_number)) {
            return $this->collector_number;
        }

        if (! str_contains((string) $this->version, '|')) {
            return null;
        }

        $number = trim(explode('|', $this->version, 2)[1]);

        return $number === '' ? null : $number;
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
