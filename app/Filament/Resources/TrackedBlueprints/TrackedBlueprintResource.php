<?php

namespace App\Filament\Resources\TrackedBlueprints;

use App\Filament\Resources\TrackedBlueprints\Pages\CreateTrackedBlueprint;
use App\Filament\Resources\TrackedBlueprints\Pages\EditTrackedBlueprint;
use App\Filament\Resources\TrackedBlueprints\Pages\ListTrackedBlueprints;
use App\Filament\Resources\TrackedBlueprints\Schemas\TrackedBlueprintForm;
use App\Filament\Resources\TrackedBlueprints\Tables\TrackedBlueprintsTable;
use App\Models\TrackedBlueprint;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class TrackedBlueprintResource extends Resource
{
    protected static ?string $model = TrackedBlueprint::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'TrackedBlueprint';

    protected static ?string $modelLabel = 'Prodotto tracciato';
    protected static ?string $pluralModelLabel = 'Prodotti tracciati';

    public static function form(Schema $schema): Schema
    {
        return TrackedBlueprintForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TrackedBlueprintsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\TrackedBlueprintNotificationsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTrackedBlueprints::route('/'),
            'create' => CreateTrackedBlueprint::route('/create'),
            'edit' => EditTrackedBlueprint::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return static::getModel()::query()->where('user_id' , auth()->id());
    }
}
