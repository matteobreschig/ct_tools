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
     * Pokecardex dà ogni lingua come `{statut, id_langue}`, con statut
     * "confirmed" o "presumed": valgono entrambe.
     *
     * @return list<int>|null
     */
    public function languagesOf(int $idVersion): ?array
    {
        $languages = $this->version($idVersion)['langues'] ?? null;

        if (! is_array($languages)) {
            return null;
        }

        return array_values(array_map(
            fn (mixed $language): int => (int) (is_array($language) ? ($language['id_langue'] ?? 0) : $language),
            $languages,
        ));
    }

    /**
     * Etichetta di variante confrontabile tra i due siti: minuscole, senza
     * parentesi e senza segni. "Non Holo (PPS)" e "Non-Holo" → "nonholo".
     */
    public static function normalizeVariantLabel(?string $label): ?string
    {
        $normalized = preg_replace('/[^a-z]/', '', strtolower(preg_replace('/\(.*?\)/', '', (string) $label)));

        return $normalized === '' ? null : $normalized;
    }

    /**
     * Solo le cifre del numero di collezione, senza zeri iniziali: "180nh",
     * "180h" e "180/182" → "180". Serve a riconoscere i blueprint gemelli.
     */
    public static function collectorDigits(?string $number): ?string
    {
        $digits = ltrim(preg_replace('/\D/', '', explode('/', (string) $number)[0]), '0');

        return $digits === '' ? null : $digits;
    }

    /**
     * Nome confrontabile tra i due siti: solo lettere e cifre, minuscole.
     * "Professor's Research - Professor Sada" e "Professor's Research
     * Professor Sada" → "professorsresearchprofessorsada".
     */
    public static function normalizeName(?string $name): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) $name));
    }
}
