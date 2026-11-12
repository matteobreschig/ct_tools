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
        'cardmarket_product_id',
        'cardmarket_product_ids',
    ];

    protected function casts(): array
    {
        return [
            'cardmarket_product_ids' => 'array',
        ];
    }
}
