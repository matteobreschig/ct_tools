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
 * codice, `manual` se scelta dall'utente. `ignored`: serie che non esiste su
 * CardTrader (es. Topps); le sue righe restano fuori dalla sincronizzazione.
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
        'ignored',
        'cards_loaded_at',
    ];

    protected function casts(): array
    {
        return [
            'cards_loaded_at' => 'datetime',
            'ignored' => 'boolean',
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
     * @return HasMany<PokecardexSeriesRule, $this>
     */
    public function rules(): HasMany
    {
        return $this->hasMany(PokecardexSeriesRule::class, 'id_serie');
    }

    /**
     * Versioni presenti nelle carte della serie in catalogo, per le regole.
     * Esclusa solo la Standard; Reverse e 1st Edition servono quando
     * CardTrader le tiene in un'espansione a parte.
     *
     * @return array<int, string>
     */
    public function specialVersionOptions(): array
    {
        return PokecardexCard::query()
            ->where('id_serie', $this->id_serie)
            ->get()
            ->flatMap(fn (PokecardexCard $card): array => $card->versions ?? [])
            ->reject(fn (array $version): bool => (int) $version['id_version'] === 1)
            ->mapWithKeys(fn (array $version): array => [(int) $version['id_version'] => $version['nom_version_us'] ?? (string) $version['id_version']])
            ->sortKeys()
            ->all();
    }

    /**
     * @return HasMany<PokecardexCard, $this>
     */
    public function cards(): HasMany
    {
        return $this->hasMany(PokecardexCard::class, 'id_serie');
    }
}
