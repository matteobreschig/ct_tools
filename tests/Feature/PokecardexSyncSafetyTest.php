<?php

use App\Enums\ConditionType;
use App\Enums\Language;
use App\Enums\PokecardexSyncDirection;
use App\Models\PokecardexSyncRun;
use App\Models\User;
use App\Services\Pokecardex\CardKey;
use App\Services\Pokecardex\KeyChange;
use App\Services\Pokecardex\KeyState;
use App\Services\Pokecardex\SyncPlan;
use App\Services\Pokecardex\SyncSafety;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Freni del § 7 del design.
 */

function safetyPlan(int $pokecardexCopies = 100, int $cardtraderCopies = 100, int $removed = 0, bool $firstRun = false): SyncPlan
{
    $changes = [];

    if ($removed > 0) {
        $state = new KeyState(new CardKey(1, ConditionType::NM, Language::IT, false, false), pokecardex: 0, cardtrader: $removed, base: $removed);
        $changes[] = new KeyChange($state, PokecardexSyncDirection::ToCardTrader);
    }

    return new SyncPlan(
        firstRun: $firstRun,
        changes: $changes,
        agreed: [],
        conflicts: [],
        reviews: [],
        pending: [],
        pokecardexCopies: $pokecardexCopies,
        cardtraderCopies: $cardtraderCopies,
    );
}

function previousRun(User $user, int $pokecardexCopies, int $cardtraderCopies, string $outcome = 'applied'): PokecardexSyncRun
{
    return PokecardexSyncRun::create([
        'user_id' => $user->id, 'started_at' => now()->subHour(), 'finished_at' => now()->subHour(),
        'outcome' => $outcome, 'pokecardex_copies' => $pokecardexCopies, 'cardtrader_copies' => $cardtraderCopies,
    ]);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->safety = new SyncSafety;
});

it('lets a normal run write', function () {
    previousRun($this->user, 100, 100);

    $verdict = $this->safety->check($this->user, safetyPlan(95, 98, removed: 5));

    expect($verdict->isBlocked())->toBeFalse()
        ->and($verdict->needsPreview())->toBeFalse();
});

it('blocks a run when one side lost too many copies since the last good run', function (int $pokecardex, int $cardtrader, string $side) {
    previousRun($this->user, 100, 100);

    $verdict = $this->safety->check($this->user, safetyPlan($pokecardex, $cardtrader));

    expect($verdict->isBlocked())->toBeTrue()
        ->and($verdict->blockedReason)->toContain($side);
})->with([
    'Pokecardex empty' => [0, 100, 'Pokecardex'],
    'Pokecardex below 90%' => [89, 100, 'Pokecardex'],
    'CardTrader below 90%' => [100, 80, 'CardTrader'],
]);

it('compares with the last applied run only', function () {
    previousRun($this->user, 100, 100);
    previousRun($this->user, 10, 10, outcome: 'failed');

    expect($this->safety->check($this->user, safetyPlan(10, 10))->isBlocked())->toBeTrue();
});

it('asks for a preview on the first run', function () {
    $verdict = $this->safety->check($this->user, safetyPlan(firstRun: true, removed: 50));

    expect($verdict->isBlocked())->toBeFalse()
        ->and($verdict->needsPreview())->toBeTrue()
        ->and($verdict->previewReason)->toContain('Primo');
});

it('asks for a preview when a run removes too many copies', function () {
    previousRun($this->user, 100, 100);

    $verdict = $this->safety->check($this->user, safetyPlan(removed: 21));

    expect($verdict->needsPreview())->toBeTrue()
        ->and($verdict->previewReason)->toContain('21');
});

it('reads the thresholds from the configuration', function () {
    config(['services.pokecardex.max_removed_copies' => 50, 'services.pokecardex.min_copies_ratio' => 0.5]);
    previousRun($this->user, 100, 100);

    $verdict = $this->safety->check($this->user, safetyPlan(60, 60, removed: 30));

    expect($verdict->isBlocked())->toBeFalse()
        ->and($verdict->needsPreview())->toBeFalse();
});
