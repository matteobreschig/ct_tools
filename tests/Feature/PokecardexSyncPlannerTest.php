<?php

use App\Enums\ConditionType;
use App\Enums\Language;
use App\Enums\PokecardexSyncDirection;
use App\Models\CtExpansionBlueprint;
use App\Models\Expansion;
use App\Models\PokecardexAccount;
use App\Models\PokecardexCard;
use App\Models\PokecardexSeries;
use App\Models\PokecardexSyncRun;
use App\Models\PokecardexSyncState;
use App\Models\Product;
use App\Models\User;
use App\Services\CTApiClient;
use App\Services\Pokecardex\BlueprintCatalog;
use App\Services\Pokecardex\CardKey;
use App\Services\Pokecardex\CardMatcher;
use App\Services\Pokecardex\PokecardexCatalog;
use App\Services\Pokecardex\SyncPlan;
use App\Services\Pokecardex\SyncPlanner;
use App\Services\PokecardexClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
 * Confronto a tre (§ 4 del design). Cataloghi già caricati: nessuna chiamata
 * esce. Carta di prova: Froslass 53 di TWM, blueprint 287733, italiano.
 */

const FROSLASS_BLUEPRINT = 287733;

beforeEach(function () {
    Http::preventStrayRequests();

    $this->user = User::factory()->create(['ct_api_key' => 'test-key']);
    $account = PokecardexAccount::factory()->for($this->user)->create();

    $this->planner = new SyncPlanner(new CardMatcher(
        new PokecardexCatalog(new PokecardexClient($account)),
        new BlueprintCatalog(new CTApiClient($this->user)),
    ));

    $this->expansion = Expansion::factory()->create(['code' => 'twm', 'blueprints_loaded_at' => now()]);
    $series = PokecardexSeries::factory()->create(['code' => 'TWM', 'expansion_id' => $this->expansion->id, 'cards_loaded_at' => now()]);
    $this->card = PokecardexCard::factory()->create([
        'id_card' => 26600, 'id_serie' => $series->id_serie, 'num_card' => '053/167', 'id_cardmarket' => 769227,
        'versions' => [
            ['id_version' => 1, 'nom_version_us' => 'Standard', 'langues' => [1, 2, 4]],
            ['id_version' => 2, 'nom_version_us' => 'Reverse', 'langues' => [1, 2, 4]],
            ['id_version' => 197, 'nom_version_us' => 'Reverse (Masterball)', 'langues' => [2]],
        ],
    ]);
    CtExpansionBlueprint::factory()->create([
        'blueprint_id' => FROSLASS_BLUEPRINT, 'expansion_ct_id' => $this->expansion->ct_id,
        'collector_number' => '053', 'cardmarket_product_id' => 769227,
    ]);
});

/**
 * @param  list<array{0: int, 1: int, 2: int, 3: int, 4?: int}>  $rows  [id_collection, id_version, id_etat, quantite, id_langue]
 * @return array{cartes: list<array<string, mixed>>, collection: list<array<string, int>>}
 */
function froslassCollection(array $rows): array
{
    return [
        'cartes' => [],
        'collection' => array_map(fn (array $row): array => [
            'id_collection' => $row[0], 'id_card' => 26600, 'id_version' => $row[1],
            'id_etat' => $row[2], 'id_langue' => $row[4] ?? 4, 'quantite' => $row[3],
        ], $rows),
    ];
}

function froslassListing(int $quantity, array $attributes = []): Product
{
    return Product::factory()->create(array_merge([
        'user_id' => test()->user->id, 'blueprint_id' => FROSLASS_BLUEPRINT, 'expansion_id' => test()->expansion->id,
        'condition' => 'nm', 'language' => 'it', 'reverse' => false, 'quantity' => $quantity,
    ], $attributes));
}

