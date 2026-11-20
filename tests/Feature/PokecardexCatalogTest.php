<?php

use App\Models\CtExpansionBlueprint;
use App\Models\Expansion;
use App\Models\PokecardexAccount;
use App\Models\PokecardexCard;
use App\Models\PokecardexSeries;
use App\Models\User;
use App\Services\CTApiClient;
use App\Services\Pokecardex\BlueprintCatalog;
use App\Services\Pokecardex\PokecardexCatalog;
use App\Services\PokecardexClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

const CATALOG_TEST_KEY = 'abcdefghijklmnopqrstuvwxyz012345';

function catalogEncrypted(array $payload): array
{
    $iv = random_bytes(16);

    return [
        'iv' => base64_encode($iv),
        'data' => base64_encode(openssl_encrypt(json_encode($payload), 'aes-256-cbc', CATALOG_TEST_KEY, OPENSSL_RAW_DATA, $iv)),
    ];
}

function catalogClient(): PokecardexClient
{
    return new PokecardexClient(PokecardexAccount::factory()->create([
        'session_cookies' => [['Name' => 'CardexCookie_sid', 'Value' => 'sid', 'Domain' => 'www.pokecardex.com', 'Path' => '/', 'Expires' => now()->addYear()->timestamp, 'Secure' => false, 'Discard' => false, 'HttpOnly' => true, 'Max-Age' => null]],
        'decrypt_key' => CATALOG_TEST_KEY,
    ]));
}

function catalogCard(int $idCard, string $number, int $cardmarketId): array
{
    return [
        'id_card' => $idCard, 'id_serie' => 222, 'num_card' => $number, 'name_card_en' => "Card {$idCard}",
        'id_cardmarket' => $cardmarketId, 'nom_court' => 'TWM',
        'versions' => [['id_card' => $idCard, 'id_version' => 1, 'nom_version_us' => 'Standard', 'only_english' => false, 'langues' => [['statut' => 'confirmed', 'id_langue' => 2], ['statut' => 'presumed', 'id_langue' => 4]]]],
    ];
}

beforeEach(function () {
    Sleep::fake();
});

it('stores the series and links them to CardTrader expansions by code or name', function () {
    $twm = Expansion::factory()->create(['code' => 'twm', 'name_en' => 'Twilight Masquerade']);
    $dlr = Expansion::factory()->create(['code' => 'xyz', 'name_en' => 'Delta Reign']);
    Expansion::factory()->create(['code' => 'ama', 'name_en' => 'Ambiguous']);
    Expansion::factory()->create(['code' => 'amb', 'name_en' => 'Ambiguous']);

    Http::fake([
        'www.pokecardex.com/api/collection/series' => Http::response(catalogEncrypted(['blocs' => [
            ['id_bloc' => '16', 'series' => [
                ['id_serie' => '222', 'nom_us' => 'Twilight Masquerade', 'nom_court' => 'TWM', 'id_cardmarket' => '5691'],
                ['id_serie' => '251', 'nom_us' => 'Delta Reign', 'nom_court' => 'DLR', 'id_cardmarket' => '6704'],
                ['id_serie' => '300', 'nom_us' => 'Ambiguous', 'nom_court' => 'AMX', 'id_cardmarket' => ''],
            ]],
        ]])),
        'www.pokecardex.com/' => Http::response('<meta name="csrf-token" content="t">'),
    ]);

    $count = (new PokecardexCatalog(catalogClient()))->refreshSeries();

    expect($count)->toBe(3)
        ->and(PokecardexSeries::find(222)->expansion_id)->toBe($twm->id)
        ->and(PokecardexSeries::find(251)->expansion_id)->toBe($dlr->id)
        ->and(PokecardexSeries::find(251)->expansion_source)->toBe('auto')
        ->and(PokecardexSeries::find(300)->expansion_id)->toBeNull()
        ->and(PokecardexSeries::find(300)->id_cardmarket)->toBeNull();
});

