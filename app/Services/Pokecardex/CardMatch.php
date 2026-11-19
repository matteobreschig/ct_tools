<?php

namespace App\Services\Pokecardex;

use App\Enums\PokecardexMatchStatus;

/**
 * Da una carta Pokecardex (`id_card`, `id_version`) al blueprint CardTrader.
 */
final readonly class CardMatch
{
    public function __construct(
        public PokecardexMatchStatus $status,
        public ?int $blueprintId = null,
        public bool $reverse = false,
        public bool $firstEdition = false,
        public ?string $source = null,
        public ?string $reason = null,
    ) {}

    public static function review(string $reason): self
    {
        return new self(PokecardexMatchStatus::Review, reason: $reason);
    }

    public static function pending(string $reason): self
    {
        return new self(PokecardexMatchStatus::Pending, reason: $reason);
    }
}
