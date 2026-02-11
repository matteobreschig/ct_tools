<?php

namespace App\Jobs;

use App\Enums\ConditionType;
use App\Models\Category;
use App\Models\Expansion;
use App\Models\Product;
use App\Services\CTApiClient;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use TCGdex\TCGdex;

class SyncProducts implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;
    public int $tries = 1;

    public $user;

    /**
     * Create a new job instance.
     */
    public function __construct($user)
    {
        $this->user = $user;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $tcgdexEn = new TCGdex("en");
            $tcgdexIt = new TCGdex("it");
            $client = new CTApiClient($this->user);

            $categoriesData = $client->get('/categories')->json();
            foreach($categoriesData as $categoryData) {
                Category::updateOrCreate(
                    [
                        'ct_id' => $categoryData['id']
                    ],
                    [
                        'name' => $categoryData['name'],
                    ]
                );
            }

            $expansionsData = $client->get('/expansions')->json();
            $tcgDexEnSets = $tcgdexEn->set->list();
            foreach($expansionsData as $expansionData) {
                //Al momento CT non filtra per game_id quindi faccio un controllo anche qui
                if($expansionData['game_id'] != '5') continue;

                $tcgDexSet = null;
                foreach($tcgDexEnSets as $tcgDexEnExpansion) {
                    if($tcgDexEnExpansion->name == $expansionData['name']) {
                        if(!$tcgDexSet = $tcgdexIt->set->get($tcgDexEnExpansion->id)) {
                            $tcgDexSet = $tcgdexEn->set->get($tcgDexEnExpansion->id);
                        }
                        break;
                    }
                }

                Expansion::updateOrCreate(
                    [
                        'ct_id' => $expansionData['id']
                    ],
                    [
                        'code' => $expansionData['code'],
                        'name_en' => $expansionData['name'],
                        'name' => $tcgDexSet ? $tcgDexSet->name : $expansionData['name'],
                        'tcgdex_code' => $tcgDexSet?->id,
                        'release_date' => $tcgDexSet?->releaseDate,
                        'image_url' => $tcgDexSet?->logo,
                        'symbol_url' => $tcgDexSet?->symbol,
                    ]
                );
            }

            $productsData = $client->get('/products/export', ['category_id' => '73'])->json();
            $ctProductIds = collect($productsData)->pluck('id')->all();

            $categories = Category::pluck('id', 'ct_id');
            $expansions = Expansion::pluck('id', 'ct_id');
            $expansionsTcgdex = Expansion::pluck('tcgdex_code', 'ct_id');

            foreach($productsData as $productData) {
                $product = Product::firstOrNew([
                    'product_id' => $productData['id'],
                    'user_id' => $this->user->id,
                ]);

                $collectorNumber = 0;
                if($productData['properties_hash']['collector_number']) {
                    $collectorNumber = (int) explode('/', $productData['properties_hash']['collector_number'])[0];
                }

                $tcgdexProduct = null;
                if($expansionsTcgdex[$productData['expansion']['id']]) {
                    if(!$tcgdexProduct = $tcgdexIt->card->get($expansionsTcgdex[$productData['expansion']['id']] . '-' . $collectorNumber)) {
                        if(!$tcgdexProduct = $tcgdexIt->card->get($expansionsTcgdex[$productData['expansion']['id']] . '-' . str_pad($collectorNumber, 3, '0', STR_PAD_LEFT))) {
                            if(!$tcgdexProduct = $tcgdexEn->card->get($expansionsTcgdex[$productData['expansion']['id']] . '-' . $collectorNumber)) {
                                $tcgdexProduct = $tcgdexEn->card->get($expansionsTcgdex[$productData['expansion']['id']] . '-' . str_pad($collectorNumber, 3, '0', STR_PAD_LEFT));
                            }
                        }
                    }
                }

                $product->fill([
                    'name' => $tcgdexProduct ? $tcgdexProduct->name : $productData['name_en'],
                    'image_url' => $tcgdexProduct?->image,
                    'description' => $productData['description'],
                    'quantity' => $productData['quantity'],
                    'blueprint_id' => $productData['blueprint_id'],
                    'cardmarket_product_id' => $tcgdexProduct?->pricing?->cardmarket?->idProduct,
                    'category_id' => $categories[$productData['category_id']],
                    'expansion_id' => $expansions[$productData['expansion']['id']],
                    'collector_number' => $collectorNumber,
                    'condition' => isset($productData['properties_hash']['condition'])
                        ? ConditionType::fromApiLabel($productData['properties_hash']['condition'])
                        : ConditionType::NM,
                    'regulation_mark' => $tcgdexProduct?->regulationMark,
                    'tournament_legal' => $tcgdexProduct?->legal->standard ?? false,
                    'tournament_extended_legal' => $tcgdexProduct?->legal->expanded,
                    'reverse' => $productData['properties_hash']['pokemon_reverse'] ?? false,
                    'language' => $productData['properties_hash']['pokemon_language'] ?? 'it',
                    'signed' => $productData['properties_hash']['signed'] ?? false,
                    'rarity' => $productData['properties_hash']['pokemon_rarity'] ?? 'Common',
                    'altered' => $productData['properties_hash']['altered'] ?? false,
                    'first_edition' => $productData['properties_hash']['first_edition'] ?? false,
                    'cardmarket_price_avg' => $productData['properties_hash']['pokemon_reverse'] ? $tcgdexProduct?->pricing?->cardmarket?->{'avg-holo'} : $tcgdexProduct?->pricing?->cardmarket?->avg,
                    'cardmarket_price_low' => $productData['properties_hash']['pokemon_reverse'] ? $tcgdexProduct?->pricing?->cardmarket?->{'low-holo'} : $tcgdexProduct?->pricing?->cardmarket?->low,
                    'cardmarket_price_trend' => $productData['properties_hash']['pokemon_reverse'] ? $tcgdexProduct?->pricing?->cardmarket?->{'trend-holo'} : $tcgdexProduct?->pricing?->cardmarket?->trend,
                    'cardmarket_price_avg1' => $productData['properties_hash']['pokemon_reverse'] ? $tcgdexProduct?->pricing?->cardmarket?->{'avg1-holo'} : $tcgdexProduct?->pricing?->cardmarket?->avg1,
                    'cardmarket_price_avg7' => $productData['properties_hash']['pokemon_reverse'] ? $tcgdexProduct?->pricing?->cardmarket?->{'avg7-holo'} : $tcgdexProduct?->pricing?->cardmarket?->avg7,
                    'cardmarket_price_avg30' => $productData['properties_hash']['pokemon_reverse'] ? $tcgdexProduct?->pricing?->cardmarket?->{'avg30-holo'} : $tcgdexProduct?->pricing?->cardmarket?->avg30,
                    'last_update' => now(),
                ]);

                if ($productData['price_cents'] < config('app.ct_disable_price') * 100) {
                    $product->price = $productData['price_cents'] / 100;
                }

                $product->save();
            }

            Product::where('user_id', $this->user->id)
                ->whereNotIn('product_id', $ctProductIds)
                ->delete();

            Notification::make()
                ->title('Sincronizzazione completata con successo')
                ->success()
                ->sendToDatabase($this->user);
        }
        catch (\Exception $e) {
            Notification::make()
                ->title('Sincronizzazione completata con successo')
                ->body($e->getMessage())
                ->danger()
                ->sendToDatabase($this->user);
        }
    }
}
