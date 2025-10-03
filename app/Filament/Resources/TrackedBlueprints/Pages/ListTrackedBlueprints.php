<?php

namespace App\Filament\Resources\TrackedBlueprints\Pages;

use App\Filament\Resources\TrackedBlueprints\TrackedBlueprintResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTrackedBlueprints extends ListRecords
{
    protected static string $resource = TrackedBlueprintResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
