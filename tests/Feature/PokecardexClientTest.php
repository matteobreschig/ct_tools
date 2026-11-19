<?php

use App\Exceptions\PokecardexCredentialsRejectedException;
use App\Exceptions\PokecardexException;
use App\Models\PokecardexAccount;
use App\Services\PokecardexClient;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

/*
 * Risposte registrate: stessa forma di quelle vere di Pokecardex (osservate il
 * 2026-11-12, § 2 del design), con valori inventati e una chiave di prova.
 */

const PCX_TEST_KEY = 'abcdefghijklmnopqrstuvwxyz012345';

function pcxEncrypted(array $payload, string $key = PCX_TEST_KEY): array
{
    $iv = random_bytes(16);

    return [
        'iv' => base64_encode($iv),
        'data' => base64_encode(openssl_encrypt(json_encode($payload), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv)),
    ];
}

function pcxPage(string $csrf = 'csrf-page-token'): string
{
    return '<html><head><meta name="csrf-token" content="'.$csrf.'">'
        .'<script type="module" src="/react/accueil/assets/index-TEST123.js"></script></head><body></body></html>';
}

function pcxBundle(string $key = PCX_TEST_KEY): string
{
    return 'var a=1;const Xy="'.$key.'";async function Qz(e){const{data:t}=await fetch(e);return t}';
}

function pcxLoginResponse(): PromiseInterface
{
    return Http::response('<html>ok</html>', 200, [
        'Set-Cookie' => [
            'ci_session=sess123; path=/; HttpOnly',
            'CardexCookie_u=u123; expires=Fri, 12-Nov-2027 10:00:00 GMT; path=/; HttpOnly',
            'CardexCookie_sid=sid123; expires=Fri, 12-Nov-2027 10:00:00 GMT; path=/; HttpOnly',
        ],
    ]);
}

function pcxCollection(): array
{
    return [
        'cartes' => [[
            'id_card' => 26642, 'id_serie' => 900, 'nom_court' => 'DLR', 'num_card' => '1',
            'name_card_en' => 'Heracross', 'id_cardmarket' => 812345,
            'versions' => [['id_card' => 26642, 'id_version' => 1, 'nom_version_us' => 'Normal', 'only_english' => false, 'langues' => [1, 2]]],
        ]],
        'collection' => [
            ['id_collection' => 101, 'id_card' => 26642, 'id_version' => 2, 'id_etat' => 6, 'id_langue' => 6, 'quantite' => 1],
        ],
        'prices' => [],
    ];
}

function pcxAccount(array $attributes = []): PokecardexAccount
{
    return PokecardexAccount::factory()->create(array_merge([
        'username' => 'ash',
        'password' => 'pikachu-secret',
    ], $attributes));
}

function pcxLoggedInAccount(): PokecardexAccount
{
    return pcxAccount([
        'session_cookies' => [
            ['Name' => 'CardexCookie_sid', 'Value' => 'sid-saved', 'Domain' => 'www.pokecardex.com', 'Path' => '/', 'Expires' => now()->addYear()->timestamp, 'Secure' => false, 'Discard' => false, 'HttpOnly' => true, 'Max-Age' => null],
        ],
        'decrypt_key' => PCX_TEST_KEY,
    ]);
}

function pcxIsAction(Request $request, string $action): bool
{
    return $request->url() === "https://www.pokecardex.com/api/collection/{$action}";
}

beforeEach(function () {
    Sleep::fake();
});

it('logs in, reads the collection and keeps session and key for the next time', function () {
    Http::fake([
        'www.pokecardex.com/connexion' => Http::sequence()
            ->push(pcxPage('csrf-login'))
            ->pushResponse(pcxLoginResponse()),
        'www.pokecardex.com/react/accueil/assets/index-TEST123.js' => Http::response(pcxBundle()),
        'www.pokecardex.com/api/collection/get_all_collection' => Http::response(pcxEncrypted(pcxCollection())),
        'www.pokecardex.com/' => Http::response(pcxPage()),
    ]);

    $account = pcxAccount();
    $collection = (new PokecardexClient($account))->collection();

    expect($collection['cartes'][0]['id_card'])->toBe(26642)
        ->and($collection['collection'][0]['quantite'])->toBe(1);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://www.pokecardex.com/connexion'
        && $request['username'] === 'ash'
        && $request['password'] === 'pikachu-secret'
        && $request['csrf_test_name'] === 'csrf-login'
        && $request['autologin'] === '1');

    Http::assertSent(fn (Request $request) => pcxIsAction($request, 'get_all_collection')
        && $request->hasHeader('X-CSRF-TOKEN', 'csrf-page-token')
        && $request['lang'] === 'en');

    $account->refresh();
    expect($account->decrypt_key)->toBe(PCX_TEST_KEY)
        ->and(collect($account->session_cookies)->pluck('Name'))->toContain('CardexCookie_sid');
});

