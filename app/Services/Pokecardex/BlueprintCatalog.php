<?php

namespace App\Services\Pokecardex;

use App\Models\CtExpansionBlueprint;
use App\Models\Expansion;
use App\Services\CTApiClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * Blueprint delle carte singole di un'espansione CardTrader, da
 * `/blueprints/export`, con i loro ID Cardmarket. Ogni espansione si riscarica
 * solo se ha più di un giorno.
 *
 * Per alcune espansioni CardTrader prepara l'export in background: la prima
 * richiesta risponde 404 "Data is not ready" e i dati arrivano più tardi.
 */
final class BlueprintCatalog
{
    private const MAX_AGE_HOURS = 24;

    /** Categoria CardTrader delle carte Pokémon singole. */
    private const SINGLE_CARDS_CATEGORY = 73;

    public function __construct(private CTApiClient $client) {}

    /**
     * Carica i blueprint dell'espansione se mancano o hanno più di un giorno.
     * False se CardTrader non ha ancora pronti i dati: si riprova più tardi.
     */
    public function load(Expansion $expansion): bool
    {
        if ($expansion->blueprints_loaded_at?->isAfter(now()->subHours(self::MAX_AGE_HOURS))) {
            return true;
        }

        // Sotto il limite di CardTrader quando si caricano molte espansioni di fila.
        Sleep::for(200)->milliseconds();

        try {
            $blueprints = $this->client->get('/blueprints/export', ['expansion_id' => $expansion->ct_id])->json();
        } catch (RequestException $exception) {
            if ($exception->response->status() === 404 && str_contains($exception->response->body(), 'not ready')) {
                return false;
            }

            throw $exception;
        }

        if (! is_array($blueprints)) {
            throw new RuntimeException("blueprints/export: risposta inattesa per l'espansione {$expansion->ct_id}.");
        }

        $now = now();

        $rows = collect($blueprints)
            ->filter(fn (array $blueprint): bool => ($blueprint['category_id'] ?? null) === self::SINGLE_CARDS_CATEGORY)
            ->map(fn (array $blueprint): array => [
                'blueprint_id' => (int) $blueprint['id'],
                'expansion_ct_id' => $expansion->ct_id,
                'name' => $blueprint['name'] ?? null,
                'collector_number' => $blueprint['fixed_properties']['collector_number'] ?? null,
                'cardmarket_product_id' => $blueprint['card_market_ids'][0] ?? null,
                'cardmarket_product_ids' => json_encode($blueprint['card_market_ids'] ?? []),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        foreach ($rows->chunk(500) as $chunk) {
            CtExpansionBlueprint::upsert(
                $chunk->values()->all(),
                ['blueprint_id'],
                ['expansion_ct_id', 'name', 'collector_number', 'cardmarket_product_id', 'cardmarket_product_ids', 'updated_at'],
            );
        }

        $expansion->forceFill(['blueprints_loaded_at' => $now])->save();

        return true;
    }
}
