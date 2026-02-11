<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum RegulationMark: string implements HasLabel
{
    case A = 'A';
    case B = 'B';
    case C = 'C';
    case D = 'D';
    case E = 'E';
    case F = 'F';
    case G = 'G';
    case H = 'H';
    case I = 'I';
    case J = 'J';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::A => 'A',
            self::B => 'B',
            self::C => 'C',
            self::D => 'D',
            self::E => 'E',
            self::F => 'F',
            self::G => 'G',
            self::H => 'H',
            self::I => 'I',
            self::J => 'J',
        };
    }
}
