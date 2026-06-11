<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Mappatura blueprint CardTrader -> prodotto Cardmarket, sincronizzata da
 * /blueprints/export. Fonte più completa di TCGdex per i print promo/Prize Pack.
 */
class CtBlueprint extends Model
{
    protected $fillable = [
        'blueprint_id',
        'meta_name',
        'cardmarket_product_id',
        'name',
        'image_url',
    ];
}
