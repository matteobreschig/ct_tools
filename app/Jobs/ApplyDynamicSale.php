<?php

namespace App\Jobs;

use App\Exceptions\ApiRateLimitException;
use App\Models\CardGroup;
use App\Models\CardGroupSetting;
use App\Models\Product;
use App\Models\User;
use App\Services\CTApiClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;

/**
 * Vendita dinamica: per ogni gruppo di carte tiene ferme le copie minime
 * richieste (deroga del gruppo o minimo globale dell'utente) e mette in
 * vendita il resto, spingendo i prezzi a Cardtrader in un'unica chiamata.
 */
class ApplyDynamicSale implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    /**
     * Una sola istanza per utente in coda o in esecuzione: il job è lanciato
     * da più punti (fine sincronizzazione, pulsante "Applica adesso") e crea
     * inserzioni su Cardtrader, quindi due copie concorrenti le duplicherebbero.
     * Finestra allineata al timeout, oltre il lock si libera comunque.
     */
    public int $uniqueFor = 300;

    public function uniqueId(): string
    {
        return (string) $this->user->id;
    }

    /**
     * Cache per esecuzione dei prezzi minimi di mercato Cardtrader,
     * indicizzata per blueprint + condizione + lingua + reverse.
     *
     * @var array<string, float|null>
     */
    protected array $marketplacePrices = [];

    public function __construct(public User $user) {}

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
                // valore: prezzo Cardmarket, altrimenti quello dell'inserzione
                // (MoneyCast salva i prezzi mancanti come 0, quindi lo zero va
                // trattato come sconosciuto, mai come "carta che non vale nulla").
                // Secondo criterio l'id, così l'ordine resta stabile a parità.
                $products = $group->products()
                    ->orderByRaw('CASE
                        WHEN cardmarket_price_low > 0 THEN cardmarket_price_low
                        WHEN price > 0 THEN price
                        ELSE 0 END')
                    ->orderBy('id')
                    ->get();

                $keep = $overrides[$group->group_key]
                    ?? $this->user->keepQuantityForType($products->first()?->card_type);

                if ($keep === null) {
                    return;
                }

                $kept = 0;
                foreach ($products as $product) {
                    // Blocco manuale: lo stato di vendita resta quello impostato
                    // a mano. Fuori dal conteggio del minimo: le copie automatiche
                    // si gestiscono a parte, le bloccate sono "extra".
                    if ($product->sale_locked) {
                        continue;
                    }

                    $sell = $kept >= $keep;

                    if (! $sell) {
                        $kept += $product->quantity;

                        // Riga di confine con copie in eccesso: divido
                        // l'inserzione, l'eccedenza diventa una nuova inserzione
                        // in vendita. Se la divisione non riesce la riga resta
                        // tutta ferma (mai sotto il minimo) e riprovo al giro dopo.
                        $excess = $kept - $keep;
                        $salePrice = $this->resolveSalePrice($client, $product);

                        if ($excess > 0 && $salePrice > 0) {
                            try {
                                $this->splitListing($client, $product, $excess, $salePrice);
                                $kept = $keep;
                            } catch (RequestException|ApiRateLimitException) {
                                // Riga intera ferma, niente di pubblicato a metà.
                            }
                        }
                    }

                    // Senza un prezzo proprio pubblico al trend Cardmarket o,
                    // in mancanza, al minimo del marketplace Cardtrader.
                    // Senza nessuna fonte l'inserzione non è pubblicabile.
                    if ($sell && $product->price <= 0) {
                        $resolved = $this->resolveSalePrice($client, $product);

                        if ($resolved === null) {
                            continue;
                        }

                        $product->price = $resolved;
                    }

                    $product->sale = $sell;
                    $product->save();

                    // Oltre al prezzo di disattivazione, il tag vacanza: con la
                    // modalità vacanza per tag attiva su Cardtrader le inserzioni
                    // ferme spariscono dagli acquisti restando nel magazzino.
                    $ctData['products'][] = [
                        'id' => $product->product_id,
                        'price' => $sell ? $product->price : config('app.ct_disable_price'),
                        'tag' => $sell ? null : config('app.ct_vacation_tag'),
                    ];
                }
            });

        if ($ctData['products'] !== []) {
            $client->post('/products/bulk_update', $ctData);
        }
    }

    /**
     * Prezzo a cui pubblicare il prodotto: il suo, altrimenti il trend
     * Cardmarket, altrimenti il minimo del marketplace Cardtrader per la
     * stessa carta in pari condizione, lingua e reverse (copre le stampe
     * senza prezzi Cardmarket, come i Prize Pack). Null = nessuna fonte.
     */
    protected function resolveSalePrice(CTApiClient $client, Product $product): ?float
    {
        if ($product->price > 0) {
            return $product->price;
        }

        if ($product->cardmarket_price_trend > 0) {
            return $product->cardmarket_price_trend;
        }

        $cacheKey = implode(':', [
            $product->blueprint_id,
            $product->condition->value,
            $product->language->value,
            (int) $product->reverse,
        ]);

        if (array_key_exists($cacheKey, $this->marketplacePrices)) {
            return $this->marketplacePrices[$cacheKey];
        }

        try {
            $listings = $client->get('/marketplace/products', ['blueprint_id' => $product->blueprint_id])
                ->json()[$product->blueprint_id] ?? [];
        } catch (RequestException|ApiRateLimitException) {
            // Niente cache sull'errore: ritento al prossimo giro.
            return null;
        }

        $listings = collect($listings)
            ->filter(fn (array $listing) => (bool) ($listing['properties_hash']['pokemon_reverse'] ?? false) === $product->reverse);

        $sameCondition = fn (array $listing) => ($listing['properties_hash']['condition'] ?? null) === $product->condition->getApiLabel();
        $language = fn (string $language) => fn (array $listing) => ($listing['properties_hash']['pokemon_language'] ?? null) === $language;

        // Scala di ripieghi: stessa condizione e lingua, poi stessa condizione
        // in inglese, poi stessa condizione in qualunque lingua, infine
        // qualunque inserzione (sempre a pari reverse, i prezzi differiscono).
        $levels = [
            $listings->filter($sameCondition)->filter($language($product->language->value)),
            $listings->filter($sameCondition)->filter($language('en')),
            $listings->filter($sameCondition),
            $listings,
        ];

        $cents = null;
        foreach ($levels as $level) {
            $cents = $level->min(fn (array $listing) => $listing['price']['cents'] ?? null);

            if ($cents > 0) {
                break;
            }
        }

        return $this->marketplacePrices[$cacheKey] = $cents > 0 ? round($cents / 100, 2) : null;
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
        $client->put('/products/'.$product->product_id, [
            'quantity' => $product->quantity - $excess,
            'price' => config('app.ct_disable_price'),
            'tag' => config('app.ct_vacation_tag'),
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
