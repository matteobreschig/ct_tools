<?php

namespace App\Services\Pokecardex;

use App\Enums\PokecardexMatchStatus;
use App\Enums\PokecardexSyncDirection;
use App\Models\Expansion;
use App\Models\PokecardexCard;
use App\Models\PokecardexSyncState;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Confronto a tre (§ 4 del design): per ogni chiave carta mette a confronto
 * la quantità su Pokecardex (P), quella su CardTrader (C) e la base concordata
 * all'ultimo giro, e decide cosa scrivere. Non scrive niente.
 *
 * | Caso                       | Azione                    |
 * |----------------------------|---------------------------|
 * | P = C                      | nessuna, la base diventa P |
 * | P ≠ base, C = base         | P su CardTrader           |
 * | P = base, C ≠ base         | C su Pokecardex           |
 * | P ≠ base, C ≠ base, P ≠ C  | conflitto                 |
 *
 * Al primo giro (nessuna base) ogni differenza propone il valore di Pokecardex
 * (decisione dell'utente, 2026-11-12: le carte si inseriscono su Pokecardex e
 * le differenze sono copie mai portate su CardTrader) e passa dall'anteprima.
 */
final class SyncPlanner
{
    /** @var list<ReviewItem> */
    private array $reviews = [];

    /** @var list<ReviewItem> */
    private array $pending = [];

    /**
     * Carte Pokecardex con almeno una riga senza abbinamento sicuro: non si
     * aggiunge niente su di loro, si rischierebbe un doppione.
     *
     * @var array<int, true>
     */
    private array $quarantinedCards = [];

    public function __construct(private CardMatcher $matcher) {}

    /**
     * @param  array{collection: list<array<string, mixed>>}&array<string, mixed>  $collection  risposta di `get_all_collection`
     */
    public function plan(User $user, array $collection): SyncPlan
    {
        $this->reviews = [];
        $this->pending = [];
        $this->quarantinedCards = [];

        $rows = collect($collection['collection'])->map(fn (array $row): PokecardexRow => PokecardexRow::fromArray($row));
        $pokecardexKeys = $this->pokecardexKeys($rows);

        $products = Product::query()->where('user_id', $user->id)->whereNotNull('blueprint_id')->get();
        $cardtraderKeys = $this->cardtraderKeys($products);

        $bases = PokecardexSyncState::query()
            ->where('user_id', $user->id)
            ->get()
            ->keyBy(fn (PokecardexSyncState $state): string => $state->key()->id());

        $firstRun = $bases->isEmpty();
        $changes = [];
        $agreed = [];
        $conflicts = [];

        $keyIds = collect(array_keys($pokecardexKeys))
            ->merge(array_keys($cardtraderKeys))
            ->merge($bases->keys())
            ->unique()
            ->sort()
            ->values();

        foreach ($keyIds as $keyId) {
            $key = $pokecardexKeys[$keyId]['key'] ?? $cardtraderKeys[$keyId]['key'] ?? $bases->get($keyId)->key();
            $state = new KeyState(
                $key,
                pokecardex: array_sum(array_map(fn (PokecardexRow $row): int => $row->quantity, $pokecardexKeys[$keyId]['rows'] ?? [])),
                cardtrader: (int) collect($cardtraderKeys[$keyId]['products'] ?? [])->sum('quantity'),
                base: $bases->get($keyId)?->quantity,
                rows: $pokecardexKeys[$keyId]['rows'] ?? [],
                products: $cardtraderKeys[$keyId]['products'] ?? [],
            );

            if ($state->pokecardex === $state->cardtrader) {
                $agreed[] = $state;

                continue;
            }

            $direction = $this->direction($state, $firstRun);

            if ($direction === null) {
                $conflicts[] = $state;
                $this->reviews[] = new ReviewItem('conflict', 'Modificata su entrambi i lati in modo diverso.', [
                    'key' => $key->attributes(), 'pokecardex' => $state->pokecardex, 'cardtrader' => $state->cardtrader, 'base' => $state->base,
                ]);

                continue;
            }

            $change = $this->prepare(new KeyChange($state, $direction));

            if ($change !== null) {
                $changes[] = $change;
            }
        }

        return new SyncPlan(
            firstRun: $firstRun,
            changes: $changes,
            agreed: $agreed,
            conflicts: $conflicts,
            reviews: $this->reviews,
            pending: $this->pending,
            pokecardexCopies: $rows->sum(fn (PokecardexRow $row): int => $row->quantity),
            cardtraderCopies: (int) $products->sum('quantity'),
        );
    }

    private function direction(KeyState $state, bool $firstRun): ?PokecardexSyncDirection
    {
        if ($firstRun) {
            return PokecardexSyncDirection::ToCardTrader;
        }

        $base = $state->base ?? 0;

        return match (true) {
            $state->pokecardex !== $base && $state->cardtrader === $base => PokecardexSyncDirection::ToCardTrader,
            $state->pokecardex === $base && $state->cardtrader !== $base => PokecardexSyncDirection::ToPokecardex,
            default => null,
        };
    }

