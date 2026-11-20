<?php

use App\Enums\ConditionType;
use App\Enums\Language;
use App\Enums\PokecardexSyncDirection;
use App\Models\CtExpansionBlueprint;
use App\Models\PokecardexSyncState;
use App\Models\User;
use App\Services\Pokecardex\CardKey;
use App\Services\Pokecardex\CardTraderWriter;
use App\Services\Pokecardex\KeyChange;
use App\Services\Pokecardex\KeyState;
use App\Services\Pokecardex\PokecardexWriter;
use App\Services\Pokecardex\SyncApplier;
use App\Services\Pokecardex\SyncPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Applicazione di un piano: ordine delle scritture e base concordata.
 */

function applierKey(string $condition = 'nm', int $blueprintId = 287733): CardKey
{
    return new CardKey($blueprintId, ConditionType::from($condition), Language::IT, false, false);
}

function applierPlan(array $changes, array $agreed = []): SyncPlan
{
    return new SyncPlan(false, $changes, $agreed, [], [], [], 0, 0);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->log = [];
    $this->cardtrader = Mockery::mock(CardTraderWriter::class);
    $this->pokecardex = Mockery::mock(PokecardexWriter::class);
    $this->cardtrader->shouldReceive('write')->andReturnUsing(function (KeyChange $change) {
        $this->log[] = 'ct:'.$change->state->key->condition->value.':'.$change->quantityAfter();
    })->byDefault();
    $this->pokecardex->shouldReceive('write')->andReturnUsing(function (KeyChange $change) {
        $this->log[] = 'pcx:'.$change->state->key->condition->value.':'.$change->quantityAfter();
    })->byDefault();
    $this->applier = new SyncApplier($this->cardtrader, $this->pokecardex);
});

it('writes and stores the agreed quantity as the new base', function () {
    $state = new KeyState(applierKey(), pokecardex: 3, cardtrader: 1, base: 1);
    $agreed = new KeyState(applierKey('sp'), pokecardex: 2, cardtrader: 2, base: null);

    $result = $this->applier->apply($this->user, applierPlan([new KeyChange($state, PokecardexSyncDirection::ToCardTrader)], [$agreed]));

    expect($result->failed())->toBeFalse()
        ->and($result->cardtraderWrites)->toBe(1)
        ->and(PokecardexSyncState::where('condition', 'nm')->value('quantity'))->toBe(3)
        ->and(PokecardexSyncState::where('condition', 'sp')->value('quantity'))->toBe(2);
});

it('adds before it removes within a card', function () {
    $old = new KeyState(applierKey('nm'), pokecardex: 1, cardtrader: 0, base: null);
    $new = new KeyState(applierKey('sp'), pokecardex: 0, cardtrader: 1, base: null);

    $this->applier->apply($this->user, applierPlan([
        new KeyChange($old, PokecardexSyncDirection::ToPokecardex, quantity: 0),
        new KeyChange($new, PokecardexSyncDirection::ToPokecardex, ['id_card' => 1, 'id_version' => 1], quantity: 1),
    ]));

    expect($this->log)->toBe(['pcx:sp:1', 'pcx:nm:0'])
        ->and(PokecardexSyncState::where('condition', 'nm')->exists())->toBeFalse()
        ->and(PokecardexSyncState::where('condition', 'sp')->value('quantity'))->toBe(1);
});

it('rewrites the base from scratch on a first run', function () {
    PokecardexSyncState::create(['user_id' => $this->user->id, ...applierKey('nm', 999)->attributes(), 'quantity' => 4]);
    $agreed = new KeyState(applierKey('nm', 287733), pokecardex: 2, cardtrader: 2, base: null);

    $this->applier->apply($this->user, new SyncPlan(true, [], [$agreed], [], [], [], 2, 2));

    expect(PokecardexSyncState::query()->pluck('quantity', 'blueprint_id')->all())->toBe([287733 => 2]);
});

it('merges the reverse base into the standard one where CardTrader decides the reverse', function () {
    CtExpansionBlueprint::factory()->create(['blueprint_id' => 999, 'reverse_choosable' => false]);
    CtExpansionBlueprint::factory()->create(['blueprint_id' => 287733, 'reverse_choosable' => true]);
    $reverse = fn (int $blueprintId): CardKey => new CardKey($blueprintId, ConditionType::NM, Language::IT, true, false);
    PokecardexSyncState::create(['user_id' => $this->user->id, ...$reverse(999)->attributes(), 'quantity' => 2]);
    PokecardexSyncState::create(['user_id' => $this->user->id, ...applierKey('nm', 999)->attributes(), 'quantity' => 1]);
    PokecardexSyncState::create(['user_id' => $this->user->id, ...$reverse(287733)->attributes(), 'quantity' => 4]);

    $this->applier->apply($this->user, applierPlan([]));

    expect(PokecardexSyncState::query()->orderBy('blueprint_id')->get(['blueprint_id', 'reverse', 'quantity'])->toArray())->toBe([
        ['blueprint_id' => 999, 'reverse' => false, 'quantity' => 3],
        ['blueprint_id' => 287733, 'reverse' => true, 'quantity' => 4],
    ]);
});

it('stops at the first failed write and leaves that card base untouched', function () {
    $this->cardtrader->shouldReceive('write')->andThrow(new RuntimeException('HTTP 500'));
    PokecardexSyncState::create(['user_id' => $this->user->id, ...applierKey('nm', 1)->attributes(), 'quantity' => 1]);
    $first = new KeyState(applierKey('nm', 1), pokecardex: 2, cardtrader: 1, base: 1);
    $second = new KeyState(applierKey('nm', 2), pokecardex: 2, cardtrader: 1, base: 1);

    $result = $this->applier->apply($this->user, applierPlan([
        new KeyChange($first, PokecardexSyncDirection::ToCardTrader),
        new KeyChange($second, PokecardexSyncDirection::ToCardTrader),
    ]));

    expect($result->failed())->toBeTrue()
        ->and($result->failure)->toContain('HTTP 500')
        ->and(PokecardexSyncState::where('blueprint_id', 1)->value('quantity'))->toBe(1)
        ->and(PokecardexSyncState::where('blueprint_id', 2)->exists())->toBeFalse();
});
