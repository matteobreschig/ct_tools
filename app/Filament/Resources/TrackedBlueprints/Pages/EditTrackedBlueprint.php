<?php

namespace App\Filament\Resources\TrackedBlueprints\Pages;

use App\Filament\Resources\TrackedBlueprints\TrackedBlueprintResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTrackedBlueprint extends EditRecord
{
    protected static string $resource = TrackedBlueprintResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
