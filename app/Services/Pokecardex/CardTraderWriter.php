<?php

namespace App\Services\Pokecardex;

use App\Models\Product;
use App\Services\CTApiClient;
use RuntimeException;

/**
 * Scritture su CardTrader per una chiave carta (§ 4.3 del design).
 *
 * - Aumento: più copie sull'inserzione ferma della chiave, se c'è; altrimenti
 *   una nuova inserzione ferma (prezzo di disattivazione e tag vacanza). A fine
 *   giro la vendita dinamica decide quante metterne in vendita.
 * - Diminuzione: prima dalle inserzioni in vendita, poi da quelle ferme
 *   (decisione dell'utente). Un'inserzione che arriva a zero si cancella.
 *
 * Non aggiorna la tabella `products`: il giro la rilegge dopo le scritture.
 */
class CardTraderWriter
{
    public function __construct(private CTApiClient $client) {}

    public function write(KeyChange $change): void
    {
        $current = $change->state->cardtrader;
        $target = $change->quantityAfter();

        if ($target > $current) {
            $this->add($change->state, $target - $current);
        } elseif ($target < $current) {
            $this->remove($change->state, $current - $target);
        }
    }

    private function add(KeyState $state, int $copies): void
    {
        $frozen = collect($state->products)->first(fn (Product $product): bool => ! $product->sale);

        if ($frozen !== null) {
            $this->client->put('/products/'.$frozen->product_id, [
                'quantity' => $frozen->quantity + $copies,
                'price' => config('app.ct_disable_price'),
                'tag' => config('app.ct_vacation_tag'),
            ]);

            return;
        }

        $created = $this->client->post('/products', [
            'blueprint_id' => $state->key->blueprintId,
            'price' => config('app.ct_disable_price'),
            'quantity' => $copies,
            'properties' => [
                'condition' => $state->key->condition->getApiLabel(),
                'pokemon_language' => $state->key->language->value,
                'pokemon_reverse' => $state->key->reverse,
                'first_edition' => $state->key->firstEdition,
                'signed' => false,
                'altered' => false,
            ],
        ])->json();

        $productId = $created['resource']['id'] ?? null;

        if ($productId === null) {
            throw new RuntimeException("CardTrader non ha restituito l'inserzione creata per il blueprint {$state->key->blueprintId}.");
        }

        // CardTrader può fondere la nuova inserzione con una identica: il tag
        // vacanza va comunque sull'inserzione restituita.
        $this->client->put('/products/'.$productId, [
            'price' => config('app.ct_disable_price'),
            'tag' => config('app.ct_vacation_tag'),
        ]);
    }

    private function remove(KeyState $state, int $copies): void
    {
        $listings = collect($state->products)->sortBy(fn (Product $product): int => $product->sale ? 0 : 1)->values();

        foreach ($listings as $product) {
            if ($copies === 0) {
                break;
            }

            $taken = min($product->quantity, $copies);
            $copies -= $taken;

            if ($taken === $product->quantity) {
                $this->client->delete('/products/'.$product->product_id);

                continue;
            }

            $this->client->put('/products/'.$product->product_id, [
                'quantity' => $product->quantity - $taken,
                'price' => $product->sale ? $product->price : config('app.ct_disable_price'),
                'tag' => $product->sale ? null : config('app.ct_vacation_tag'),
            ]);
        }

        if ($copies > 0) {
            throw new RuntimeException("Mancano {$copies} copie da togliere per il blueprint {$state->key->blueprintId}.");
        }
    }
}
