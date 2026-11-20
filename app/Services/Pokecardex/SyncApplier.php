<?php

namespace App\Services\Pokecardex;

use App\Enums\PokecardexSyncDirection;
use App\Models\CtExpansionBlueprint;
use App\Models\PokecardexSyncState;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Esegue le scritture di un piano e aggiorna la base concordata (§ 4.2).
 *
 * - Le scritture di una carta vanno insieme e gli aumenti vengono prima delle
 *   diminuzioni: se una scrittura fallisce, la carta ha al massimo copie in
 *   più, mai copie perse.
 * - La base di una chiave cambia solo quando tutte le scritture della sua
 *   carta sono riuscite.
 * - Al primo errore il giro si ferma: un errore ripetuto (sessione scaduta,
 *   sito cambiato) non deve produrre una raffica di scritture sbagliate.
 */
final class SyncApplier
{
    public function __construct(
        private CardTraderWriter $cardtrader,
        private PokecardexWriter $pokecardex,
    ) {}

    public function apply(User $user, SyncPlan $plan): ApplyResult
    {
        // Un primo giro riscrive la base da zero: quella lasciata da un
        // tentativo interrotto descrive uno stato che il piano non usa più.
        if ($plan->firstRun) {
            PokecardexSyncState::query()->where('user_id', $user->id)->delete();
        }

        $this->mergeFixedReverseBases($user);

        foreach ($plan->agreed as $state) {
            $this->saveBase($user, $state->key, $state->pokecardex);
        }

        $writes = [PokecardexSyncDirection::ToCardTrader->value => 0, PokecardexSyncDirection::ToPokecardex->value => 0];

        $cards = collect($plan->changes)->groupBy(fn (KeyChange $change): string => $change->state->key->variantId());

        foreach ($cards as $changes) {
            $ordered = $changes->sortBy(fn (KeyChange $change): int => $change->quantityAfter() > $change->quantityBefore() ? 0 : 1);

            try {
                foreach ($ordered as $change) {
                    $change->direction === PokecardexSyncDirection::ToCardTrader
                        ? $this->cardtrader->write($change)
                        : $this->pokecardex->write($change);

                    $writes[$change->direction->value]++;
                }
            } catch (Throwable $exception) {
                $key = $ordered->first()->state->key;

                return new ApplyResult(
                    $writes[PokecardexSyncDirection::ToCardTrader->value],
                    $writes[PokecardexSyncDirection::ToPokecardex->value],
                    "Scrittura fallita per il blueprint {$key->blueprintId} ({$key->condition->value}, {$key->language->value}): {$exception->getMessage()}",
                );
            }

            foreach ($changes->groupBy(fn (KeyChange $change): string => $change->state->key->id()) as $keyChanges) {
                $this->saveBase($user, $keyChanges->first()->state->key, $keyChanges->first()->quantityAfter());
            }
        }

        return new ApplyResult(
            $writes[PokecardexSyncDirection::ToCardTrader->value],
            $writes[PokecardexSyncDirection::ToPokecardex->value],
        );
    }

    /**
     * Dove CardTrader decide da solo il reverse, la base reverse si somma a
     * quella normale e sparisce: è la stessa chiave per il piano
     * (`CardKey::canonical()`), che ha già sommato le due quantità.
     */
    private function mergeFixedReverseBases(User $user): void
    {
        $fixedReverse = CtExpansionBlueprint::fixedReverseIds();

        $stale = PokecardexSyncState::query()
            ->where('user_id', $user->id)
            ->where('reverse', true)
            ->get()
            ->filter(fn (PokecardexSyncState $state): bool => isset($fixedReverse[$state->blueprint_id]));

        foreach ($stale as $state) {
            DB::transaction(function () use ($user, $state, $fixedReverse): void {
                $canonical = PokecardexSyncState::firstOrNew(['user_id' => $user->id, ...$state->key()->canonical($fixedReverse)->attributes()]);
                $canonical->quantity = (int) $canonical->quantity + $state->quantity;
                $canonical->save();
                $state->delete();
            });
        }
    }

    /**
     * Una base a zero equivale a nessuna base: la riga si toglie.
     */
    private function saveBase(User $user, CardKey $key, int $quantity): void
    {
        $attributes = ['user_id' => $user->id, ...$key->attributes()];

        if ($quantity === 0) {
            PokecardexSyncState::query()->where($attributes)->delete();

            return;
        }

        PokecardexSyncState::updateOrCreate($attributes, ['quantity' => $quantity]);
    }
}
