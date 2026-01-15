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
use Illuminate\Support\Carbon;
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
                    foreach($productsData as $productData) {
                        Product::updateOrCreate(
                            [
                                'product_id' => $productData['id']
                            ],
                            [
                                'user_id' => Auth::user()->id,
                                'name' => $productData['name_en'],
                                'description' => $productData['description'],
                                'quantity' => $productData['quantity'],
                                'price' => $productData['price_cents'] / 100,
                                'blueprint_id' => $productData['blueprint_id'],
                                'category_id' => Category::where('ct_id', $productData['category_id'])->first()->id,
                                'expansion_id' => Expansion::where('ct_id', $productData['expansion']['id'])->first()->id,
                                'collector_number' => $productData['properties_hash']['collector_number'] ?? 0,
                                'condition' => isset($productData['properties_hash']['condition']) ? ConditionType::fromApiLabel($productData['properties_hash']['condition']) : ConditionType::NM,
                                'tournament_legal' => $productData['properties_hash']['tournament_legal'] ?? false,
                                'reverse' => $productData['properties_hash']['pokemon_reverse'] ?? false,
                                'language' => $productData['properties_hash']['pokemon_language'] ?? 'it',
                                'signed' => $productData['properties_hash']['signed'] ?? false,
                                'rarity' => $productData['properties_hash']['pokemon_rarity'] ?? 'Common',
                                'altered' => $productData['properties_hash']['altered'] ?? false,
                                'first_edition' => $productData['properties_hash']['first_edition'] ?? false,
                                'last_update' => Carbon::now(),
                            ]
                        );
                    }
                })
        ];
    }
}
