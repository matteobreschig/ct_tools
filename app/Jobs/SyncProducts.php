<?php

namespace App\Jobs;

use App\Enums\ConditionType;
use App\Models\Category;
use App\Models\CtBlueprint;
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
            $metaNames = CtBlueprint::whereNotNull('meta_name')->pluck('meta_name', 'blueprint_id');
            $blueprintImages = CtBlueprint::whereNotNull('image_url')
                ->where('image_url', '!=', '')
                ->pluck('image_url', 'blueprint_id');

            // Nomi italiani trovati durante questo ciclo, indicizzati per meta_name,
            // e prodotti rimasti col nome inglese da correggere a fine ciclo.
            $italianNamesByMeta = [];
            $pendingTranslation = [];

            foreach($productsData as $productData) {
                $product = Product::firstOrNew([
                    'product_id' => $productData['id'],
                    'user_id' => $this->user->id,
                ]);

                $rawCollectorNumber = trim(explode('/', (string) ($productData['properties_hash']['collector_number'] ?? ''))[0]);
                $collectorNumber = (int) $rawCollectorNumber;

                $tcgdexProduct = null;
                $italianMatch = false;
                $tcgdexCode = $expansionsTcgdex[$productData['expansion']['id']] ?? null;
                if($tcgdexCode) {
                    // Provo più varianti del numero di collezione: quello originale con
                    // eventuali lettere (promo tipo "TG12" o "SWSH123"), quello numerico
                    // e quello con riempimento a 3 cifre.
                    $numberCandidates = array_values(array_unique(array_filter([
                        $rawCollectorNumber,
                        (string) $collectorNumber,
                        str_pad($collectorNumber, 3, '0', STR_PAD_LEFT),
                    ], fn ($number) => $number !== '')));

                    foreach($numberCandidates as $numberCandidate) {
                        if($tcgdexProduct = $tcgdexIt->card->get($tcgdexCode . '-' . $numberCandidate)) {
                            $italianMatch = true;
                            break;
                        }
                    }

                    if(!$tcgdexProduct) {
                        foreach($numberCandidates as $numberCandidate) {
                            if($tcgdexProduct = $tcgdexEn->card->get($tcgdexCode . '-' . $numberCandidate)) {
                                break;
                            }
                        }
                    }
                }

                $cardmarketProductId = $tcgdexProduct?->pricing?->cardmarket?->idProduct;

                $product->fill([
                    'name' => $tcgdexProduct ? $tcgdexProduct->name : $productData['name_en'],
                    'image_url' => $tcgdexProduct?->image ?? $blueprintImages[$productData['blueprint_id']] ?? null,
                    'description' => $productData['description'],
                    'quantity' => $productData['quantity'],
                    'blueprint_id' => $productData['blueprint_id'],
                    'cardmarket_product_id' => $cardmarketProductId,
                    'category_id' => $categories[$productData['category_id']],
                    'expansion_id' => $expansions[$productData['expansion']['id']],
                    'collector_number' => $collectorNumber,
                    'condition' => isset($productData['properties_hash']['condition'])
                        ? ConditionType::fromApiLabel($productData['properties_hash']['condition'])
                        : ConditionType::NM,
                    // Categoria TCGdex localizzata: normalizzo sui tre tipi interni.
                    'card_type' => match ($tcgdexProduct?->category) {
                        'Pokémon', 'Pokemon' => 'pokemon',
                        'Allenatore', 'Trainer' => 'trainer',
                        'Energia', 'Energy' => 'energy',
                        default => null,
                    },
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

                // Imposto il meta_name (chiave di raggruppamento) solo se risolto:
                // non azzero mai un valore esistente, così le unioni manuali
                // sopravvivono alla sincronizzazione.
                $metaName = $metaNames[$productData['blueprint_id']] ?? null;
                if ($metaName !== null) {
                    $product->meta_name = $metaName;
                }

                if ($productData['price_cents'] < config('app.ct_disable_price') * 100) {
                    $product->price = $productData['price_cents'] / 100;
                } elseif (!$product->exists) {
                    // Prodotto nuovo già al prezzo di disattivazione su Cardtrader:
                    // lo registro direttamente come non in vendita.
                    $product->sale = false;
                }

                $product->save();

                // Memorizzo il nome italiano per gruppo di ristampe e segno i prodotti
                // rimasti col nome inglese: a fine ciclo riuso la traduzione trovata
                // su un'altra versione della stessa carta.
                if ($metaName !== null) {
                    if ($italianMatch) {
                        $italianNamesByMeta[$metaName] ??= $product->name;
                    } else {
                        $pendingTranslation[$metaName][] = $product;
                    }
                }
            }

            foreach ($pendingTranslation as $metaName => $products) {
                if (!isset($italianNamesByMeta[$metaName])) {
                    continue;
                }

                foreach ($products as $pendingProduct) {
                    $pendingProduct->update(['name' => $italianNamesByMeta[$metaName]]);
                }
            }

            // Le ristampe senza corrispondenza TCGdex ereditano il tipo carta
            // dalle versioni gemelle.
            Product::backfillCardTypeFromSiblings();

            Product::where('user_id', $this->user->id)
                ->whereNotIn('product_id', $ctProductIds)
                ->delete();

            // Carte nuove con blueprint non ancora mappato: accodo subito il
            // recupero dei meta_name invece di aspettare il giro giornaliero.
            // Il job è unico, accodarlo mentre uno gira non crea doppioni.
            $hasUnmappedBlueprints = Product::query()
                ->whereNotNull('blueprint_id')
                ->whereNotExists(fn ($q) => $q
                    ->selectRaw('1')
                    ->from('ct_blueprints')
                    ->whereColumn('ct_blueprints.blueprint_id', 'products.blueprint_id'))
                ->exists();

            if ($hasUnmappedBlueprints) {
                SyncCardtraderBlueprints::dispatch($this->user);
            }

            // Riallinea la vendita dinamica alle quantità appena sincronizzate
            // (il job esce subito se l'utente non ha configurato minimi).
            ApplyDynamicSale::dispatch($this->user);

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
