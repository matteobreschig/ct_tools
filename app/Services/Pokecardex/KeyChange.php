<?php

namespace App\Services\Pokecardex;

use App\Enums\PokecardexSyncDirection;

/**
 * Scrittura proposta per una chiave: porta un lato al valore dell'altro.
 */
final readonly class KeyChange
{
    /**
     * @param  array{id_card: int, id_version: int}|null  $target  carta e versione Pokecardex dove aggiungere, se il verso è Pokecardex e la quantità cresce
     */
    public function __construct(
        public KeyState $state,
        public PokecardexSyncDirection $direction,
        public ?array $target = null,
    ) {}

    public function quantityBefore(): int
    {
        return $this->direction === PokecardexSyncDirection::ToCardTrader
            ? $this->state->cardtrader
            : $this->state->pokecardex;
    }

    public function quantityAfter(): int
    {
        return $this->direction === PokecardexSyncDirection::ToCardTrader
            ? $this->state->pokecardex
            : $this->state->cardtrader;
    }

    public function removedCopies(): int
    {
        return max(0, $this->quantityBefore() - $this->quantityAfter());
    }
}
