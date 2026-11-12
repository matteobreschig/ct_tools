<?php

namespace App\Services\Pokecardex;

use App\Services\PokecardexClient;
use RuntimeException;

/**
 * Scritture su Pokecardex per una chiave carta (§ 4.4 del design).
 *
 * - Aumento: sulla riga con la stessa traduzione (carta, versione, condizione,
 *   lingua), se c'è; altrimenti una riga nuova.
 * - Diminuzione: dalla riga più recente (`id_collection` più alto) in giù; una
 *   riga che arriva a zero si cancella.
 */
class PokecardexWriter
{
    public function __construct(private PokecardexClient $client) {}

    public function write(KeyChange $change): void
    {
        $current = $change->state->pokecardex;
        $target = $change->quantityAfter();

        if ($target > $current) {
            $this->add($change, $target - $current);
        } elseif ($target < $current) {
            $this->remove($change->state, $current - $target);
        }
    }

    private function add(KeyChange $change, int $copies): void
    {
        $state = $change->state;
        $destination = $change->target ?? $this->targetFromRows($state);
        $idEtat = Translator::conditionToPokecardex($state->key->condition);
        $idLangue = Translator::languageToPokecardex($state->key->language);

        if ($destination === null || $idLangue === null) {
            throw new RuntimeException("Non so dove aggiungere su Pokecardex il blueprint {$state->key->blueprintId}.");
        }

        $same = collect($state->rows)->first(fn (PokecardexRow $row): bool => $row->idCard === $destination['id_card']
            && $row->idVersion === $destination['id_version']
            && $row->idEtat === $idEtat
            && $row->idLangue === $idLangue);

        if ($same !== null) {
            $this->client->updateRow($same->idCard, $same->idCollection, $same->idVersion, $same->idEtat, $same->idLangue, $same->quantity + $copies);

            return;
        }

        $this->client->addRow($destination['id_card'], $destination['id_version'], $idEtat, $idLangue, $copies);
    }

    private function remove(KeyState $state, int $copies): void
    {
        $rows = collect($state->rows)->sortByDesc(fn (PokecardexRow $row): int => $row->idCollection)->values();

        foreach ($rows as $row) {
            if ($copies === 0) {
                break;
            }

            $taken = min($row->quantity, $copies);
            $copies -= $taken;

            if ($taken === $row->quantity) {
                $this->client->deleteRow($row->idCollection);

                continue;
            }

            $this->client->updateRow($row->idCard, $row->idCollection, $row->idVersion, $row->idEtat, $row->idLangue, $row->quantity - $taken);
        }

        if ($copies > 0) {
            throw new RuntimeException("Mancano {$copies} copie da togliere su Pokecardex per il blueprint {$state->key->blueprintId}.");
        }
    }

    /**
     * @return array{id_card: int, id_version: int}|null
     */
    private function targetFromRows(KeyState $state): ?array
    {
        $targets = collect($state->rows)
            ->map(fn (PokecardexRow $row): array => ['id_card' => $row->idCard, 'id_version' => $row->idVersion])
            ->unique(fn (array $target): string => $target['id_card'].'|'.$target['id_version']);

        return $targets->count() === 1 ? $targets->first() : null;
    }
}
