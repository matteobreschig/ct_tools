<?php

namespace App\Filament\Resources\CardGroups;

use App\Filament\Resources\CardGroups\Pages\ListCardGroups;
use App\Filament\Resources\CardGroups\Pages\ViewCardGroup;
use App\Filament\Resources\CardGroups\RelationManagers\VersionsRelationManager;
use App\Filament\Resources\CardGroups\Tables\CardGroupsTable;
use App\Models\CardGroup;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CardGroupResource extends Resource
{
    protected static ?string $model = CardGroup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'Carta';
    protected static ?string $pluralModelLabel = 'Carte';

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('name')
                ->label('Carta'),
            TextEntry::make('versions_count')
                ->label('Versioni possedute'),
            TextEntry::make('total_quantity')
                ->label('Copie totali'),
            TextEntry::make('on_sale_quantity')
                ->label('Copie in vendita'),
            TextEntry::make('price_low')
                ->label('CM prezzo più basso')
                ->numeric(
                    decimalPlaces: 2,
                    decimalSeparator: ',',
                    thousandsSeparator: '.'
                )
                ->suffix(' €'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return CardGroupsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            VersionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCardGroups::route('/'),
            'view' => ViewCardGroup::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('user_id', auth()->id());
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
