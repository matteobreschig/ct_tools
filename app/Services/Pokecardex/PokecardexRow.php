<?php

namespace App\Services\Pokecardex;

/**
 * Una riga della collezione Pokecardex.
 */
final readonly class PokecardexRow
{
    public function __construct(
        public int $idCollection,
        public int $idCard,
        public int $idVersion,
        public int $idEtat,
        public int $idLangue,
        public int $quantity,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            (int) $row['id_collection'],
            (int) $row['id_card'],
            (int) $row['id_version'],
            (int) $row['id_etat'],
            (int) $row['id_langue'],
            (int) $row['quantite'],
        );
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'id_collection' => $this->idCollection,
            'id_card' => $this->idCard,
            'id_version' => $this->idVersion,
            'id_etat' => $this->idEtat,
            'id_langue' => $this->idLangue,
            'quantite' => $this->quantity,
        ];
    }
}
