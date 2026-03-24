<?php

namespace App\Livewire;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Jeffgreco13\FilamentBreezy\Livewire\MyProfileComponent;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;

class MyProfileTelegram extends MyProfileComponent
{
    protected string $view = 'livewire.my-profile-telegram';
    public array $only = ['telegram_chat_id'];
    public array $data;
    public $user;
    public $userClass;
    public static $sort = 40;

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
                TextInput::make('telegram_chat_id')
                    ->label('Telegram Chat ID')
                    ->placeholder('es. 123456789')
                    ->numeric(),
            ])
            ->statePath('data');
    }

    public function submit(): void
    {
        $data = collect($this->form->getState())->only($this->only)->all();
        $this->user->update($data);
        Notification::make()
            ->success()
            ->title('Telegram Chat ID salvato con successo!')
            ->send();
    }

    public function sendTest(): void
    {
        if (!$this->user->telegram_chat_id) {
            Notification::make()
                ->warning()
                ->title('Inserisci e salva prima il tuo Telegram Chat ID!')
                ->send();
            return;
        }

        $this->user->notify(new \App\Notifications\BlueprintPriceAlert(
            'Test notifica CTAlert!',
            'Le notifiche Telegram sono configurate correttamente.'
        ));

        Notification::make()
            ->success()
            ->title('Notifica di test inviata su Telegram!')
            ->send();
    }
}