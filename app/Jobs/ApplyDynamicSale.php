<?php

namespace App\Jobs;

use App\Exceptions\ApiRateLimitException;
use App\Models\CardGroup;
use App\Models\CardGroupSetting;
use App\Models\Product;
use App\Models\User;
use App\Services\CTApiClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;

/**
 * Vendita dinamica: per ogni gruppo di carte tiene ferme le copie minime
 * richieste (deroga del gruppo o minimo globale dell'utente) e mette in
 * vendita il resto, spingendo i prezzi a Cardtrader in un'unica chiamata.
 */
class ApplyDynamicSale implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public function __construct(public User $user)
    {
    }

    public function handle(): void
    {
        $hasMinimums = $this->user->keep_quantity !== null
            || $this->user->keep_quantity_pokemon !== null
            || $this->user->keep_quantity_trainer !== null
            || $this->user->keep_quantity_energy !== null;
        $overrides = CardGroupSetting::where('user_id', $this->user->id)
            ->pluck('keep_quantity', 'group_key');

        if (! $hasMinimums && $overrides->isEmpty()) {
            return;
        }

        $ctData = ['products' => []];
        $client = new CTApiClient($this->user);

        CardGroup::where('user_id', $this->user->id)
            ->get()
            ->each(function (CardGroup $group) use ($overrides, $client, &$ctData) {
                // Tengo le copie più economiche e vendo prima quelle di maggior
                // valore. Secondo criterio l'id, così l'ordine resta stabile a
                // parità di prezzo.
                $products = $group->products()
                    ->orderByRaw('COALESCE(cardmarket_price_low, price, 0)')
                    ->orderBy('id')
                    ->get();

                $keep = $overrides[$group->group_key]
                    ?? $this->user->keepQuantityForType($products->first()?->card_type);

                if ($keep === null) {
                    return;
                }

                $kept = 0;
                foreach ($products as $product) {
                    $sell = $kept >= $keep;

                    if (! $sell) {
                        $kept += $product->quantity;

                        // Riga di confine con copie in eccesso: divido
                        // l'inserzione, l'eccedenza diventa una nuova inserzione
                        // in vendita. Se la divisione non riesce la riga resta
                        // tutta ferma (mai sotto il minimo) e riprovo al giro dopo.
                        $excess = $kept - $keep;
                        $salePrice = $product->price > 0 ? $product->price : $product->cardmarket_price_trend;

                        if ($excess > 0 && $salePrice > 0) {
                            try {
                                $this->splitListing($client, $product, $excess, $salePrice);
                                $kept = $keep;
                            } catch (RequestException|ApiRateLimitException) {
                                // Riga intera ferma, niente di pubblicato a metà.
                            }
                        }
                    }

                    // Senza un prezzo proprio pubblico al prezzo trend Cardmarket.
                    if ($sell && $product->price <= 0 && $product->cardmarket_price_trend > 0) {
                        $product->price = $product->cardmarket_price_trend;
                    }

                    // Senza prezzo né trend l'inserzione non è pubblicabile.
                    if ($sell && $product->price <= 0) {
                        continue;
                    }

                    // Spingo anche le righe già in vendita a cui ho appena
                    // assegnato il prezzo trend.
                    if ($product->sale === $sell && ! $product->isDirty('price')) {
                        continue;
                    }

                    $product->sale = $sell;
                    $product->save();

                    $ctData['products'][] = [
                        'id' => $product->product_id,
                        'price' => $sell ? $product->price : config('app.ct_disable_price'),
                    ];
                }
            });

        if ($ctData['products'] !== []) {
            $client->post('/products/bulk_update', $ctData);
        }
    }

    /**
     * Divide un'inserzione di confine: riduce quella esistente alle copie da
     * tenere (al prezzo di disattivazione, anche per evitare che Cardtrader
     * fonda le due inserzioni) e pubblica l'eccedenza come nuova inserzione.
     */
    protected function splitListing(CTApiClient $client, Product $product, int $excess, float $salePrice): void
    {
        // Prima la riduzione: se la creazione poi fallisce restano copie non
        // pubblicate, mai copie offerte due volte.
        $client->put('/products/' . $product->product_id, [
            'quantity' => $product->quantity - $excess,
            'price' => config('app.ct_disable_price'),
        ]);

        $created = $client->post('/products', [
            'blueprint_id' => $product->blueprint_id,
            'price' => $salePrice,
            'quantity' => $excess,
            'properties' => [
                'condition' => $product->condition->getApiLabel(),
                'pokemon_language' => $product->language->value,
                'pokemon_reverse' => $product->reverse,
                'signed' => $product->signed,
                'altered' => $product->altered,
                'first_edition' => $product->first_edition,
            ],
        ])->json();

        $product->quantity -= $excess;
        $product->sale = false;
        $product->save();

        // Cardtrader può aver fuso la nuova inserzione con una identica già in
        // vendita: allineo la riga locale all'inserzione restituita, esistente o nuova.
        $resource = $created['resource'] ?? null;
        if ($resource) {
            Product::updateOrCreate(
                [
                    'product_id' => $resource['id'],
                    'user_id' => $product->user_id,
                ],
                array_merge($product->only([
                    'name', 'image_url', 'description', 'blueprint_id', 'cardmarket_product_id',
                    'meta_name', 'category_id', 'expansion_id', 'collector_number', 'condition',
                    'card_type', 'regulation_mark', 'tournament_legal', 'tournament_extended_legal',
                    'reverse', 'language', 'signed', 'rarity', 'altered', 'first_edition',
                    'cardmarket_price_avg', 'cardmarket_price_low', 'cardmarket_price_trend',
                    'cardmarket_price_avg1', 'cardmarket_price_avg7', 'cardmarket_price_avg30',
                ]), [
                    'quantity' => $resource['quantity'],
                    'price' => $salePrice,
                    'sale' => true,
                    'last_update' => now(),
                ]),
            );
        }
    }
}
