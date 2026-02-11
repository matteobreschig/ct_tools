<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum Roles: string implements HasLabel
{
    case ADMIN = 'admin';
    case USER = 'user';

    public function getLabel(): ?string
    {
        return match($this) {
            self::ADMIN => 'Amministratore',
            self::USER => 'Utente',
        };
    }
}
