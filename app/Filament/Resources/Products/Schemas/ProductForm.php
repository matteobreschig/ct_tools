<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Enums\ConditionType;
use App\Enums\Language;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nome')
                    ->disabled(),
                TextInput::make('description')
                    ->label('Descrizione')
                    ->disabled(),
                TextInput::make('quantity')
                    ->label('Quantità')
                    ->numeric()
                    ->disabled(),
                TextInput::make('price')
                    ->label('Prezzo')
                    ->numeric()
                    ->default(0)
                    ->mask(RawJs::make('$money($input)'))
                    ->stripCharacters(',')
                    ->suffix('€')
                    ->disabled(),
                Toggle::make('sale')
                    ->label('In vendita')
                    ->required(),
                TextInput::make('collector_number')
                    ->label('Numero')
                    ->disabled(),
                Select::make('condition')
                    ->label('Condizione')
                    ->options(ConditionType::class)
                    ->disabled(),
                Toggle::make('tournament_legal')
                    ->label('Legale per i tornei')
                    ->disabled(),
                Toggle::make('reverse')
                    ->label('Reverse')
                    ->disabled(),
                Select::make('language')
                    ->options(Language::class)
                    ->label('Lingua')
                    ->disabled(),
                Toggle::make('signed')
                    ->label('Segnato')
                    ->disabled(),
                Toggle::make('altered')
                    ->label('Alterato')
                    ->disabled(),
                TextInput::make('rarity')
                    ->label('Rarità')
                    ->disabled(),
                Toggle::make('first_edition')
                    ->label('Prima edizione')
                    ->disabled(),
            ]);
    }
}