it('reuses the saved session and key without logging in again', function () {
    Http::fake([
        'www.pokecardex.com/api/collection/get_all_collection' => Http::response(pcxEncrypted(pcxCollection())),
        'www.pokecardex.com/' => Http::response(pcxPage()),
    ]);

    (new PokecardexClient(pcxLoggedInAccount()))->collection();

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/connexion'));
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '.js'));
    Http::assertSent(fn (Request $request) => pcxIsAction($request, 'get_all_collection')
        && str_contains($request->header('Cookie')[0] ?? '', 'CardexCookie_sid=sid-saved'));
});

it('logs in again once when the saved session is refused', function () {
    Http::fake([
        'www.pokecardex.com/connexion' => Http::sequence()
            ->push(pcxPage('csrf-login'))
            ->pushResponse(pcxLoginResponse()),
        'www.pokecardex.com/api/collection/get_all_collection' => Http::sequence()
            ->pushStatus(403)
            ->push(pcxEncrypted(pcxCollection())),
        'www.pokecardex.com/' => Http::response(pcxPage()),
    ]);

    $collection = (new PokecardexClient(pcxLoggedInAccount()))->collection();

    expect($collection['collection'])->toHaveCount(1);
    Http::assertSentCount(6);
});

it('stops when the session is refused even after a new login', function () {
    Http::fake([
        'www.pokecardex.com/connexion' => Http::sequence()
            ->push(pcxPage('csrf-login'))
            ->pushResponse(pcxLoginResponse()),
        'www.pokecardex.com/api/collection/get_all_collection' => Http::response('', 403),
        'www.pokecardex.com/' => Http::response(pcxPage()),
    ]);

    expect(fn () => (new PokecardexClient(pcxLoggedInAccount()))->collection())
        ->toThrow(PokecardexException::class);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/connexion'));
    expect(Http::recorded(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/connexion')))->toHaveCount(1);
});

it('flags rejected credentials and never tries them again', function () {
    Http::fake([
        'www.pokecardex.com/connexion' => Http::sequence()
            ->push(pcxPage('csrf-login'))
            ->push('<html>Identifiants incorrects</html>'),
        'www.pokecardex.com/' => Http::response(pcxPage()),
    ]);

    $account = pcxAccount();

    expect(fn () => (new PokecardexClient($account))->collection())
        ->toThrow(PokecardexCredentialsRejectedException::class);

    expect($account->refresh()->credentials_rejected_at)->not->toBeNull();

    Http::fake();
    expect(fn () => new PokecardexClient($account))
        ->toThrow(PokecardexCredentialsRejectedException::class);
    Http::assertNothingSent();
});

it('stops when the login page is not the expected one', function () {
    Http::fake([
        'www.pokecardex.com/connexion' => Http::response('<html>Just a moment...</html>', 403),
    ]);

    expect(fn () => (new PokecardexClient(pcxAccount()))->collection())
        ->toThrow(PokecardexException::class, 'login');
});

it('reads the key from the bundle again when the saved one no longer decrypts', function () {
    Http::fake([
        'www.pokecardex.com/react/accueil/assets/index-TEST123.js' => Http::response(pcxBundle('zyxwvutsrqponmlkjihgfedcba543210')),
        'www.pokecardex.com/api/collection/get_all_collection' => Http::response(pcxEncrypted(pcxCollection(), 'zyxwvutsrqponmlkjihgfedcba543210')),
        'www.pokecardex.com/' => Http::response(pcxPage()),
    ]);

    $account = pcxLoggedInAccount();
    (new PokecardexClient($account))->collection();

    expect($account->refresh()->decrypt_key)->toBe('zyxwvutsrqponmlkjihgfedcba543210');
});

it('stops when a read is not encrypted or not the expected shape', function (mixed $body) {
    Http::fake([
        'www.pokecardex.com/api/collection/get_all_collection' => Http::response($body),
        'www.pokecardex.com/' => Http::response(pcxPage()),
    ]);

    expect(fn () => (new PokecardexClient(pcxLoggedInAccount()))->collection())
        ->toThrow(PokecardexException::class);
})->with([
    'plain json' => [['error' => 'nope']],
    'html page' => ['<html>maintenance</html>'],
    'empty collection payload' => [pcxEncrypted(['cartes' => []])],
]);

it('adds a row and returns its id', function () {
    Http::fake([
        'www.pokecardex.com/api/collection/add_carte_collection' => Http::response(['id_ajout' => 553350405, 'remaing_recherches' => 0]),
        'www.pokecardex.com/' => Http::response(pcxPage()),
    ]);

    $id = (new PokecardexClient(pcxLoggedInAccount()))
        ->addRow(idCard: 26642, idVersion: 1, idEtat: 6, idLangue: 1, quantity: 2);

    expect($id)->toBe(553350405);
    Http::assertSent(fn (Request $request) => pcxIsAction($request, 'add_carte_collection')
        && $request->isForm()
        && $request['id_card'] == 26642
        && $request['id_version'] == 1
        && $request['id_etat'] == 6
        && $request['id_langue'] == 1
        && $request['quantite'] == 2);
});

it('updates a row keeping purchase price, date and note', function () {
    Http::fake([
        'www.pokecardex.com/api/collection/get_carte_details' => Http::response(pcxEncrypted([
            'id_card' => 26642,
            'collection' => [
                ['id_collection' => 101, 'quantite' => 1, 'id_card' => 26642, 'id_version' => 2, 'id_langue' => 6, 'id_etat' => 6, 'date_ajout' => '2026-10-29', 'date_achat' => '2026-10-01', 'prix_achat' => '1.50', 'note' => 'dal booster'],
            ],
        ])),
        'www.pokecardex.com/api/collection/update_carte_collection' => Http::response(null, 204),
        'www.pokecardex.com/' => Http::response(pcxPage()),
    ]);

    (new PokecardexClient(pcxLoggedInAccount()))
        ->updateRow(idCard: 26642, idCollection: 101, idVersion: 2, idEtat: 2, idLangue: 6, quantity: 3);

    Http::assertSent(fn (Request $request) => pcxIsAction($request, 'update_carte_collection')
        && $request['id_collection'] == 101
        && $request['id_card'] == 26642
        && $request['quantite'] == 3
        && $request['id_etat'] == 2
        && $request['prix_achat'] === '1.50'
        && $request['date_achat'] === '2026-10-01'
        && $request['note'] === 'dal booster');
});

it('does not update a row that no longer exists', function () {
    Http::fake([
        'www.pokecardex.com/api/collection/get_carte_details' => Http::response(pcxEncrypted(['id_card' => 26642, 'collection' => []])),
        'www.pokecardex.com/' => Http::response(pcxPage()),
    ]);

    expect(fn () => (new PokecardexClient(pcxLoggedInAccount()))
        ->updateRow(idCard: 26642, idCollection: 101, idVersion: 2, idEtat: 2, idLangue: 6, quantity: 3))
        ->toThrow(PokecardexException::class);

    Http::assertNotSent(fn (Request $request) => pcxIsAction($request, 'update_carte_collection'));
});

it('deletes a single row by its id', function () {
    Http::fake([
        'www.pokecardex.com/api/collection/delete_carte_collection' => Http::response(null, 204),
        'www.pokecardex.com/' => Http::response(pcxPage()),
    ]);

    (new PokecardexClient(pcxLoggedInAccount()))->deleteRow(101);

    Http::assertSent(fn (Request $request) => pcxIsAction($request, 'delete_carte_collection')
        && $request['id_collection'] == 101);
});

it('refuses quantities below one', function (string $method, array $arguments) {
    Http::fake();

    expect(fn () => (new PokecardexClient(pcxLoggedInAccount()))->{$method}(...$arguments))
        ->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
})->with([
    'add' => ['addRow', ['idCard' => 1, 'idVersion' => 1, 'idEtat' => 1, 'idLangue' => 1, 'quantity' => 0]],
    'update' => ['updateRow', ['idCard' => 1, 'idCollection' => 1, 'idVersion' => 1, 'idEtat' => 1, 'idLangue' => 1, 'quantity' => 0]],
]);

it('stops when a write fails', function () {
    Http::fake([
        'www.pokecardex.com/api/collection/add_carte_collection' => Http::response(['message' => 'Langue inexistante'], 402),
        'www.pokecardex.com/' => Http::response(pcxPage()),
    ]);

    expect(fn () => (new PokecardexClient(pcxLoggedInAccount()))
        ->addRow(idCard: 26642, idVersion: 1, idEtat: 6, idLangue: 9, quantity: 1))
        ->toThrow(PokecardexException::class, '402');
});

it('waits between calls to Pokecardex', function () {
    Http::fake([
        'www.pokecardex.com/api/collection/delete_carte_collection' => Http::response(null, 204),
        'www.pokecardex.com/' => Http::response(pcxPage()),
    ]);

    $client = new PokecardexClient(pcxLoggedInAccount());
    $client->deleteRow(101);
    $client->deleteRow(102);

    Sleep::assertSleptTimes(2);
});
