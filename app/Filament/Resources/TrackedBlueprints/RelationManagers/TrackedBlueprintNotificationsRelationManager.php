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
                    ->label('Data e ora'),
                TextColumn::make('price')
                    ->label('Prezzo'),
                TextColumn::make('quantity')
                    ->label('Quantità'),
                TextColumn::make('condition')
                    ->label('Condizione'),
                TextColumn::make('language')
                    ->label('Lingua'),
                IconColumn::make('ct_zero')
                    ->label('CT Zero')
                    ->boolean(),
            ]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
