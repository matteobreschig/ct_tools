<?php

namespace App\Services\Pokecardex;

use App\Models\PokecardexCard;

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

    /**
     * Impronta delle scritture: uguale solo se il piano scriverebbe le stesse
     * cose. Serve ad applicare un'anteprima approvata solo se nel frattempo
     * nulla è cambiato.
     */
    public function fingerprint(): string
    {
        return sha1(json_encode(array_map(fn (KeyChange $change): array => [
            $change->state->key->id(),
            $change->direction->value,
            $change->quantityBefore(),
            $change->quantityAfter(),
            $change->target,
        ], $this->changes)));
    }

    /**
     * Copie su ciascun lato dopo le scritture del piano.
     *
     * @return array{pokecardex: int, cardtrader: int}
     */
    public function copiesAfter(): array
    {
        $copies = ['pokecardex' => $this->pokecardexCopies, 'cardtrader' => $this->cardtraderCopies];

        foreach ($this->changes as $change) {
            $side = $change->direction->value === 'to_cardtrader' ? 'cardtrader' : 'pokecardex';
            $copies[$side] += $change->quantityAfter() - $change->quantityBefore();
        }

        return $copies;
    }

    /**
     * Il piano in forma salvabile, per l'anteprima e lo storico.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $cardIds = collect($this->changes)
            ->flatMap(fn (KeyChange $change): array => [...array_map(fn (PokecardexRow $row): int => $row->idCard, $change->state->rows), $change->target['id_card'] ?? null])
            ->filter()
            ->unique();
        $cards = PokecardexCard::query()->whereIn('id_card', $cardIds)->get(['id_card', 'name_en', 'num_card'])->keyBy('id_card');
        $card = fn (KeyChange $change): ?PokecardexCard => $cards->get($change->state->rows[0]->idCard ?? $change->target['id_card'] ?? 0);

        return [
            'first_run' => $this->firstRun,
            'pokecardex_copies' => $this->pokecardexCopies,
            'cardtrader_copies' => $this->cardtraderCopies,
            'changes' => array_map(fn (KeyChange $change): array => [
                'key' => $change->state->key->attributes(),
                'name' => $change->state->products[0]->name ?? $card($change)?->name_en,
                'collector_number' => $change->state->products[0]->collector_number ?? $card($change)?->num_card,
                'direction' => $change->direction->value,
                'before' => $change->quantityBefore(),
                'after' => $change->quantityAfter(),
                'pokecardex' => $change->state->pokecardex,
                'cardtrader' => $change->state->cardtrader,
                'target' => $change->target,
                'sales' => $change->sales,
            ], $this->changes),
            'conflicts' => count($this->conflicts),
            'reviews' => array_map(fn (ReviewItem $item): array => ['kind' => $item->kind, 'message' => $item->message, 'payload' => $item->payload], $this->reviews),
            'pending' => array_map(fn (ReviewItem $item): array => ['kind' => $item->kind, 'message' => $item->message, 'payload' => $item->payload], $this->pending),
        ];
    }
}
