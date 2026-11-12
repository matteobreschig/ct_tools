<?php

use App\Enums\PokecardexMatchStatus;
use App\Models\CtExpansionBlueprint;
use App\Models\Expansion;
use App\Models\PokecardexAccount;
use App\Models\PokecardexCard;
use App\Models\PokecardexCardMatch;
use App\Models\PokecardexSeries;
use App\Models\PokecardexSeriesRule;
use App\Models\User;
use App\Services\CTApiClient;
use App\Services\Pokecardex\BlueprintCatalog;
use App\Services\Pokecardex\CardMatcher;
use App\Services\Pokecardex\PokecardexCatalog;
use App\Services\PokecardexClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
 * Abbinamenti (§ 5.3, § 5.4 del design). I cataloghi sono già caricati,
 * quindi nessuna chiamata esce: lo garantisce preventStrayRequests.
 */

beforeEach(function () {
    Http::preventStrayRequests();

    $user = User::factory()->create(['ct_api_key' => 'test-key']);
    $account = PokecardexAccount::factory()->for($user)->create();

    $this->matcher = new CardMatcher(
        new PokecardexCatalog(new PokecardexClient($account)),
        new BlueprintCatalog(new CTApiClient($user)),
    );

    $this->expansion = Expansion::factory()->create(['code' => 'twm', 'blueprints_loaded_at' => now()]);
    $this->series = PokecardexSeries::factory()->create([
        'code' => 'TWM', 'expansion_id' => $this->expansion->id, 'expansion_source' => 'auto', 'cards_loaded_at' => now(),
    ]);
});

function twmCard(array $attributes = []): PokecardexCard
{
    return PokecardexCard::factory()->create(array_merge([
        'id_serie' => test()->series->id_serie,
        'num_card' => '053/167',
        'id_cardmarket' => 769227,
    ], $attributes));
}

function twmBlueprint(array $attributes = []): CtExpansionBlueprint
{
    return CtExpansionBlueprint::factory()->create(array_merge([
        'expansion_ct_id' => test()->expansion->ct_id,
        'collector_number' => '053',
        'cardmarket_product_id' => 769227,
        'cardmarket_product_ids' => [769227],
    ], $attributes));
}

it('matches standard, reverse and first edition by Cardmarket id', function (int $idVersion, bool $reverse, bool $firstEdition) {
    $card = twmCard(['versions' => [
        ['id_version' => 1, 'nom_version_us' => 'Standard', 'langues' => [1, 2]],
        ['id_version' => 2, 'nom_version_us' => 'Reverse', 'langues' => [1, 2]],
        ['id_version' => 6, 'nom_version_us' => '1st Edition', 'langues' => [1, 2]],
    ]]);
    $blueprint = twmBlueprint();

    $match = $this->matcher->match($card, $idVersion);

    expect($match->status)->toBe(PokecardexMatchStatus::Matched)
        ->and($match->blueprintId)->toBe($blueprint->blueprint_id)
        ->and($match->reverse)->toBe($reverse)
        ->and($match->firstEdition)->toBe($firstEdition);

    expect(PokecardexCardMatch::where(['id_card' => $card->id_card, 'id_version' => $idVersion])->value('source'))->toBe('cardmarket');
})->with([
    'standard' => [1, false, false],
    'reverse' => [2, true, false],
    'first edition' => [6, false, true],
]);

it('sends to review a Cardmarket id that lands on a different collector number', function () {
    $card = twmCard();
    twmBlueprint(['collector_number' => '072']);

    $match = $this->matcher->match($card, 1);

    expect($match->status)->toBe(PokecardexMatchStatus::Review)
        ->and($match->reason)->toContain('numero');
    expect(PokecardexCardMatch::count())->toBe(0);
});

it('sends to review a Cardmarket id shared by more blueprints', function () {
    $card = twmCard();
    twmBlueprint();
    twmBlueprint(['blueprint_id' => 999001]);

    expect($this->matcher->match($card, 1)->status)->toBe(PokecardexMatchStatus::Review);
});

