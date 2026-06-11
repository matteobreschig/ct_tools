<?php

namespace App\Filament\Resources\CardGroups\Tables;

use App\Models\CardGroup;
use App\Models\CardGroupSetting;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
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
                TextColumn::make('keep_quantity')
                    ->label('Da tenere')
                    ->badge()
                    // Deroga della carta se presente, poi minimo del tipo, poi globale.
                    // Deroga in evidenza (info), minimi in grigio, '—' = motore spento.
                    ->state(fn (CardGroup $record) => CardGroupSetting::where('user_id', $record->user_id)
                        ->where('group_key', $record->group_key)
                        ->value('keep_quantity')
                        ?? auth()->user()->keepQuantityForType($record->products()->value('card_type')))
                    ->placeholder('—')
                    ->color(fn (CardGroup $record) => CardGroupSetting::where('user_id', $record->user_id)
                        ->where('group_key', $record->group_key)
                        ->exists() ? 'info' : 'gray'),
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
                Action::make('keep_quantity')
                    ->label('Minimo')
                    ->icon(Heroicon::LockClosed)
                    ->fillForm(fn (CardGroup $record) => [
                        'keep_quantity' => CardGroupSetting::where('user_id', $record->user_id)
                            ->where('group_key', $record->group_key)
                            ->value('keep_quantity'),
                    ])
                    ->schema([
                        TextInput::make('keep_quantity')
                            ->label('Copie minime da tenere per questa carta')
                            ->numeric()
                            ->minValue(0)
                            ->helperText('Lascia vuoto per usare il minimo globale del profilo.'),
                    ])
                    ->action(function (array $data, CardGroup $record) {
                        if ($data['keep_quantity'] === null || $data['keep_quantity'] === '') {
                            CardGroupSetting::where('user_id', $record->user_id)
                                ->where('group_key', $record->group_key)
                                ->delete();
                        } else {
                            CardGroupSetting::updateOrCreate(
                                ['user_id' => $record->user_id, 'group_key' => $record->group_key],
                                ['keep_quantity' => (int) $data['keep_quantity']],
                            );
                        }

                        Notification::make()
                            ->title('Minimo aggiornato')
                            ->success()
                            ->send();
                    }),
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
