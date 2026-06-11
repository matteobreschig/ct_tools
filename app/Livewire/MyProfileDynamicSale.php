<?php

namespace App\Livewire;

use App\Jobs\ApplyDynamicSale;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Jeffgreco13\FilamentBreezy\Livewire\MyProfileComponent;

class MyProfileDynamicSale extends MyProfileComponent
{
    protected string $view = 'livewire.my-profile-dynamic-sale';
    public array $only = ['keep_quantity', 'keep_quantity_pokemon', 'keep_quantity_trainer', 'keep_quantity_energy'];
    public array $data;
    public $user;
    public $userClass;
    public static $sort = 50;

    public function mount()
    {
        $this->user = Filament::getCurrentPanel()->auth()->user();
        $this->userClass = get_class($this->user);

        $this->form->fill($this->user->only($this->only));
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('keep_quantity')
                    ->label('Copie minime da tenere per carta')
                    ->placeholder('es. 1')
                    ->numeric()
                    ->minValue(0)
                    ->helperText('Le copie oltre questa soglia vengono messe in vendita automaticamente a ogni sincronizzazione, indipendentemente dalla versione. Lascia vuoto per disattivare la vendita dinamica. Sulla singola carta puoi impostare una soglia diversa dalla pagina Carte.'),
                TextInput::make('keep_quantity_pokemon')
                    ->label('Minimo per carte Pokémon')
                    ->placeholder('vuoto = usa il minimo globale')
                    ->numeric()
                    ->minValue(0),
                TextInput::make('keep_quantity_trainer')
                    ->label('Minimo per carte Allenatore')
                    ->placeholder('vuoto = usa il minimo globale')
                    ->numeric()
                    ->minValue(0),
                TextInput::make('keep_quantity_energy')
                    ->label('Minimo per carte Energia')
                    ->placeholder('vuoto = usa il minimo globale')
                    ->numeric()
                    ->minValue(0),
            ])
            ->statePath('data');
    }

    public function submit(): void
    {
        $data = collect($this->form->getState())
            ->only($this->only)
            ->map(fn ($value) => $value === '' ? null : $value)
            ->all();
        $this->user->update($data);

        Notification::make()
            ->success()
            ->title('Impostazioni di vendita dinamica salvate!')
            ->send();
    }

    public function applyNow(): void
    {
        ApplyDynamicSale::dispatch($this->user);

        Notification::make()
            ->success()
            ->title('Vendita dinamica avviata, le inserzioni si aggiornano tra poco.')
            ->send();
    }
}
