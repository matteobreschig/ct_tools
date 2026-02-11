<?php

namespace App\Livewire;

use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;
use Jeffgreco13\FilamentBreezy\Livewire\MyProfileComponent;
use Livewire\Component;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Facades\Filament;
use App\Helpers\GCalendarHelper;
use Filament\Notifications\Notification;

class MyProfileCtApiKey extends MyProfileComponent
{
    protected string $view = "livewire.my-profile-ct-api-key";
    public array $only = ['ct_api_key'];
    public array $data;
    public $user;
    public $userClass;
    public static $sort = 30;

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
                Textarea::make('ct_api_key')
                    ->label('CT API Key')
            ])
            ->statePath('data');
    }

    // only capture the custome component field
    public function submit(): void
    {
        $data = collect($this->form->getState())->only($this->only)->all();
        $this->user->update($data);
        Notification::make()
            ->success()
            ->title(__('Chiave API salvata con successo!'))
            ->send();
    }
}