it('keeps waiting a card whose blueprint does not exist yet', function () {
    $card = twmCard();

    $match = $this->matcher->match($card, 1);

    expect($match->status)->toBe(PokecardexMatchStatus::Pending);
});

it('sends to review a card whose series has no CardTrader expansion', function () {
    $series = PokecardexSeries::factory()->create(['expansion_id' => null, 'cards_loaded_at' => now()]);
    $card = PokecardexCard::factory()->create(['id_serie' => $series->id_serie]);

    $match = $this->matcher->match($card, 1);

    expect($match->status)->toBe(PokecardexMatchStatus::Review)
        ->and($match->reason)->toContain('espansione');
});

it('sends to review a special version without a rule', function () {
    $card = twmCard(['versions' => [['id_version' => 197, 'nom_version_us' => 'Reverse (Masterball)', 'langues' => [2]]]]);
    twmBlueprint();

    $match = $this->matcher->match($card, 197);

    expect($match->status)->toBe(PokecardexMatchStatus::Review)
        ->and($match->reason)->toContain('regola');
});

it('matches a special version through the series rule and the collector number', function () {
    $masterBall = Expansion::factory()->create(['code' => 'm-twm', 'blueprints_loaded_at' => now()]);
    PokecardexSeriesRule::create(['id_serie' => $this->series->id_serie, 'id_version' => 197, 'expansion_id' => $masterBall->id]);
    $card = twmCard(['versions' => [['id_version' => 197, 'nom_version_us' => 'Reverse (Masterball)', 'langues' => [2]]]]);
    twmBlueprint();
    $special = CtExpansionBlueprint::factory()->create(['expansion_ct_id' => $masterBall->ct_id, 'collector_number' => '53', 'cardmarket_product_id' => 888888]);

    $match = $this->matcher->match($card, 197);

    expect($match->status)->toBe(PokecardexMatchStatus::Matched)
        ->and($match->blueprintId)->toBe($special->blueprint_id)
        ->and($match->reverse)->toBeTrue()
        ->and($match->source)->toBe('rule');
});

it('prefers a manual match over everything else', function () {
    $card = twmCard();
    twmBlueprint();
    PokecardexCardMatch::create(['id_card' => $card->id_card, 'id_version' => 1, 'blueprint_id' => 4242, 'source' => 'manual']);

    $match = $this->matcher->match($card, 1);

    expect($match->status)->toBe(PokecardexMatchStatus::Matched)
        ->and($match->blueprintId)->toBe(4242)
        ->and($match->source)->toBe('manual');
});

it('finds the Pokecardex card and version of a CardTrader blueprint', function (bool $reverse, int $idVersion) {
    $card = twmCard();
    $blueprint = twmBlueprint();

    $target = $this->matcher->matchBlueprint($blueprint->blueprint_id, $this->expansion, reverse: $reverse, firstEdition: false);

    expect($target->status)->toBe(PokecardexMatchStatus::Matched)
        ->and($target->idCard)->toBe($card->id_card)
        ->and($target->idVersion)->toBe($idVersion);
})->with([
    'standard' => [false, 1],
    'reverse' => [true, 2],
]);

it('finds the Pokecardex special version of a blueprint in a rule expansion', function () {
    $masterBall = Expansion::factory()->create(['code' => 'm-twm', 'blueprints_loaded_at' => now()]);
    PokecardexSeriesRule::create(['id_serie' => $this->series->id_serie, 'id_version' => 197, 'expansion_id' => $masterBall->id]);
    $card = twmCard(['versions' => [
        ['id_version' => 1, 'nom_version_us' => 'Standard', 'langues' => [2]],
        ['id_version' => 197, 'nom_version_us' => 'Reverse (Masterball)', 'langues' => [2]],
    ]]);
    $special = CtExpansionBlueprint::factory()->create(['expansion_ct_id' => $masterBall->ct_id, 'collector_number' => '053', 'cardmarket_product_id' => 888888]);

    $target = $this->matcher->matchBlueprint($special->blueprint_id, $masterBall, reverse: true, firstEdition: false);

    expect($target->status)->toBe(PokecardexMatchStatus::Matched)
        ->and($target->idCard)->toBe($card->id_card)
        ->and($target->idVersion)->toBe(197);
});

