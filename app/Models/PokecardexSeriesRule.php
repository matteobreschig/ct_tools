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
     * Cambiare o togliere una regola rende vecchi gli abbinamenti automatici
     * di quella versione (per regola o per ID Cardmarket): si cancellano e il
     * prossimo giro li ricalcola. Quelli manuali restano.
     */
    protected static function booted(): void
    {
        $forget = function (PokecardexSeriesRule $rule): void {
            PokecardexCardMatch::query()
                ->whereIn('source', ['rule', 'cardmarket'])
                ->whereIn('id_version', array_filter([$rule->id_version, $rule->getOriginal('id_version')]))
                ->whereIn('id_card', PokecardexCard::query()->where('id_serie', $rule->id_serie)->select('id_card'))
                ->delete();
        };

        static::saved($forget);
        static::deleted($forget);
    }

    /**
     * @return BelongsTo<Expansion, $this>
     */
    public function expansion(): BelongsTo
    {
        return $this->belongsTo(Expansion::class);
    }
}