function froslassBase(int $quantity, bool $reverse = false, string $language = 'it'): void
{
    // Una base vale solo dopo un giro applicato: senza, il giro resta il primo.
    PokecardexSyncRun::query()->firstOrCreate(
        ['user_id' => test()->user->id, 'outcome' => 'applied'],
        ['started_at' => now()->subDay(), 'finished_at' => now()->subDay()],
    );
    PokecardexSyncState::create([
        'user_id' => test()->user->id, 'blueprint_id' => FROSLASS_BLUEPRINT, 'condition' => 'nm',
        'language' => $language, 'reverse' => $reverse, 'first_edition' => false, 'quantity' => $quantity,
    ]);
}

function froslassKey(bool $reverse = false): string
{
    return (new CardKey(FROSLASS_BLUEPRINT, ConditionType::NM, Language::IT, $reverse, false))->id();
}

function onlyChange(SyncPlan $plan)
{
    expect($plan->changes)->toHaveCount(1);

    return $plan->changes[0];
}

it('proposes Pokecardex values on the first run', function () {
    froslassListing(1);

    $plan = $this->planner->plan($this->user, froslassCollection([[101, 1, 6, 3]]));

    expect($plan->firstRun)->toBeTrue();
    $change = onlyChange($plan);
    expect($change->direction)->toBe(PokecardexSyncDirection::ToCardTrader)
        ->and($change->state->pokecardex)->toBe(3)
        ->and($change->state->cardtrader)->toBe(1)
        ->and($change->quantityAfter())->toBe(3);
});

it('records agreed keys when both sides match', function () {
    froslassListing(2);

    $plan = $this->planner->plan($this->user, froslassCollection([[101, 1, 1, 1], [102, 1, 6, 1]]));

    expect($plan->changes)->toBeEmpty()
        ->and($plan->agreed)->toHaveCount(1)
        ->and($plan->agreed[0]->key->id())->toBe(froslassKey());
});

it('applies the three-way rules', function (int $pokecardex, int $cardtrader, int $base, ?PokecardexSyncDirection $direction, bool $conflict) {
    froslassBase($base);
    if ($cardtrader > 0) {
        froslassListing($cardtrader);
    }
    $rows = $pokecardex > 0 ? [[101, 1, 6, $pokecardex]] : [];

    $plan = $this->planner->plan($this->user, froslassCollection($rows));

    expect($plan->firstRun)->toBeFalse()
        ->and($plan->conflicts)->toHaveCount($conflict ? 1 : 0);

    if ($direction === null) {
        expect($plan->changes)->toBeEmpty();
    } else {
        expect(onlyChange($plan)->direction)->toBe($direction);
    }
})->with([
    'nothing changed' => [2, 2, 2, null, false],
    'changed on Pokecardex' => [3, 2, 2, PokecardexSyncDirection::ToCardTrader, false],
    'removed on Pokecardex' => [0, 2, 2, PokecardexSyncDirection::ToCardTrader, false],
    'sold on CardTrader' => [2, 1, 2, PokecardexSyncDirection::ToPokecardex, false],
    'changed on both sides the same way' => [3, 3, 2, null, false],
    'changed on both sides differently' => [3, 1, 2, null, true],
    'new on Pokecardex' => [2, 0, 0, PokecardexSyncDirection::ToCardTrader, false],
]);

it('puts Neuve and Excellent on the same NM key', function () {
    froslassBase(1);
    froslassListing(1);

    $plan = $this->planner->plan($this->user, froslassCollection([[101, 1, 1, 1], [102, 1, 6, 2]]));

    $change = onlyChange($plan);
    expect($change->state->pokecardex)->toBe(3)
        ->and($change->direction)->toBe(PokecardexSyncDirection::ToCardTrader)
        ->and($change->state->rows)->toHaveCount(2);
});

it('ignores signed and altered listings', function () {
    froslassBase(1);
    froslassListing(1);
    froslassListing(5, ['signed' => true]);
    froslassListing(5, ['altered' => true]);

    $plan = $this->planner->plan($this->user, froslassCollection([[101, 1, 6, 1]]));

    expect($plan->changes)->toBeEmpty()
        ->and($plan->cardtraderCopies)->toBe(11);
});

