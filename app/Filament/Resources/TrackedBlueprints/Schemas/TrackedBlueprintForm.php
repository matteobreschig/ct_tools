<?php

namespace App\Filament\Resources\TrackedBlueprints\Schemas;

use App\Enums\ConditionType;
use App\Enums\Language;
use DOMDocument;
use DOMXPath;
use Exception;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;
use Illuminate\Support\Facades\Http;

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

                        $response = Http::withToken(config('app.ct_api_token'))->get('https://api.cardtrader.com/api/v2/marketplace/products?blueprint_id=' . $data['sku'] . '&language=' . config('app.locale'));
                        if($response->successful()) {
                            $responseData = $response->json();
                            $set('blueprint_expansion_code', $responseData[$data['sku']][0]['expansion']['code']);
                            $set('blueprint_collector_number', $responseData[$data['sku']][0]['properties_hash']['collector_number']);
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
            TextInput::make('blueprint_expansion_code')
                ->label('Espansione'),
            TextInput::make('blueprint_collector_number')
                ->label('Numero'),
        ];
    }

    public static function getStepThreeSchema(): array
    {
        return [
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
