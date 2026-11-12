<?php

namespace App\Models;

use Database\Factories\PokecardexSeriesFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Serie Pokecardex (catalogo condiviso tra gli utenti) con l'espansione
 * CardTrader corrispondente. `expansion_source`: `auto` se trovata per nome o
 * codice, `manual` se scelta dall'utente.
 */
class PokecardexSeries extends Model
{
    /** @use HasFactory<PokecardexSeriesFactory> */
    use HasFactory;

    protected $primaryKey = 'id_serie';

    public $incrementing = false;

    protected $fillable = [
        'id_serie',
        'lang',
        'code',
        'name_us',
        'id_cardmarket',
        'expansion_id',
        'expansion_source',
        'cards_loaded_at',
    ];

    protected function casts(): array
    {
        return [
            'cards_loaded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Expansion, $this>
     */
    public function expansion(): BelongsTo
    {
        return $this->belongsTo(Expansion::class);
    }

    /**
     * @return HasMany<PokecardexCard, $this>
     */
    public function cards(): HasMany
    {
        return $this->hasMany(PokecardexCard::class, 'id_serie');
    }
}
