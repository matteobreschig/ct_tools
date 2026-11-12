<?php

namespace App\Services\Pokecardex;

use App\Enums\PokecardexSyncDirection;

/**
 * Scrittura proposta per una chiave: porta un lato al valore dell'altro, o a
 * una quantità esplicita (primo giro con copie vendute su CardTrader).
 */
final readonly class KeyChange
{
    /**
     * @param  array{id_card: int, id_version: int}|null  $target  carta e versione Pokecardex dove aggiungere, se il verso è Pokecardex e la quantità cresce
     * @param  int|null  $quantity  quantità da scrivere; null = quella dell'altro lato
     * @param  list<array{quantity: int, paid_at: string, order: string}>  $sales  vendite CardTrader che motivano la scrittura
     */
    public function __construct(
        public KeyState $state,
        public PokecardexSyncDirection $direction,
        public ?array $target = null,
        public ?int $quantity = null,
        public array $sales = [],
    ) {}

    public function quantityBefore(): int
    {
        return $this->direction === PokecardexSyncDirection::ToCardTrader
            ? $this->state->cardtrader
            : $this->state->pokecardex;
    }

    public function quantityAfter(): int
    {
        return $this->quantity ?? ($this->direction === PokecardexSyncDirection::ToCardTrader
            ? $this->state->pokecardex
            : $this->state->cardtrader);
    }

    /**
     * @param  array{id_card: int, id_version: int}  $target
     */
    public function withTarget(array $target): self
    {
        return new self($this->state, $this->direction, $target, $this->quantity, $this->sales);
    }

    public function removedCopies(): int
    {
        return max(0, $this->quantityBefore() - $this->quantityAfter());
    }
}
