<?php

namespace App\Services\Pokecardex;

use App\Enums\PokecardexMatchStatus;

/**
 * Da un blueprint CardTrader (con reverse e prima edizione) alla carta e
 * versione Pokecardex.
 */
final readonly class BlueprintMatch
{
    public function __construct(
        public PokecardexMatchStatus $status,
        public ?int $idCard = null,
        public ?int $idVersion = null,
        public ?string $reason = null,
    ) {}

    public static function review(string $reason): self
    {
        return new self(PokecardexMatchStatus::Review, reason: $reason);
    }
}
