<?php

namespace App\Services\Pokecardex;

/**
 * Esito del confronto a tre: cosa scrivere, cosa è già allineato, cosa chiede
 * una decisione. Non scrive niente.
 */
final readonly class SyncPlan
{
    /**
     * @param  list<KeyChange>  $changes
     * @param  list<KeyState>  $agreed  chiavi con P = C: la base diventa P
     * @param  list<KeyState>  $conflicts
     * @param  list<ReviewItem>  $reviews
     * @param  list<ReviewItem>  $pending
     * @param  int  $pokecardexCopies  copie lette su Pokecardex, tutte le righe
     * @param  int  $cardtraderCopies  copie lette su CardTrader, tutte le inserzioni
     */
    public function __construct(
        public bool $firstRun,
        public array $changes,
        public array $agreed,
        public array $conflicts,
        public array $reviews,
        public array $pending,
        public int $pokecardexCopies,
        public int $cardtraderCopies,
    ) {}

    public function removedCopies(): int
    {
        return array_sum(array_map(fn (KeyChange $change): int => $change->removedCopies(), $this->changes));
    }
}
