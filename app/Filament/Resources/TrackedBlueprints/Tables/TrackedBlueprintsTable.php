<?php

namespace App\Filament\Resources\TrackedBlueprints\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class TrackedBlueprintsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('blueprint_image_url')
                    ->label('')
                    ->tooltip(function($state) {
                        return new HtmlString("
                            <div style='padding:8px'>
                                <img
                                    src='{$state}/high.webp'
                                    style='max-width:300px; height:auto; border-radius:10px; box-shadow:0 4px 12px rgba(0,0,0,0.2);'
                                    loading='lazy'
                                >
                            </div>
                        ");
                    })
                    ->extraImgAttributes(function($state) {
                        return [
                            'src' => $state . '/low.webp',
                            'loading' => 'lazy',
                        ];
                    }),
                TextColumn::make('blueprint_name')
                    ->label('Nome')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('blueprintExpansion.name')
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
