<?php

namespace App\Filament\Resources\TrackedBlueprints\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TrackedBlueprintsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('blueprint_name')
                    ->label('Nome')
                    ->searchable(),
                TextColumn::make('blueprint_expansion_code')
                    ->label('Espansione')
                    ->searchable(),
                TextColumn::make('blueprint_collector_number')
                    ->label('Numero')
                    ->searchable(),
                TextColumn::make('price')
                    ->label('Prezzo desiderato')
                    ->suffix('€')
                    ->numeric(),
                TextColumn::make('minimum_quantity')
                    ->label('Quantità minima')
                    ->numeric(),
                TextColumn::make('conditions')
                    ->label('Condizioni'),
                TextColumn::make('languages')
                    ->label('Lingue'),
                IconColumn::make('ct_zero_only')
                    ->label('Solo CT Zero')
                    ->boolean(),
                IconColumn::make('active')
                    ->label('Attivo')
                    ->boolean()
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