it('keeps reverse and standard copies on different keys', function () {
    froslassListing(1);
    froslassListing(2, ['reverse' => true]);

    $plan = $this->planner->plan($this->user, froslassCollection([[101, 1, 6, 1], [102, 2, 6, 2]]));

    expect($plan->changes)->toBeEmpty()
        ->and(collect($plan->agreed)->map(fn ($state) => $state->key->id())->sort()->values()->all())
        ->toBe(collect([froslassKey(), froslassKey(true)])->sort()->values()->all());
});

it('finds where to add on Pokecardex', function () {
    froslassBase(0, reverse: true);
    froslassListing(2, ['reverse' => true]);

    $plan = $this->planner->plan($this->user, froslassCollection([[101, 1, 6, 1]]));

    $change = collect($plan->changes)->first(fn ($change) => $change->state->key->reverse);
    expect($change->direction)->toBe(PokecardexSyncDirection::ToPokecardex)
        ->and($change->target)->toBe(['id_card' => 26600, 'id_version' => 2]);
});

it('does not add on Pokecardex in a language the card does not exist in', function () {
    froslassBase(0, language: 'de');
    froslassListing(1, ['language' => 'de']);

    $plan = $this->planner->plan($this->user, froslassCollection([]));

    expect($plan->changes)->toBeEmpty()
        ->and($plan->reviews)->toHaveCount(1)
        ->and($plan->reviews[0]->kind)->toBe('language');
});

it('does not add on Pokecardex a card that has rows to review there', function () {
    froslassBase(0);
    froslassListing(1);

    $plan = $this->planner->plan($this->user, froslassCollection([[101, 197, 6, 1, 2]]));

    expect($plan->changes)->toBeEmpty()
        ->and(collect($plan->reviews)->pluck('kind')->sort()->values()->all())->toBe(['quarantine', 'unmatched']);
});

it('sends to review the Pokecardex rows in languages CardTrader does not have', function () {
    $plan = $this->planner->plan($this->user, froslassCollection([[101, 1, 6, 1, 8]]));

    expect($plan->changes)->toBeEmpty()
        ->and($plan->reviews[0]->kind)->toBe('language')
        ->and($plan->pokecardexCopies)->toBe(1);
});

it('counts the copies that a plan would remove', function () {
    froslassBase(5);
    froslassListing(5);

    $plan = $this->planner->plan($this->user, froslassCollection([[101, 1, 6, 2]]));

    expect(onlyChange($plan)->removedCopies())->toBe(3)
        ->and($plan->removedCopies())->toBe(3);
});

/**
 * @return array<string, list<array{quantity: int, paid_at: string, order: string}>>
 */
function froslassSales(int $quantity, string $paidAt = '2026-11-03'): array
{
    return [froslassKey() => [['quantity' => $quantity, 'paid_at' => $paidAt, 'order' => 'C1']]];
}

it('removes from Pokecardex the copies already sold on CardTrader on the first run', function () {
    froslassListing(1);

    $plan = $this->planner->plan($this->user, froslassCollection([[101, 1, 6, 2]]), froslassSales(1));

    $change = onlyChange($plan);
    expect($change->direction)->toBe(PokecardexSyncDirection::ToPokecardex)
        ->and($change->quantityBefore())->toBe(2)
        ->and($change->quantityAfter())->toBe(1)
        ->and($change->sales[0]['paid_at'])->toBe('2026-11-03');
});

it('splits a first-run difference between sold copies and copies to add', function () {
    froslassListing(1);

    $plan = $this->planner->plan($this->user, froslassCollection([[101, 1, 6, 4]]), froslassSales(1));

    $byDirection = collect($plan->changes)->keyBy(fn ($change) => $change->direction->value);
    expect($plan->changes)->toHaveCount(2)
        ->and($byDirection['to_pokecardex']->quantityAfter())->toBe(3)
        ->and($byDirection['to_cardtrader']->quantityBefore())->toBe(1)
        ->and($byDirection['to_cardtrader']->quantityAfter())->toBe(3);
});

