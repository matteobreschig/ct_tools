<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum Language: string implements HasLabel
{
    case IT = 'it';
    case EN = 'en';
    case FR = 'fr';
    case DE = 'de';
    case ES = 'es';
    case PT = 'pt';
    case NL = 'nl';
    case RU = 'ru';
    case PL = 'pl';
    case SV = 'sv';
    case JP = 'jp';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::IT => 'IT',
            self::EN => 'EN',
            self::FR => 'FR',
            self::DE => 'DE',
            self::ES => 'ES',
            self::PT => 'PT',
            self::NL => 'NL',
            self::RU => 'RU',
            self::PL => 'PL',
            self::SV => 'SV',
            self::JP => 'JP',
        };
    }
}