it('never overwrites a series linked by hand', function () {
    $manual = Expansion::factory()->create(['code' => 'man']);
    Expansion::factory()->create(['code' => 'twm']);
    PokecardexSeries::factory()->create(['id_serie' => 222, 'code' => 'TWM', 'expansion_id' => $manual->id, 'expansion_source' => 'manual']);

    Http::fake([
        'www.pokecardex.com/api/collection/series' => Http::response(catalogEncrypted(['blocs' => [
            ['series' => [['id_serie' => '222', 'nom_us' => 'Twilight Masquerade', 'nom_court' => 'TWM', 'id_cardmarket' => '5691']]],
        ]])),
        'www.pokecardex.com/' => Http::response('<meta name="csrf-token" content="t">'),
    ]);

    (new PokecardexCatalog(catalogClient()))->refreshSeries();

    expect(PokecardexSeries::find(222)->expansion_id)->toBe($manual->id);
});

it('loads the cards of a series only when they are missing or older than a day', function () {
    $series = PokecardexSeries::factory()->create(['id_serie' => 222]);

    Http::fake([
        'www.pokecardex.com/api/collection/get_cartes' => Http::response(catalogEncrypted(['cartes' => [
            catalogCard(26600, '053/167', 769227),
            catalogCard(26601, '054/167', 769228),
        ]])),
        'www.pokecardex.com/' => Http::response('<meta name="csrf-token" content="t">'),
    ]);

    $catalog = new PokecardexCatalog(catalogClient());
    $catalog->loadCards($series);
    $catalog->loadCards($series->refresh());

    expect(PokecardexCard::count())->toBe(2)
        ->and(PokecardexCard::find(26600)->collectorNumber())->toBe('53')
        ->and(PokecardexCard::find(26600)->languagesOf(1))->toBe([2, 4]);
    Http::assertSentCount(2);

    $this->travel(25)->hours();
    $catalog->loadCards($series->refresh());

    expect(Http::recorded(fn (Request $request) => str_contains($request->url(), 'get_cartes')))->toHaveCount(2);
});

it('remembers the cards that come with the collection', function () {
    (new PokecardexCatalog(catalogClient()))->remember([catalogCard(26600, '053/167', 769227)]);

    expect(PokecardexCard::find(26600)->id_cardmarket)->toBe(769227);
});

it('loads the single-card blueprints of an expansion once a day', function () {
    $user = User::factory()->create(['ct_api_key' => 'test-key']);
    $expansion = Expansion::factory()->create(['ct_id' => 3674]);

    Http::fake([
        'api.cardtrader.com/api/v2/blueprints/export*' => Http::response([
            ['id' => 287733, 'name' => 'Froslass', 'category_id' => 73, 'expansion_id' => 3674, 'fixed_properties' => ['collector_number' => '053'], 'card_market_ids' => [769227], 'editable_properties' => [['name' => 'pokemon_language'], ['name' => 'pokemon_reverse']]],
            ['id' => 287999, 'name' => 'Booster', 'category_id' => 74, 'expansion_id' => 3674, 'fixed_properties' => [], 'card_market_ids' => [111]],
            ['id' => 288000, 'name' => 'Promo', 'category_id' => 73, 'expansion_id' => 3674, 'fixed_properties' => ['collector_number' => '200'], 'card_market_ids' => [], 'editable_properties' => [['name' => 'pokemon_language']]],
        ]),
    ]);

    $catalog = new BlueprintCatalog(new CTApiClient($user));
    $catalog->load($expansion);
    $catalog->load($expansion->refresh());

    expect(CtExpansionBlueprint::count())->toBe(2)
        ->and(CtExpansionBlueprint::find(287733)->cardmarket_product_id)->toBe(769227)
        ->and(CtExpansionBlueprint::find(288000)->cardmarket_product_id)->toBeNull()
        ->and(CtExpansionBlueprint::find(287733)->reverse_choosable)->toBeTrue()
        ->and(CtExpansionBlueprint::find(288000)->reverse_choosable)->toBeFalse()
        ->and($expansion->refresh()->blueprints_loaded_at)->not->toBeNull();
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'expansion_id=3674'));
});

it('reports when CardTrader has not prepared the export yet', function () {
    $user = User::factory()->create(['ct_api_key' => 'test-key']);
    $expansion = Expansion::factory()->create(['ct_id' => 3312]);

    Http::fake([
        'api.cardtrader.com/api/v2/blueprints/export*' => Http::response(['error_code' => 'not_found', 'extra' => ['message' => 'Data is not ready for blueprints_exp_3312']], 404),
    ]);

    $loaded = (new BlueprintCatalog(new CTApiClient($user)))->load($expansion);

    expect($loaded)->toBeFalse()
        ->and($expansion->refresh()->blueprints_loaded_at)->toBeNull();
});
