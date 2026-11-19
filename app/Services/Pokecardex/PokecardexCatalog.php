<?php

namespace App\Services\Pokecardex;

use App\Exceptions\PokecardexException;
use App\Models\Expansion;
use App\Models\PokecardexCard;
use App\Models\PokecardexSeries;
use App\Services\PokecardexClient;

/**
 * Catalogo Pokecardex locale (§ 5.4 del design): serie e carte, caricate su
 * richiesta dentro il giro manuale. Le carte di una serie si riscaricano solo
 * se hanno più di un giorno.
 */
final class PokecardexCatalog
{
    private const CARDS_MAX_AGE_HOURS = 24;

    public function __construct(private PokecardexClient $client) {}

    /**
     * Aggiorna l'elenco delle serie e abbina a un'espansione CardTrader quelle
     * che non ne hanno ancora una. Restituisce il numero di serie lette.
     */
    public function refreshSeries(): int
    {
        $series = collect($this->client->series()['blocs'] ?? [])
            ->flatMap(fn (array $bloc): array => $bloc['series'] ?? []);

        if ($series->isEmpty()) {
            throw new PokecardexException('series: nessuna serie nella risposta.');
        }

        foreach ($series as $item) {
            PokecardexSeries::updateOrCreate(
                ['id_serie' => (int) $item['id_serie']],
                [
                    'lang' => 'en',
                    'code' => $item['nom_court'] ?? null,
                    'name_us' => $item['nom_us'] ?? null,
                    'id_cardmarket' => filled($item['id_cardmarket'] ?? null) ? (int) $item['id_cardmarket'] : null,
                ],
            );
        }

        $this->linkExpansions();

        return $series->count();
    }

    /**
     * Abbina le serie senza espansione: codice uguale (`nom_court` = `code`) o
     * nome inglese uguale, senza distinguere maiuscole. Solo se il risultato è
     * uno. Le serie abbinate a mano non si toccano mai.
     */
    public function linkExpansions(): void
    {
        $expansions = Expansion::query()->get(['id', 'code', 'name', 'name_en']);

        PokecardexSeries::query()->whereNull('expansion_id')->each(function (PokecardexSeries $series) use ($expansions): void {
            $candidates = $expansions->filter(fn (Expansion $expansion): bool => $this->sameText($expansion->code, $series->code)
                || $this->sameText($expansion->name_en, $series->name_us)
                || $this->sameText($expansion->name, $series->name_us));

            if ($candidates->count() === 1) {
                $series->update(['expansion_id' => $candidates->first()->id, 'expansion_source' => 'auto']);
            }
        });
    }

    /**
     * Carica le carte della serie se mancano o hanno più di un giorno.
     */
    public function loadCards(PokecardexSeries $series): void
    {
        if ($series->cards_loaded_at?->isAfter(now()->subHours(self::CARDS_MAX_AGE_HOURS))) {
            return;
        }

        $cards = $this->client->seriesCards($series->id_serie, $series->lang)['cartes'] ?? null;

        if (! is_array($cards)) {
            throw new PokecardexException("get_cartes: risposta senza carte per la serie {$series->id_serie}.");
        }

        $this->remember($cards);

        $series->forceFill(['cards_loaded_at' => now()])->save();
    }

    /**
     * Salva nel catalogo le carte ricevute da Pokecardex (anche quelle che
     * arrivano con la collezione).
     *
     * @param  list<array<string, mixed>>  $cards
     */
    public function remember(array $cards): void
    {
        $now = now();

        $rows = collect($cards)->map(fn (array $card): array => [
            'id_card' => (int) $card['id_card'],
            'id_serie' => (int) $card['id_serie'],
            'num_card' => $card['num_card'] ?? null,
            'name_en' => $card['name_card_en'] ?? null,
            'id_cardmarket' => filled($card['id_cardmarket'] ?? null) ? (int) $card['id_cardmarket'] : null,
            'versions' => json_encode(collect($card['versions'] ?? [])->map(fn (array $version): array => [
                'id_version' => (int) $version['id_version'],
                'nom_version_us' => $version['nom_version_us'] ?? null,
                'langues' => $version['langues'] ?? null,
            ])->values()->all()),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ($rows->chunk(500) as $chunk) {
            PokecardexCard::upsert(
                $chunk->values()->all(),
                ['id_card'],
                ['id_serie', 'num_card', 'name_en', 'id_cardmarket', 'versions', 'updated_at'],
            );
        }
    }

    private function sameText(?string $first, ?string $second): bool
    {
        return filled($first) && filled($second) && mb_strtolower(trim($first)) === mb_strtolower(trim($second));
    }
}
