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
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('blueprint_expansion_code')
                    ->label('Espansione')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('blueprint_collector_number')
                    ->label('Numero')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('price')
                    ->label('Prezzo desiderato')
                    ->suffix('€')
                    ->numeric(
                        decimalPlaces: 2,
                        decimalSeparator: ',',
                        thousandsSeparator: '.'
                    )
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('minimum_quantity')
                    ->label('Quantità minima')
                    ->numeric()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('conditions')
                    ->label('Condizioni')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('languages')
                    ->label('Lingue')
                    ->sortable()
                    ->toggleable(),
                IconColumn::make('ct_zero_only')
                    ->label('Solo CT Zero')
                    ->boolean()
                    ->sortable()
                    ->toggleable(),
                IconColumn::make('active')
                    ->label('Attivo')
                    ->boolean()
                    ->sortable()
                    ->toggleable()
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
