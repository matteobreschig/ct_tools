<?php

namespace App\Filament\Resources\TrackedBlueprints\Schemas;

use App\Enums\ConditionType;
use App\Enums\Language;
use App\Models\Expansion;
use App\Services\CTApiClient;
use DOMDocument;
use DOMXPath;
use Exception;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use TCGdex\TCGdex;

class TrackedBlueprintForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components(array_merge(self::getStepOneSchema(), self::getStepTwoSchema(), self::getStepThreeSchema()))
            ->columns(3);
    }

    public static function getStepOneSchema(): array
    {
        return [
            TextInput::make('blueprint_url')
                ->label('URL')
                ->url()
                ->required()
                ->live()
                ->afterStateUpdated(function ($state, Set $set) {
                    try {
                        $html = file_get_contents($state);
                        $dom = new DomDocument();
                        @$dom->loadHTML($html);
                        $xp = new domxpath($dom);
                        $jsonScripts = $xp->query('//script[@type="application/ld+json"]');
                        $json = trim($jsonScripts->item(0)->nodeValue);
                        $data = json_decode($json, true);
                        $set('blueprint_id', $data['sku']);
                        $set('blueprint_name', $data['name']);
                        $set('price', $data['offers']['price']);

                        //Chiamata API per gli altri dati
                        $tcgdexEn = new TCGdex("en");
                        $tcgdexIt = new TCGdex("it");
                        $client = new CTApiClient(Auth::user());
                        $expansions = Expansion::pluck('id', 'ct_id');
                        $expansionsTcgdex = Expansion::pluck('tcgdex_code', 'ct_id');

                        $responseData = $client->get('/marketplace/products', ['blueprint_id' => $data['sku'], 'language' => config('app.locale')])->json();
                        if($responseData) {
                            $collectorNumber = 0;
                            if($responseData[$data['sku']][0]['properties_hash']['collector_number']) {
                                $collectorNumber = (int) explode('/', $responseData[$data['sku']][0]['properties_hash']['collector_number'])[0];
                            }

                            $tcgdexProduct = null;
                            if($expansionsTcgdex[$responseData[$data['sku']][0]['expansion']['id']]) {
                                if(!$tcgdexProduct = $tcgdexIt->card->get($expansionsTcgdex[$responseData[$data['sku']][0]['expansion']['id']] . '-' . $collectorNumber)) {
                                    if(!$tcgdexProduct = $tcgdexIt->card->get($expansionsTcgdex[$responseData[$data['sku']][0]['expansion']['id']] . '-' . str_pad($collectorNumber, 3, '0', STR_PAD_LEFT))) {
                                        if(!$tcgdexProduct = $tcgdexEn->card->get($expansionsTcgdex[$responseData[$data['sku']][0]['expansion']['id']] . '-' . $collectorNumber)) {
                                            $tcgdexProduct = $tcgdexEn->card->get($expansionsTcgdex[$responseData[$data['sku']][0]['expansion']['id']] . '-' . str_pad($collectorNumber, 3, '0', STR_PAD_LEFT));
                                        }
                                    }
                                }
                            }

                            $set('blueprint_expansion_id', $expansions[$responseData[$data['sku']][0]['expansion']['id']]);
                            $set('blueprint_collector_number', $collectorNumber);
                            $set('blueprint_image_url', $tcgdexProduct?->image);
                            $set('cardmarket_product_id', $tcgdexProduct?->pricing?->cardmarket?->idProduct);
                            $set('cardmarket_price_avg', $responseData[$data['sku']][0]['properties_hash']['pokemon_reverse'] ? $tcgdexProduct?->pricing?->cardmarket?->{'avg-holo'} : $tcgdexProduct?->pricing?->cardmarket?->avg);
                            $set('cardmarket_price_low', $responseData[$data['sku']][0]['properties_hash']['pokemon_reverse'] ? $tcgdexProduct?->pricing?->cardmarket?->{'low-holo'} : $tcgdexProduct?->pricing?->cardmarket?->low);
                            $set('cardmarket_price_trend', $responseData[$data['sku']][0]['properties_hash']['pokemon_reverse'] ? $tcgdexProduct?->pricing?->cardmarket?->{'trend-holo'} : $tcgdexProduct?->pricing?->cardmarket?->trend);
                            $set('cardmarket_price_avg1', $responseData[$data['sku']][0]['properties_hash']['pokemon_reverse'] ? $tcgdexProduct?->pricing?->cardmarket?->{'avg1-holo'} : $tcgdexProduct?->pricing?->cardmarket?->avg1);
                            $set('cardmarket_price_avg7', $responseData[$data['sku']][0]['properties_hash']['pokemon_reverse'] ? $tcgdexProduct?->pricing?->cardmarket?->{'avg7-holo'} : $tcgdexProduct?->pricing?->cardmarket?->avg7);
                            $set('cardmarket_price_avg30', $responseData[$data['sku']][0]['properties_hash']['pokemon_reverse'] ? $tcgdexProduct?->pricing?->cardmarket?->{'avg30-holo'} : $tcgdexProduct?->pricing?->cardmarket?->avg30);
                        }
                    }
                    catch(Exception $e) {
                        $set('blueprint_url', null);
                    }
                })
                ->disabledOn('edit')
                ->columnSpanFull(),
        ];
    }

    public static function getStepTwoSchema(): array
    {
        return [
            TextInput::make('blueprint_name')
                ->label('Nome')
                ->columnSpanFull(),
            TextInput::make('blueprint_id')
                ->label('CT ID')
                ->required()
                ->disabled()
                ->dehydrated(),
            Select::make('blueprint_expansion_id')
                ->relationship('blueprintExpansion', 'name')
                ->label('Espansione'),
            TextInput::make('blueprint_collector_number')
                ->label('Numero'),
            Hidden::make('blueprint_image_url'),
        ];
    }

    public static function getStepThreeSchema(): array
    {
        return [
            Hidden::make('cardmarket_product_id'),
            TextInput::make('cardmarket_price_avg')
                ->label('CM Prezzo medio')
                ->mask(RawJs::make('$money($input)'))
                ->stripCharacters(',')
                ->disabled()
                ->suffix('€')
                ->numeric()
                ->dehydrated(),
            TextInput::make('cardmarket_price_low')
                ->label('CM Prezzo basso')
                ->mask(RawJs::make('$money($input)'))
                ->stripCharacters(',')
                ->disabled()
                ->suffix('€')
                ->numeric()
                ->dehydrated(),
            TextInput::make('cardmarket_price_trend')
                ->label('CM Prezzo trend')
                ->mask(RawJs::make('$money($input)'))
                ->stripCharacters(',')
                ->disabled()
                ->suffix('€')
                ->numeric()
                ->dehydrated(),
            TextInput::make('cardmarket_price_avg1')
                ->label('CM Prezzo medio 1')
                ->mask(RawJs::make('$money($input)'))
                ->stripCharacters(',')
                ->disabled()
                ->suffix('€')
                ->numeric()
                ->dehydrated(),
            TextInput::make('cardmarket_price_avg7')
                ->label('CM Prezzo medio 7')
                ->mask(RawJs::make('$money($input)'))
                ->stripCharacters(',')
                ->disabled()
                ->suffix('€')
                ->numeric()
                ->dehydrated(),
            TextInput::make('cardmarket_price_avg30')
                ->label('CM Prezzo medio 30')
                ->mask(RawJs::make('$money($input)'))
                ->stripCharacters(',')
                ->disabled()
                ->suffix('€')
                ->numeric()
                ->dehydrated(),
            TextInput::make('price')
                ->label('Prezzo desiderato')
                ->mask(RawJs::make('$money($input)'))
                ->stripCharacters(',')
                ->required()
                ->suffix('€')
                ->numeric(),
            TextInput::make('minimum_quantity')
                ->label('Quantità minima')
                ->integer()
                ->default(1)
                ->minValue(1),
            CheckboxList::make('conditions')
                ->label('Condizioni')
                ->options(ConditionType::class)
                ->default(['nm', 'sp', 'mp', 'pl'])
                ->bulkToggleable()
                ->columns(2),
            CheckboxList::make('languages')
                ->label('Lingue')
                ->options(Language::class)
                ->default(['it', 'en'])
                ->bulkToggleable()
                ->columns(3),
            Toggle::make('ct_zero_only')
                ->label('Solo CT Zero'),
            Toggle::make('active')
                ->label('Attivo')
                ->default(true),
        ];
    }
}
