<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ConditionType: string implements HasLabel
{
    case NM = 'nm';
    case SP = 'sp';
    case MP = 'mp';
    case PL = 'pl';
    case PO = 'po';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::NM => 'NM',
            self::SP => 'SP',
            self::MP => 'MP',
            self::PL => 'PL',
            self::PO => 'PO',
        };
    }

    public function getApiLabel(): ?string
    {
        return match ($this) {
            self::NM => 'Near Mint',
            self::SP => 'Slightly Played',
            self::MP => 'Moderately Played',
            self::PL => 'Played',
            self::PO => 'Poor',
        };
    }
}