it('sends to review a blueprint whose version does not exist on Pokecardex', function () {
    twmCard(['versions' => [['id_version' => 1, 'nom_version_us' => 'Standard', 'langues' => [2]]]]);
    $blueprint = twmBlueprint();

    $target = $this->matcher->matchBlueprint($blueprint->blueprint_id, $this->expansion, reverse: true, firstEdition: false);

    expect($target->status)->toBe(PokecardexMatchStatus::Review)
        ->and($target->reason)->toContain('versione');
});

it('sends to review a blueprint with no Pokecardex card', function () {
    $blueprint = twmBlueprint(['cardmarket_product_id' => 111]);

    $target = $this->matcher->matchBlueprint($blueprint->blueprint_id, $this->expansion, reverse: false, firstEdition: false);

    expect($target->status)->toBe(PokecardexMatchStatus::Review);
});

it('keeps waiting while CardTrader prepares the expansion catalog', function () {
    $this->expansion->forceFill(['blueprints_loaded_at' => null])->save();
    Http::fake([
        'api.cardtrader.com/api/v2/blueprints/export*' => Http::response(['extra' => ['message' => 'Data is not ready for blueprints_exp_1']], 404),
    ]);

    $match = $this->matcher->match(twmCard(), 1);

    expect($match->status)->toBe(PokecardexMatchStatus::Pending)
        ->and($match->reason)->toContain('preparando');
});

it('uses the Cardmarket id inside a mixed rule expansion where numbers repeat', function () {
    $prizePack = Expansion::factory()->create(['code' => 'playprizep', 'blueprints_loaded_at' => now()]);
    $series = PokecardexSeries::factory()->create(['code' => 'PRZP', 'cards_loaded_at' => now()]);
    PokecardexSeriesRule::create(['id_serie' => $series->id_serie, 'id_version' => 144, 'expansion_id' => $prizePack->id]);
    $card = PokecardexCard::factory()->create([
        'id_serie' => $series->id_serie, 'num_card' => '119/132', 'id_cardmarket' => 864423,
        'versions' => [['id_version' => 144, 'nom_version_us' => 'Non Holo (PPS)', 'langues' => [2]]],
    ]);
    CtExpansionBlueprint::factory()->create(['expansion_ct_id' => $prizePack->ct_id, 'collector_number' => '119', 'cardmarket_product_id' => 700001]);
    $right = CtExpansionBlueprint::factory()->create(['expansion_ct_id' => $prizePack->ct_id, 'collector_number' => '119', 'cardmarket_product_id' => 864423]);

    $match = $this->matcher->match($card, 144);

    expect($match->status)->toBe(PokecardexMatchStatus::Matched)
        ->and($match->blueprintId)->toBe($right->blueprint_id)
        ->and($match->reverse)->toBeFalse();

    PokecardexCardMatch::query()->delete();

    $target = $this->matcher->matchBlueprint($right->blueprint_id, $prizePack, reverse: false, firstEdition: false);

    expect($target->status)->toBe(PokecardexMatchStatus::Matched)
        ->and($target->idCard)->toBe($card->id_card)
        ->and($target->idVersion)->toBe(144);
});

it('lets a series rule take over a safe version kept apart on CardTrader', function () {
    $energyReverse = Expansion::factory()->create(['code' => 'e-twm', 'blueprints_loaded_at' => now()]);
    PokecardexSeriesRule::create(['id_serie' => $this->series->id_serie, 'id_version' => 2, 'expansion_id' => $energyReverse->id]);
    $card = twmCard();
    twmBlueprint();
    $apart = CtExpansionBlueprint::factory()->create(['expansion_ct_id' => $energyReverse->ct_id, 'collector_number' => '053', 'cardmarket_product_id' => 870135]);

    $match = $this->matcher->match($card, 2);

    expect($match->status)->toBe(PokecardexMatchStatus::Matched)
        ->and($match->blueprintId)->toBe($apart->blueprint_id)
        ->and($match->reverse)->toBeTrue()
        ->and($match->source)->toBe('rule');
});

