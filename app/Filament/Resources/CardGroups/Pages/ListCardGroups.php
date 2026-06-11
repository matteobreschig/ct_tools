<?php

namespace App\Filament\Resources\CardGroups\Pages;

use App\Filament\Resources\CardGroups\CardGroupResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListCardGroups extends ListRecords
{
    protected static string $resource = CardGroupResource::class;

    public function getTabs(): array
    {
        return [
            'tutte' => Tab::make('Tutte'),
            'raggruppate' => Tab::make('Raggruppate')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('group_type', 'meta')),
            'non_raggruppate' => Tab::make('Non raggruppate')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('group_type', 'blueprint')),
        ];
    }
}
