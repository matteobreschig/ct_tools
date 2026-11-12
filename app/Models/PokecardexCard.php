<?php

namespace App\Models;

use Database\Factories\PokecardexCardFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Carta del catalogo Pokecardex. `versions` è l'elenco delle versioni della
 * carta così come lo dà Pokecardex (`id_version`, `nom_version_us`, `langues`).
 */
class PokecardexCard extends Model
{
    /** @use HasFactory<PokecardexCardFactory> */
    use HasFactory;

    protected $primaryKey = 'id_card';

    public $incrementing = false;

    protected $fillable = [
        'id_card',
        'id_serie',
        'num_card',
        'name_en',
        'id_cardmarket',
        'versions',
    ];

    protected function casts(): array
    {
        return [
            'versions' => 'array',
        ];
    }

    /**
     * @return BelongsTo<PokecardexSeries, $this>
     */
    public function series(): BelongsTo
    {
        return $this->belongsTo(PokecardexSeries::class, 'id_serie');
    }

    /**
     * Numero di collezione senza totale né zeri iniziali: "053/167" → "53".
     */
    public function collectorNumber(): ?string
    {
        return self::normalizeCollectorNumber($this->num_card);
    }

    public static function normalizeCollectorNumber(?string $number): ?string
    {
        $head = trim(explode('/', (string) $number)[0]);

        if ($head === '') {
            return null;
        }

        return ltrim($head, '0') === '' ? '0' : ltrim($head, '0');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function version(int $idVersion): ?array
    {
        return collect($this->versions ?? [])->firstWhere('id_version', $idVersion);
    }

    /**
     * Lingue in cui esiste la versione. Null se Pokecardex non le indica.
     *
     * @return list<int>|null
     */
    public function languagesOf(int $idVersion): ?array
    {
        $languages = $this->version($idVersion)['langues'] ?? null;

        return is_array($languages) ? array_map('intval', $languages) : null;
    }
}
