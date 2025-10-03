<?php

namespace App\Filament\Resources\TrackedBlueprints\Pages;

use App\Filament\Resources\TrackedBlueprints\Schemas\TrackedBlueprintForm;
use App\Filament\Resources\TrackedBlueprints\TrackedBlueprintResource;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Wizard\Step;

class CreateTrackedBlueprint extends CreateRecord
{
    use CreateRecord\Concerns\HasWizard;
    protected static string $resource = TrackedBlueprintResource::class;

    protected function getSteps(): array
    {
        return [
            Step::make('URL')
                ->schema(TrackedBlueprintForm::getStepOneSchema()),
            Step::make('Nome')
                ->schema(TrackedBlueprintForm::getStepTwoSchema())
                ->columns(3),
            Step::make('Configurazione')
                ->schema(TrackedBlueprintForm::getStepThreeSchema())
                ->columns(3),
        ];
    }
}
