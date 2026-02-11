<?php

namespace App\Filament\Resources\Products\Pages;

use App\Enums\ConditionType;
use App\Filament\Resources\Products\ProductResource;
use App\Jobs\SyncProducts;
use App\Models\Category;
use App\Models\Expansion;
use App\Models\Product;
use App\Services\CTApiClient;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;
use TCGdex\TCGdex;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncronize_products')
                ->label('Sincronizza prodotti')
                ->requiresConfirmation()
                ->action(function () {
                    SyncProducts::dispatch(Auth::user());
                })
        ];
    }
}