it('never counts more sold copies than the difference', function () {
    froslassListing(1);

    $plan = $this->planner->plan($this->user, froslassCollection([[101, 1, 6, 2]]), froslassSales(5));

    expect(onlyChange($plan)->quantityAfter())->toBe(1);
});

it('uses the sales only on the first run', function () {
    froslassBase(1);
    froslassListing(1);

    $plan = $this->planner->plan($this->user, froslassCollection([[101, 1, 6, 2]]), froslassSales(1));

    $change = onlyChange($plan);
    expect($change->direction)->toBe(PokecardexSyncDirection::ToCardTrader)
        ->and($change->quantityAfter())->toBe(2);
});

it('does not remove from CardTrader a card whose Pokecardex rows are to review', function () {
    froslassListing(1);

    $plan = $this->planner->plan($this->user, froslassCollection([[101, 197, 6, 1, 2]]));

    expect($plan->changes)->toBeEmpty()
        ->and(collect($plan->reviews)->pluck('kind')->sort()->values()->all())->toBe(['quarantine', 'unmatched']);
});

it('does not remove from CardTrader a listing with no Pokecardex card', function () {
    $other = CtExpansionBlueprint::factory()->create(['expansion_ct_id' => $this->expansion->ct_id, 'collector_number' => '099', 'cardmarket_product_id' => 123]);
    froslassListing(2, ['blueprint_id' => $other->blueprint_id]);

    $plan = $this->planner->plan($this->user, froslassCollection([]));

    expect($plan->changes)->toBeEmpty()
        ->and($plan->reviews[0]->kind)->toBe('no_target')
        ->and($plan->reviews[0]->message)->toContain('non tolgo');
});

it('removes from CardTrader a card that is known and absent on Pokecardex', function () {
    froslassListing(2);

    $plan = $this->planner->plan($this->user, froslassCollection([]));

    $change = onlyChange($plan);
    expect($change->direction)->toBe(PokecardexSyncDirection::ToCardTrader)
        ->and($change->quantityAfter())->toBe(0);
});

function froslassChanges(SyncPlan $plan): array
{
    return collect($plan->changes)
        ->map(fn ($change) => $change->direction->value.':'.$change->state->key->condition->value.':'.$change->quantityBefore().'>'.$change->quantityAfter())
        ->sort()
        ->values()
        ->all();
}

it('keeps the first run rules after a first apply stopped halfway', function () {
    froslassListing(1, ['condition' => 'sp']);
    PokecardexSyncState::create([
        'user_id' => $this->user->id, 'blueprint_id' => FROSLASS_BLUEPRINT, 'condition' => 'nm',
        'language' => 'it', 'reverse' => false, 'first_edition' => false, 'quantity' => 1,
    ]);

    $plan = $this->planner->plan($this->user, froslassCollection([[101, 1, 6, 1]]));

    expect($plan->firstRun)->toBeTrue()
        ->and(froslassChanges($plan))->toBe(['to_pokecardex:nm:1>0', 'to_pokecardex:sp:0>1']);
});

it('keeps the CardTrader condition when only the condition differs on the first run', function () {
    froslassListing(1, ['condition' => 'sp']);

    $plan = $this->planner->plan($this->user, froslassCollection([[101, 1, 6, 1]]));

    expect(froslassChanges($plan))->toBe(['to_pokecardex:nm:1>0', 'to_pokecardex:sp:0>1']);
    $added = collect($plan->changes)->first(fn ($change) => $change->quantityAfter() > $change->quantityBefore());
    expect($added->target)->toBe(['id_card' => 26600, 'id_version' => 1]);
});

it('takes quantities from Pokecardex and conditions from CardTrader on the first run', function () {
    froslassListing(2, ['condition' => 'mp']);

    $plan = $this->planner->plan($this->user, froslassCollection([[101, 1, 2, 1]]));

    expect(froslassChanges($plan))->toBe(['to_cardtrader:mp:2>1', 'to_pokecardex:mp:0>1', 'to_pokecardex:sp:1>0']);
});

