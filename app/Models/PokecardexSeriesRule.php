<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Regola per una versione speciale (§ 5.3 del design): la versione
 * `id_version` della serie `id_serie` sta su CardTrader nell'espansione
 * `expansion_id`, con lo stesso numero di collezione.
 */
class PokecardexSeriesRule extends Model
{
    protected $fillable = [
        'id_serie',
        'id_version',
        'expansion_id',
    ];

    /**
     * @return BelongsTo<Expansion, $this>
     */
    public function expansion(): BelongsTo
    {
        return $this->belongsTo(Expansion::class);
    }
}
