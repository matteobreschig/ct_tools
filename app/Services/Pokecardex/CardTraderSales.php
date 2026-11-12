<?php

namespace App\Services\Pokecardex;

use App\Enums\ConditionType;
use App\Enums\Language;
use App\Services\CTApiClient;
use Illuminate\Support\Sleep;

/**
 * Storico delle vendite CardTrader dell'utente (`/orders` come venditore),
 * raggruppato per chiave carta. Serve al primo giro, che non ha una base: una
 * copia venduta su CardTrader e rimasta su Pokecardex va tolta da Pokecardex,
 * non reinserita su CardTrader.
 */
final class CardTraderSales
{
    private const PAGE_SIZE = 100;

    /** Carte Pokémon singole. */
    private const SINGLE_CARDS_CATEGORY = 73;

    public function __construct(private CTApiClient $client) {}

    /**
     * Copie vendute per chiave carta, dalla vendita più recente. Esclusi gli
     * ordini annullati o non pagati e le carte firmate o alterate.
     *
     * @return array<string, list<array{quantity: int, paid_at: string, order: string}>>
     */
    public function soldByKey(): array
    {
        $sold = [];

        foreach ($this->orders() as $order) {
            if (($order['cancelled_at'] ?? null) !== null || ($order['paid_at'] ?? null) === null) {
                continue;
            }

            foreach ($order['order_items'] ?? [] as $item) {
                $key = $this->key($item);

                if ($key !== null) {
                    $sold[$key->id()][] = [
                        'quantity' => (int) $item['quantity'],
                        'paid_at' => substr((string) $order['paid_at'], 0, 10),
                        'order' => (string) ($order['code'] ?? $order['id']),
                    ];
                }
            }
        }

        foreach ($sold as $keyId => $sales) {
            usort($sales, fn (array $first, array $second): int => strcmp($second['paid_at'], $first['paid_at']));
            $sold[$keyId] = $sales;
        }

        return $sold;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function orders(): array
    {
        $orders = [];
        $page = 1;

        do {
            if ($page > 1) {
                Sleep::for(200)->milliseconds();
            }

            $batch = $this->client->get('/orders', [
                'order_as' => 'seller',
                'sort' => 'date.desc',
                'limit' => self::PAGE_SIZE,
                'page' => $page,
            ])->json();

            $batch = is_array($batch) ? $batch : [];
            $orders = [...$orders, ...$batch];
            $page++;
        } while (count($batch) === self::PAGE_SIZE);

        return $orders;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function key(array $item): ?CardKey
    {
        $properties = $item['properties'] ?? [];

        if (($item['category_id'] ?? null) !== self::SINGLE_CARDS_CATEGORY
            || ($properties['signed'] ?? false)
            || ($properties['altered'] ?? false)) {
            return null;
        }

        $condition = ConditionType::tryFrom((string) ConditionType::fromApiLabel($properties['condition'] ?? null));
        $language = Language::tryFrom((string) ($properties['pokemon_language'] ?? ''));

        if ($condition === null || $language === null || ! isset($item['blueprint_id'])) {
            return null;
        }

        return new CardKey(
            (int) $item['blueprint_id'],
            $condition,
            $language,
            (bool) ($properties['pokemon_reverse'] ?? false),
            (bool) ($properties['first_edition'] ?? false),
        );
    }
}