it('tells Prize Pack Non Holo and Cosmos apart by the CardTrader variant', function () {
    $prizePack = Expansion::factory()->create(['code' => 'playprizep', 'blueprints_loaded_at' => now()]);
    $series = PokecardexSeries::factory()->create(['code' => 'PRZP', 'cards_loaded_at' => now()]);
    foreach ([144, 147] as $version) {
        PokecardexSeriesRule::create(['id_serie' => $series->id_serie, 'id_version' => $version, 'expansion_id' => $prizePack->id]);
    }
    $card = PokecardexCard::factory()->create([
        'id_serie' => $series->id_serie, 'num_card' => '150/159', 'name_en' => 'Levincia', 'id_cardmarket' => 842718,
        'versions' => [
            ['id_version' => 144, 'nom_version_us' => 'Non Holo (PPS)', 'langues' => [2, 4]],
            ['id_version' => 147, 'nom_version_us' => 'Cosmos Holo (PPS)', 'langues' => [2, 4]],
        ],
    ]);
    PokecardexCard::factory()->create(['id_serie' => $series->id_serie, 'num_card' => '150/217', 'name_en' => 'Dratini', 'versions' => [['id_version' => 144, 'nom_version_us' => 'Non Holo (PPS)', 'langues' => [2]]]]);
    $nonHolo = CtExpansionBlueprint::factory()->create(['expansion_ct_id' => $prizePack->ct_id, 'name' => 'Levincia', 'collector_number' => '150nh', 'version' => 'Non-Holo | 150/159', 'cardmarket_product_id' => 842718]);
    $cosmos = CtExpansionBlueprint::factory()->create(['expansion_ct_id' => $prizePack->ct_id, 'name' => 'Levincia', 'collector_number' => '150h', 'version' => 'Cosmos Holo | 150/159', 'cardmarket_product_id' => 842719]);

    expect($this->matcher->match($card, 144)->blueprintId)->toBe($nonHolo->blueprint_id)
        ->and($this->matcher->match($card, 147)->blueprintId)->toBe($cosmos->blueprint_id);

    PokecardexCardMatch::query()->delete();

    $fromCosmos = $this->matcher->matchBlueprint($cosmos->blueprint_id, $prizePack, reverse: false, firstEdition: false);
    $fromNonHolo = $this->matcher->matchBlueprint($nonHolo->blueprint_id, $prizePack, reverse: false, firstEdition: false);

    expect($fromCosmos->status)->toBe(PokecardexMatchStatus::Matched)
        ->and([$fromCosmos->idCard, $fromCosmos->idVersion])->toBe([$card->id_card, 147])
        ->and([$fromNonHolo->idCard, $fromNonHolo->idVersion])->toBe([$card->id_card, 144]);
});

it('keeps a single blueprint even if its variant label differs from the Pokecardex name', function () {
    $pokeBall = Expansion::factory()->create(['code' => 'p-twm', 'blueprints_loaded_at' => now()]);
    PokecardexSeriesRule::create(['id_serie' => $this->series->id_serie, 'id_version' => 94, 'expansion_id' => $pokeBall->id]);
    $card = twmCard(['versions' => [['id_version' => 94, 'nom_version_us' => 'Reverse (Pokeball)', 'langues' => [2]]]]);
    $special = CtExpansionBlueprint::factory()->create(['expansion_ct_id' => $pokeBall->ct_id, 'collector_number' => '053', 'version' => 'Poké Ball Reverse Holo | 053/167', 'cardmarket_product_id' => 770001]);

    expect($this->matcher->match($card, 94)->blueprintId)->toBe($special->blueprint_id);

    PokecardexCardMatch::query()->delete();

    $target = $this->matcher->matchBlueprint($special->blueprint_id, $pokeBall, reverse: true, firstEdition: false);
    expect([$target->idCard, $target->idVersion])->toBe([$card->id_card, 94]);
});
