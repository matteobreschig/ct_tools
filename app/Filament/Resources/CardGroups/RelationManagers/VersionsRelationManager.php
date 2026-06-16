<?php

namespace App\Filament\Resources\CardGroups\RelationManagers;

use App\Services\CTApiClient;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'products';

    protected static ?string $title = 'Versioni';

    public function table(Table $table): Table
    {
        return $table
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
                    ->boolean()
                    ->sortable(),
                IconColumn::make('sale_locked')
                    ->label('Bloccato')
                    ->boolean()
                    ->trueIcon(Heroicon::LockClosed)
                    ->falseIcon(Heroicon::LockOpen)
                    ->color(fn (bool $state): string => $state ? 'warning' : 'gray')
                    ->tooltip(fn (bool $state): string => $state
                        ? 'Stato vendita bloccato: la vendita dinamica non lo tocca'
                        : 'Gestito dalla vendita dinamica')
                    ->sortable(),
                TextColumn::make('expansion.name')
                    ->label('Espansione')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('collector_number')
                    ->label('Numero')
                    ->sortable(),
                TextColumn::make('language')
                    ->label('Lingua')
                    ->badge()
                    ->sortable(),
                TextColumn::make('condition')
                    ->label('Condizione')
                    ->badge()
                    ->sortable(),
                IconColumn::make('reverse')
                    ->label('Reverse')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('quantity')
                    ->label('Quantità')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('price')
                    ->label('Prezzo')
                    ->numeric(
                        decimalPlaces: 2,
                        decimalSeparator: ',',
                        thousandsSeparator: '.'
                    )
                    ->suffix(' €')
                    ->sortable(),
                TextColumn::make('cardmarket_price_low')
                    ->label('CM prezzo basso')
                    ->numeric(
                        decimalPlaces: 2,
                        decimalSeparator: ',',
                        thousandsSeparator: '.'
                    )
                    ->suffix(' €')
                    ->sortable(),
            ])
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
                                ];
                            });

                            $client = new CTApiClient(Auth::user());
                            $client->post('/products/bulk_update', $ctData)->json();
                        }),
                ]),
            ]);
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}
