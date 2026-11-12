<?php

namespace App\Services\Pokecardex;

/**
 * Esito dell'applicazione di un piano: scritture fatte per verso ed eventuale
 * errore che ha fermato il giro.
 */
final readonly class ApplyResult
{
    public function __construct(
        public int $cardtraderWrites = 0,
        public int $pokecardexWrites = 0,
        public ?string $failure = null,
    ) {}

    public function failed(): bool
    {
        return $this->failure !== null;
    }
}
