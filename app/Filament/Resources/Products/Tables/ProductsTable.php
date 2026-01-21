<?php

namespace App\Filament\Resources\Products\Tables;

use App\Enums\ConditionType;
use App\Enums\Language;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                IconColumn::make('sale')
                    ->label('In vendita')
                    ->sortable()
                    ->boolean()
                    ->toggleable(),
                TextColumn::make('name')
                    ->label('Nome')
                    ->sortable()
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('description')
                    ->label('Descrizione')
                    ->sortable()
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('quantity')
                    ->label('Quantità')
                    ->numeric()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('price')
                    ->label('Prezzo')
                    ->numeric()
                    ->suffix('€')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('category.name')
                    ->label('Categoria')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('expansion.name')
                    ->label('Espansione')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('collector_number')
                    ->label('Numero')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('condition')
                    ->label('Condizione')
                    ->badge()
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                IconColumn::make('tournament_legal')
                    ->label('Legale per i tornei')
                    ->boolean()
                    ->sortable()
                    ->toggleable(),
                IconColumn::make('reverse')
                    ->label('Reverse')
                    ->boolean()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('language')
                    ->label('Lingua')
                    ->badge()
                    ->sortable()
                    ->searchable()
                    ->toggleable(),
                IconColumn::make('signed')
                    ->label('Segnato')
                    ->boolean()
                    ->sortable()
                    ->toggleable(),
                IconColumn::make('altered')
                    ->label('Alterato')
                    ->boolean()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('rarity')
                    ->label('Rarità')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                IconColumn::make('first_edition')
                    ->label('Prima edizione')
                    ->boolean()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('last_update')
                    ->label('Ultimo aggiornamento')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->label('Categoria')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->multiple()
                    ->preload(),
                SelectFilter::make('expansion')
                    ->label('Espansione')
                    ->relationship('expansion', 'name')
                    ->searchable()
                    ->multiple()
                    ->preload(),
                SelectFilter::make('condition')
                    ->label('Condizione')
                    ->options(ConditionType::class)
                    ->searchable()
                    ->multiple()
                    ->preload(),
                SelectFilter::make('language')
                    ->label('Lingua')
                    ->options(Language::class)
                    ->searchable()
                    ->multiple()
                    ->preload(),
                Filter::make('tournament_legal')
                    ->label('Legale per i tornei')
                    ->toggle(),
                Filter::make('reverse')
                    ->label('Reverse')
                    ->toggle(),
                Filter::make('signed')
                    ->label('Segnato')
                    ->toggle(),
                Filter::make('altered')
                    ->label('Alterato')
                    ->toggle(),
                Filter::make('first_edition')
                    ->label('Prima edizione')
                    ->toggle(),
            ])
            ->recordActions([])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
