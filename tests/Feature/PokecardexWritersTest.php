<?php

use App\Enums\ConditionType;
use App\Enums\Language;
use App\Enums\PokecardexSyncDirection;
use App\Models\PokecardexAccount;
use App\Models\Product;
use App\Models\User;
use App\Services\CTApiClient;
use App\Services\Pokecardex\CardKey;
use App\Services\Pokecardex\CardTraderWriter;
use App\Services\Pokecardex\KeyChange;
use App\Services\Pokecardex\KeyState;
use App\Services\Pokecardex\PokecardexRow;
use App\Services\Pokecardex\PokecardexWriter;
use App\Services\PokecardexClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

/*
 * Scritture sui due lati (§ 4.3, § 4.4 del design).
 */

const WRITER_TEST_KEY = 'abcdefghijklmnopqrstuvwxyz012345';

function writerKey(string $condition = 'nm'): CardKey
{
    return new CardKey(287733, ConditionType::from($condition), Language::IT, false, false);
}

function writerListing(User $user, int $quantity, bool $sale, int $productId): Product
{
    return Product::factory()->for($user)->create([
        'product_id' => $productId, 'blueprint_id' => 287733, 'quantity' => $quantity, 'sale' => $sale, 'price' => $sale ? 2.5 : 99999,
    ]);
}

function writerRow(int $idCollection, int $quantity, int $idEtat = 6): PokecardexRow
{
    return new PokecardexRow($idCollection, 26600, 1, $idEtat, 4, $quantity);
}

function writerPokecardexClient(): PokecardexClient
{
    return new PokecardexClient(PokecardexAccount::factory()->create([
        'session_cookies' => [['Name' => 'CardexCookie_sid', 'Value' => 'sid', 'Domain' => 'www.pokecardex.com', 'Path' => '/', 'Expires' => now()->addYear()->timestamp, 'Secure' => false, 'Discard' => false, 'HttpOnly' => true, 'Max-Age' => null]],
        'decrypt_key' => WRITER_TEST_KEY,
    ]));
}

function writerEncrypted(array $payload): array
{
    $iv = random_bytes(16);

    return ['iv' => base64_encode($iv), 'data' => base64_encode(openssl_encrypt(json_encode($payload), 'aes-256-cbc', WRITER_TEST_KEY, OPENSSL_RAW_DATA, $iv))];
}

beforeEach(function () {
    Sleep::fake();
    $this->user = User::factory()->create(['ct_api_key' => 'test-key']);
    $this->cardtrader = new CardTraderWriter(new CTApiClient($this->user));
});

it('adds copies to the frozen CardTrader listing of the key', function () {
    Http::fake(['api.cardtrader.com/*' => Http::response(['resource' => ['id' => 5001]])]);
    $sale = writerListing($this->user, 1, true, 5000);
    $frozen = writerListing($this->user, 2, false, 5001);
    $state = new KeyState(writerKey(), pokecardex: 5, cardtrader: 3, base: 3, products: [$sale, $frozen]);

    $this->cardtrader->write(new KeyChange($state, PokecardexSyncDirection::ToCardTrader));

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/products/5001')
        && $request['quantity'] === 4
        && $request['tag'] === config('app.ct_vacation_tag'));
});

it('creates a frozen CardTrader listing when the key has none', function () {
    Http::fake(['api.cardtrader.com/*' => Http::response(['resource' => ['id' => 7777, 'quantity' => 2]])]);
    $state = new KeyState(writerKey('sp'), pokecardex: 2, cardtrader: 0, base: 0);

    $this->cardtrader->write(new KeyChange($state, PokecardexSyncDirection::ToCardTrader));

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/products')
        && $request['blueprint_id'] === 287733
        && $request['quantity'] === 2
        && $request['price'] === config('app.ct_disable_price')
        && $request['properties']['condition'] === 'Slightly Played'
        && $request['properties']['pokemon_language'] === 'it');
    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/products/7777')
        && $request['tag'] === config('app.ct_vacation_tag'));
});