it('adds the extra Pokecardex copies in their own condition on the first run', function () {
    froslassListing(1, ['condition' => 'sp']);

    $plan = $this->planner->plan($this->user, froslassCollection([[101, 1, 6, 3]]));

    expect(froslassChanges($plan))->toBe(['to_cardtrader:nm:0>2', 'to_pokecardex:nm:3>2', 'to_pokecardex:sp:0>1']);
});

it('trusts a language already in the collection even if the catalog does not list it', function () {
    $this->card->update(['versions' => [['id_version' => 1, 'nom_version_us' => 'Standard', 'langues' => [2]]]]);
    froslassListing(1, ['condition' => 'sp']);

    $plan = $this->planner->plan($this->user, froslassCollection([[101, 1, 6, 1]]));

    expect(froslassChanges($plan))->toBe(['to_pokecardex:nm:1>0', 'to_pokecardex:sp:0>1']);
});

it('drops all writes of a card when one of them cannot be done', function () {
    froslassListing(1, ['condition' => 'sp']);

    // La riga Excellent si toglierebbe, ma la nuova riga SP non si può aggiungere
    // perché la carta ha una riga da rivedere (versione speciale senza regola).
    $plan = $this->planner->plan($this->user, froslassCollection([[101, 1, 6, 1], [102, 197, 6, 1, 2]]));

    expect($plan->changes)->toBeEmpty()
        ->and(collect($plan->reviews)->pluck('kind')->sort()->values()->all())->toBe(['quarantine', 'unmatched']);
});

function froslassVariant(): string
{
    return (new CardKey(FROSLASS_BLUEPRINT, ConditionType::NM, Language::IT, false, false))->variantId();
}

it('lets the user keep the CardTrader side of a card on the first run', function () {
    froslassListing(1, ['condition' => 'sp']);

    $plan = $this->planner->plan($this->user, froslassCollection([[101, 1, 6, 3]]), sides: [froslassVariant() => 'cardtrader']);

    expect(froslassChanges($plan))->toBe(['to_pokecardex:nm:3>0', 'to_pokecardex:sp:0>1']);
});

it('lets the user keep the Pokecardex side of a card on the first run, conditions included', function () {
    froslassListing(1, ['condition' => 'sp']);

    $plan = $this->planner->plan($this->user, froslassCollection([[101, 1, 6, 3]]), froslassSales(1), [froslassVariant() => 'pokecardex']);

    expect(froslassChanges($plan))->toBe(['to_cardtrader:nm:0>3', 'to_cardtrader:sp:1>0']);
});

it('resolves a conflict with the side the user chose', function (string $side, string $expected) {
    froslassBase(2);
    froslassListing(1);

    $plan = $this->planner->plan($this->user, froslassCollection([[101, 1, 6, 3]]), sides: [froslassVariant() => $side]);

    expect($plan->conflicts)->toBeEmpty()
        ->and(froslassChanges($plan))->toBe([$expected]);
})->with([
    'Pokecardex' => ['pokecardex', 'to_cardtrader:nm:1>3'],
    'CardTrader' => ['cardtrader', 'to_pokecardex:nm:3>1'],
]);

it('leaves out the rows of an ignored series', function () {
    $topps = PokecardexSeries::factory()->create(['code' => 'TOPPS', 'ignored' => true]);
    PokecardexCard::factory()->create(['id_card' => 99001, 'id_serie' => $topps->id_serie]);

    $plan = $this->planner->plan($this->user, ['cartes' => [], 'collection' => [
        ['id_collection' => 1, 'id_card' => 99001, 'id_version' => 1, 'id_etat' => 6, 'id_langue' => 4, 'quantite' => 2],
    ]]);

    expect($plan->changes)->toBeEmpty()
        ->and($plan->reviews)->toBeEmpty()
        ->and($plan->pokecardexCopies)->toBe(2);
});
