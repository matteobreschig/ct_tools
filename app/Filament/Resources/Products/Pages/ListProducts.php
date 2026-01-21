<?php

namespace App\Filament\Resources\Products\Pages;

use App\Enums\ConditionType;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Category;
use App\Models\Expansion;
use App\Models\Product;
use App\Services\CTApiClient;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncronize_with_ct')
                ->label('Sincronizza con CT')
                ->requiresConfirmation()
                ->action(function () {
                    $client = new CTApiClient(Auth::user());

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
                    foreach($expansionsData as $expansionData) {
                        //Al momento CT non filtra per game_id quindi faccio un controllo anche qui
                        if($expansionData['game_id'] != '5') continue;

                        Expansion::updateOrCreate(
                            [
                                'ct_id' => $expansionData['id']
                            ],
                            [
                                'code' => $expansionData['code'],
                                'name' => $expansionData['name'],
                            ]
                        );
                    }

                    $productsData = $client->get('/products/export', ['category_id' => '73'])->json();
                    $ctProductIds = collect($productsData)->pluck('id')->all();

                    $categories = Category::pluck('id', 'ct_id');
                    $expansions = Expansion::pluck('id', 'ct_id');

                    foreach($productsData as $productData) {
                        $product = Product::firstOrNew([
                            'product_id' => $productData['id'],
                            'user_id' => Auth::id(),
                        ]);

                        $product->fill([
                            'name' => $productData['name_en'],
                            'description' => $productData['description'],
                            'quantity' => $productData['quantity'],
                            'blueprint_id' => $productData['blueprint_id'],
                            'category_id' => $categories[$productData['category_id']],
                            'expansion_id' => $expansions[$productData['expansion']['id']],
                            'collector_number' => $productData['properties_hash']['collector_number'] ?? 0,
                            'condition' => isset($productData['properties_hash']['condition'])
                                ? ConditionType::fromApiLabel($productData['properties_hash']['condition'])
                                : ConditionType::NM,
                            'tournament_legal' => $productData['properties_hash']['tournament_legal'] ?? false,
                            'reverse' => $productData['properties_hash']['pokemon_reverse'] ?? false,
                            'language' => $productData['properties_hash']['pokemon_language'] ?? 'it',
                            'signed' => $productData['properties_hash']['signed'] ?? false,
                            'rarity' => $productData['properties_hash']['pokemon_rarity'] ?? 'Common',
                            'altered' => $productData['properties_hash']['altered'] ?? false,
                            'first_edition' => $productData['properties_hash']['first_edition'] ?? false,
                            'last_update' => now(),
                        ]);

                        if ($productData['price_cents'] < config('app.ct_disable_price') * 100) {
                            $product->price = $productData['price_cents'] / 100;
                        }

                        $product->save();
                    }

                    Product::where('user_id', Auth::id())
                        ->whereNotIn('product_id', $ctProductIds)
                        ->delete();
                })
        ];
    }
}
