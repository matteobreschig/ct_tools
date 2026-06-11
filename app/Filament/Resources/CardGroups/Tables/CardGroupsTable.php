<?php

namespace App\Filament\Resources\CardGroups\Tables;

use App\Models\CardGroup;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class CardGroupsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image_url')
                    ->label('')
                    ->tooltip(function ($state) {
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
                    ->extraImgAttributes(function ($state) {
                        return [
                            'src' => $state . '/low.webp',
                            'loading' => 'lazy',
                        ];
                    }),
                TextColumn::make('name')
                    ->label('Carta')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('versions_count')
                    ->label('Versioni')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('total_quantity')
                    ->label('Copie totali')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('on_sale_quantity')
                    ->label('In vendita')
                    ->numeric()
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'success' : 'gray')
                    ->sortable(),
                TextColumn::make('price_low')
                    ->label('CM prezzo più basso')
                    ->numeric(
                        decimalPlaces: 2,
                        decimalSeparator: ',',
                        thousandsSeparator: '.'
                    )
                    ->suffix(' €')
                    ->sortable(),
                TextColumn::make('group_type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state === 'meta' ? 'Raggruppata' : 'Non raggruppata')
                    ->color(fn ($state) => $state === 'meta' ? 'success' : 'warning')
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->recordActions([
                ViewAction::make()
                    ->label('Versioni'),
                Action::make('merge')
                    ->label('Unisci')
                    ->icon(Heroicon::ArrowsPointingIn)
                    ->visible(fn (CardGroup $record) => $record->group_type === 'blueprint')
                    ->schema([
                        Select::make('meta_name')
                            ->label('Unisci alla carta')
                            ->options(fn () => CardGroup::query()
                                ->where('user_id', auth()->id())
                                ->where('group_type', 'meta')
                                ->orderBy('name')
                                ->pluck('name', 'meta_name'))
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (array $data, CardGroup $record) {
                        Product::query()
                            ->where('user_id', $record->user_id)
                            ->where('blueprint_id', $record->blueprint_id)
                            ->update(['meta_name' => $data['meta_name']]);

                        Notification::make()
                            ->title('Carta unita')
                            ->success()
                            ->send();
                    }),
            ]);
    }
}
