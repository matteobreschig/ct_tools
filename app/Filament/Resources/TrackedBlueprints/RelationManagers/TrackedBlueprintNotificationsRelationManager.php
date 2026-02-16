<?php

namespace App\Filament\Resources\TrackedBlueprints\RelationManagers;

use App\Filament\Resources\TrackedBlueprints\TrackedBlueprintResource;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TrackedBlueprintNotificationsRelationManager extends RelationManager
{
    protected static string $relationship = 'trackedBlueprintNotifications';

    protected static ?string $title = 'Prezzi rilevati';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('datetime')
                    ->label('Data e ora')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('price')
                    ->label('Prezzo')
                    ->suffix('€')
                    ->numeric(
                        decimalPlaces: 2,
                        decimalSeparator: ',',
                        thousandsSeparator: '.'
                    )
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('quantity')
                    ->label('Quantità')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('condition')
                    ->label('Condizione')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('language')
                    ->label('Lingua')
                    ->sortable()
                    ->toggleable(),
                IconColumn::make('ct_zero')
                    ->label('CT Zero')
                    ->boolean()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('ct_user_name')
                    ->label('Condizione')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('ct_user_country_code')
                    ->label('Condizione')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Creato il')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->label('Aggiornato il')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
