<?php

namespace App\Livewire;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Jeffgreco13\FilamentBreezy\Livewire\MyProfileComponent;

/**
 * Credenziali Pokecardex dell'utente collegato. La password salvata non torna
 * mai nel modulo: un campo vuoto lascia quella già salvata.
 */
class MyProfilePokecardex extends MyProfileComponent
{
    protected string $view = 'livewire.my-profile-pokecardex';

    public array $data;

    public User $user;

    public static $sort = 60;

    public function mount(): void
    {
        $this->user = Filament::getCurrentPanel()->auth()->user();

        $this->form->fill([
            'username' => $this->user->pokecardexAccount?->username,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('username')
                    ->label('Username Pokecardex')
                    ->required()
                    ->maxLength(255),
                TextInput::make('password')
                    ->label('Password Pokecardex')
                    ->password()
                    ->revealable()
                    ->required(fn (): bool => $this->user->pokecardexAccount === null)
                    ->helperText(fn (): ?string => $this->user->pokecardexAccount
                        ? 'Password già salvata: lascia vuoto per non cambiarla.'
                        : null),
            ])
            ->statePath('data');
    }

    public function submit(): void
    {
        $state = $this->form->getState();

        $attributes = ['username' => $state['username']];

        if (filled($state['password'] ?? null)) {
            $attributes['password'] = $state['password'];
        }

        $this->user->pokecardexAccount()->updateOrCreate([], $attributes);
        $this->user->unsetRelation('pokecardexAccount');

        $this->form->fill(['username' => $state['username']]);

        Notification::make()
            ->success()
            ->title('Credenziali Pokecardex salvate!')
            ->send();
    }

    public function removeCredentials(): void
    {
        $this->user->pokecardexAccount()->delete();
        $this->user->unsetRelation('pokecardexAccount');

        $this->form->fill(['username' => null]);

        Notification::make()
            ->success()
            ->title('Credenziali Pokecardex rimosse.')
            ->send();
    }
}