    /**
     * Un aumento su Pokecardex deve sapere dove aggiungere: carta e versione,
     * in una lingua che esiste per quella versione.
     */
    private function prepare(KeyChange $change): ?KeyChange
    {
        if ($change->direction !== PokecardexSyncDirection::ToPokecardex || $change->quantityAfter() <= $change->quantityBefore()) {
            return $change;
        }

        $state = $change->state;
        $payload = ['key' => $state->key->attributes(), 'pokecardex' => $state->pokecardex, 'cardtrader' => $state->cardtrader];
        $target = $this->target($state);

        if ($target === null) {
            return null;
        }

        if (isset($this->quarantinedCards[$target['id_card']])) {
            $this->reviews[] = new ReviewItem('quarantine', 'La carta ha righe Pokecardex da rivedere: niente aggiunte finché non sono risolte.', $payload + $target);

            return null;
        }

        $idLangue = Translator::languageToPokecardex($state->key->language);
        $languages = PokecardexCard::find($target['id_card'])?->languagesOf($target['id_version']);

        if ($idLangue === null || ($languages !== null && ! in_array($idLangue, $languages, true))) {
            $this->reviews[] = new ReviewItem('language', "La carta Pokecardex non esiste in lingua {$state->key->language->value}.", $payload + $target);

            return null;
        }

        return new KeyChange($state, $change->direction, $target);
    }

    /**
     * @return array{id_card: int, id_version: int}|null
     */
    private function target(KeyState $state): ?array
    {
        $fromRows = collect($state->rows)
            ->map(fn (PokecardexRow $row): array => ['id_card' => $row->idCard, 'id_version' => $row->idVersion])
            ->unique(fn (array $target): string => $target['id_card'].'|'.$target['id_version']);

        if ($fromRows->count() === 1) {
            return $fromRows->first();
        }

        $payload = ['key' => $state->key->attributes(), 'pokecardex' => $state->pokecardex, 'cardtrader' => $state->cardtrader];
        $expansion = Expansion::find($state->products[0]->expansion_id ?? null);

        if ($expansion === null) {
            $this->reviews[] = new ReviewItem('no_target', 'Inserzione CardTrader senza espansione: non so dove aggiungerla su Pokecardex.', $payload);

            return null;
        }

        $match = $this->matcher->matchBlueprint($state->key->blueprintId, $expansion, $state->key->reverse, $state->key->firstEdition);

        if ($match->status !== PokecardexMatchStatus::Matched) {
            $item = new ReviewItem($match->status === PokecardexMatchStatus::Pending ? 'pending' : 'no_target', (string) $match->reason, $payload);
            $match->status === PokecardexMatchStatus::Pending ? $this->pending[] = $item : $this->reviews[] = $item;

            return null;
        }

        return ['id_card' => $match->idCard, 'id_version' => $match->idVersion];
    }

    /**
     * Righe Pokecardex tradotte in chiavi carta. Le righe senza abbinamento o
     * senza traduzione finiscono in revisione o in attesa.
     *
     * @param  Collection<int, PokecardexRow>  $rows
     * @return array<string, array{key: CardKey, rows: list<PokecardexRow>}>
     */
    private function pokecardexKeys(Collection $rows): array
    {
        $keys = [];
        $cards = PokecardexCard::query()->whereIn('id_card', $rows->pluck('idCard')->unique())->get()->keyBy('id_card');

        foreach ($rows as $row) {
            $payload = ['row' => $row->toArray()];
            $card = $cards[$row->idCard] ?? null;

            if ($card === null) {
                $this->reviews[] = new ReviewItem('unmatched', 'Carta assente dal catalogo Pokecardex locale.', $payload);
                $this->quarantinedCards[$row->idCard] = true;

                continue;
            }

            $payload['card'] = ['name' => $card->name_en, 'number' => $card->num_card, 'id_serie' => $card->id_serie];
            $condition = Translator::conditionFromPokecardex($row->idEtat);
            $language = Translator::languageFromPokecardex($row->idLangue);

            if ($language === null) {
                $this->reviews[] = new ReviewItem('language', 'Lingua Pokecardex senza corrispondenza su CardTrader.', $payload);

                continue;
            }

            if ($condition === null) {
                $this->reviews[] = new ReviewItem('condition', 'Condizione Pokecardex sconosciuta.', $payload);

                continue;
            }

            $match = $this->matcher->match($card, $row->idVersion);

            if ($match->status !== PokecardexMatchStatus::Matched) {
                $this->quarantinedCards[$row->idCard] = true;
                $item = new ReviewItem($match->status === PokecardexMatchStatus::Pending ? 'pending' : 'unmatched', (string) $match->reason, $payload);
                $match->status === PokecardexMatchStatus::Pending ? $this->pending[] = $item : $this->reviews[] = $item;

                continue;
            }

            $key = new CardKey($match->blueprintId, $condition, $language, $match->reverse, $match->firstEdition);
            $keys[$key->id()]['key'] = $key;
            $keys[$key->id()]['rows'][] = $row;
        }

        return $keys;
    }

    /**
     * Inserzioni CardTrader raggruppate per chiave, escluse quelle firmate o
     * alterate (Pokecardex non le rappresenta).
     *
     * @param  Collection<int, Product>  $products
     * @return array<string, array{key: CardKey, products: list<Product>}>
     */
    private function cardtraderKeys(Collection $products): array
    {
        $keys = [];

        foreach ($products as $product) {
            if ($product->signed || $product->altered || $product->condition === null || $product->language === null) {
                continue;
            }

            $key = new CardKey($product->blueprint_id, $product->condition, $product->language, (bool) $product->reverse, (bool) $product->first_edition);
            $keys[$key->id()]['key'] = $key;
            $keys[$key->id()]['products'][] = $product;
        }

        return $keys;
    }
}