it('removes CardTrader copies from listings on sale first, deleting empty ones', function () {
    Http::fake(['api.cardtrader.com/*' => Http::response([])]);
    $frozen = writerListing($this->user, 3, false, 6001);
    $sale = writerListing($this->user, 1, true, 6000);
    $state = new KeyState(writerKey(), pokecardex: 2, cardtrader: 4, base: 4, products: [$frozen, $sale]);

    $this->cardtrader->write(new KeyChange($state, PokecardexSyncDirection::ToCardTrader));

    $calls = collect(Http::recorded())->map(fn ($pair) => $pair[0]->method().' '.parse_url($pair[0]->url(), PHP_URL_PATH))->all();
    expect($calls)->toBe(['DELETE /api/v2/products/6000', 'PUT /api/v2/products/6001']);
    Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && $request['quantity'] === 2);
});

it('adds Pokecardex copies to the row with the same translation', function () {
    Http::fake([
        'www.pokecardex.com/api/collection/get_carte_details' => Http::response(writerEncrypted(['collection' => [
            ['id_collection' => 101, 'quantite' => 1, 'id_card' => 26600, 'id_version' => 1, 'id_langue' => 4, 'id_etat' => 6, 'prix_achat' => null, 'date_achat' => null, 'note' => null],
        ]])),
        'www.pokecardex.com/api/collection/update_carte_collection' => Http::response(null, 204),
        'www.pokecardex.com/' => Http::response('<meta name="csrf-token" content="t">'),
    ]);
    $state = new KeyState(writerKey(), pokecardex: 1, cardtrader: 3, base: 1, rows: [writerRow(101, 1)]);

    (new PokecardexWriter(writerPokecardexClient()))->write(new KeyChange($state, PokecardexSyncDirection::ToPokecardex));

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), 'update_carte_collection')
        && $request['id_collection'] == 101 && $request['quantite'] == 3);
});

it('adds a new Pokecardex row in the translated condition', function () {
    Http::fake([
        'www.pokecardex.com/api/collection/add_carte_collection' => Http::response(['id_ajout' => 900]),
        'www.pokecardex.com/' => Http::response('<meta name="csrf-token" content="t">'),
    ]);
    $state = new KeyState(writerKey('sp'), pokecardex: 0, cardtrader: 1, base: 0);

    (new PokecardexWriter(writerPokecardexClient()))->write(new KeyChange($state, PokecardexSyncDirection::ToPokecardex, ['id_card' => 26600, 'id_version' => 1]));

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), 'add_carte_collection')
        && $request['id_card'] == 26600 && $request['id_etat'] == 2 && $request['id_langue'] == 4 && $request['quantite'] == 1);
});

it('removes Pokecardex copies from the newest row first', function () {
    Http::fake([
        'www.pokecardex.com/api/collection/get_carte_details' => Http::response(writerEncrypted(['collection' => [
            ['id_collection' => 101, 'quantite' => 3, 'id_card' => 26600, 'id_version' => 1, 'id_langue' => 4, 'id_etat' => 1, 'prix_achat' => null, 'date_achat' => null, 'note' => null],
        ]])),
        'www.pokecardex.com/api/collection/*' => Http::response(null, 204),
        'www.pokecardex.com/' => Http::response('<meta name="csrf-token" content="t">'),
    ]);
    $state = new KeyState(writerKey(), pokecardex: 5, cardtrader: 2, base: 5, rows: [writerRow(101, 3, 1), writerRow(205, 2)]);

    (new PokecardexWriter(writerPokecardexClient()))->write(new KeyChange($state, PokecardexSyncDirection::ToPokecardex));

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), 'delete_carte_collection') && $request['id_collection'] == 205);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), 'update_carte_collection') && $request['id_collection'] == 101 && $request['quantite'] == 2);
});
