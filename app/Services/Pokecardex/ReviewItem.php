<?php

namespace App\Services\Pokecardex;

/**
 * Un problema che chiede una decisione dell'utente (§ 6 del design).
 *
 * Tipi: `unmatched` (riga Pokecardex senza blueprint sicuro), `pending` (blueprint
 * non ancora disponibile), `language` e `condition` (nessuna traduzione),
 * `no_target` (nessuna carta Pokecardex dove aggiungere), `quarantine` (la carta
 * ha righe Pokecardex da rivedere), `conflict` (modifica su entrambi i lati).
 */
final readonly class ReviewItem
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $kind,
        public string $message,
        public array $payload = [],
    ) {}
}
