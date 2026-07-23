<?php

namespace App\Filament\Resources\Products\Tables;

use App\Enums\ConditionType;
use App\Enums\Language;
use App\Enums\RegulationMark;
use App\Services\CTApiClient;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordUrl(null)
            ->columns([
                ImageColumn::make('image_url')
                    ->label('')
                    ->tooltip(function ($record) {
                        if (! $record->image_url_high) {
                            return null;
                        }

                        return new HtmlString("
                            <div style='padding:8px'>
                                <img
                                    src='{$record->image_url_high}'
                                    style='max-width:300px; height:auto; border-radius:10px; box-shadow:0 4px 12px rgba(0,0,0,0.2);'
                                    loading='lazy'
                                >
                            </div>
                        ");
                    })
                    ->extraImgAttributes(function ($record) {
                        return [
                            'src' => $record->image_url_low,
                            'loading' => 'lazy',
                        ];
                    }),
                IconColumn::make('sale')
                    ->label('In vendita')
                    ->sortable()
                    ->boolean()
                    ->toggleable(),
                IconColumn::make('sale_locked')
                    ->label('Bloccato')
                    ->sortable()
                    ->boolean()
                    ->trueIcon(Heroicon::LockClosed)
                    ->falseIcon(Heroicon::LockOpen)
                    ->color(fn (bool $state): string => $state ? 'warning' : 'gray')
                    ->tooltip(fn (bool $state): string => $state
                        ? 'Stato vendita bloccato: la vendita dinamica non lo tocca'
                        : 'Gestito dalla vendita dinamica')
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
                    ->numeric(
                        decimalPlaces: 2,
                        decimalSeparator: ',',
                        thousandsSeparator: '.'
                    )
                    ->suffix('€')
                    ->sortable()
                    ->toggleable(),
                /*                TextColumn::make('category.name')
                    ->label('Categoria')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),*/
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
                TextColumn::make('regulation_mark')
                    ->label('Lettera')
                    ->badge()
                    ->sortable()
                    ->toggleable(),
                IconColumn::make('tournament_legal')
                    ->label('Legale Standard')
                    ->boolean()
                    ->sortable()
                    ->toggleable(),
                IconColumn::make('tournament_extended_legal')
                    ->label('Legale Esteso')
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
                TextColumn::make('cardmarket_price_avg')
                    ->label('CM Prezzo medio')
                    ->numeric(
                        decimalPlaces: 2,
                        decimalSeparator: ',',
                        thousandsSeparator: '.'
                    )
                    ->suffix('€')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('cardmarket_price_low')
                    ->label('CM Prezzo basso')
                    ->numeric(
                        decimalPlaces: 2,
                        decimalSeparator: ',',
                        thousandsSeparator: '.'
                    )
                    ->suffix('€')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('cardmarket_price_trend')
                    ->label('CM Prezzo trend')
                    ->numeric(
                        decimalPlaces: 2,
                        decimalSeparator: ',',
                        thousandsSeparator: '.'
                    )
                    ->suffix('€')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('cardmarket_price_avg1')
                    ->label('CM Prezzo medio 1')
                    ->numeric(
                        decimalPlaces: 2,
                        decimalSeparator: ',',
                        thousandsSeparator: '.'
                    )
                    ->suffix('€')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('cardmarket_price_avg7')
                    ->label('CM Prezzo medio 7')
                    ->numeric(
                        decimalPlaces: 2,
                        decimalSeparator: ',',
                        thousandsSeparator: '.'
                    )
                    ->suffix('€')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('cardmarket_price_avg30')
                    ->label('CM Prezzo medio 30')
                    ->numeric(
                        decimalPlaces: 2,
                        decimalSeparator: ',',
                        thousandsSeparator: '.'
                    )
                    ->suffix('€')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Data di creazione')
                    ->dateTime()
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
                SelectFilter::make('regulation_mark')
                    ->label('Lettera')
                    ->options(RegulationMark::class)
                    ->searchable()
                    ->multiple()
                    ->preload(),
                TernaryFilter::make('tournament_legal')
                    ->label('Legale standard'),
                TernaryFilter::make('tournament_extended_legal')
                    ->label('Legale esteso'),
                TernaryFilter::make('reverse')
                    ->label('Reverse'),
                TernaryFilter::make('signed')
                    ->label('Segnato'),
                TernaryFilter::make('altered')
                    ->label('Alterato'),
                TernaryFilter::make('first_edition')
                    ->label('Prima edizione'),
                TernaryFilter::make('sale')
                    ->label('In vendita'),
                TernaryFilter::make('sale_locked')
                    ->label('Bloccato'),
            ])
            ->recordActions([])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('set_sale_status')
                        ->label('Imposta stato vendita')
                        ->icon(Heroicon::Banknotes)
                        ->requiresConfirmation()
                        ->schema([
                            ToggleButtons::make('sale_status')
                                ->label('Stato vendita')
                                ->inline()
                                ->required()
                                ->options([
                                    'set_on_sale' => 'In vendita',
                                    'set_off_sale' => 'Non in vendita',
                                ])
                                ->icons([
                                    'set_on_sale' => Heroicon::HandThumbUp,
                                    'set_off_sale' => Heroicon::HandThumbDown,
                                ]),
                            Toggle::make('lock')
                                ->label('Blocca dalla vendita dinamica')
                                ->helperText('Lo stato impostato non verrà più modificato dal job automatico. Disattiva per restituire le carte alla gestione automatica.')
                                ->default(true),
                        ])
                        ->action(function (array $data, Collection $records) {
                            $ctData = ['products' => []];

                            $records->each(function ($model) use ($data, &$ctData) {
                                $model->sale = $data['sale_status'] == 'set_on_sale';
                                $model->sale_locked = $data['lock'];
                                $model->save();

                                $ctData['products'][] = [
                                    'id' => $model->product_id,
                                    'price' => $data['sale_status'] == 'set_on_sale' ? $model->price : config('app.ct_disable_price'),
                                    'tag' => $data['sale_status'] == 'set_on_sale' ? null : config('app.ct_vacation_tag'),
                                ];
                            });

                            $client = new CTApiClient(Auth::user());
                            $ctBulkUpdateResponse = $client->post('/products/bulk_update', $ctData)->json();
                        }),
                ]),
            ]);
    }
}
