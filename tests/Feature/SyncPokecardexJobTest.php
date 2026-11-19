<?php

use App\Jobs\ApplyDynamicSale;
use App\Jobs\SyncPokecardex;
use App\Models\CtExpansionBlueprint;
use App\Models\Expansion;
use App\Models\PokecardexAccount;
use App\Models\PokecardexCard;
use App\Models\PokecardexDecision;
use App\Models\PokecardexSeries;
use App\Models\PokecardexSyncRun;
use App\Models\PokecardexSyncState;
use App\Models\Product;
use App\Models\User;
use App\Services\Pokecardex\CardTraderInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

/*
 * Il giro completo, con Pokecardex e CardTrader finti. Froslass 53 di TWM:
 * 3 copie Excellent su Pokecardex, 1 inserzione ferma su CardTrader.
 */

const JOB_TEST_KEY = 'abcdefghijklmnopqrstuvwxyz012345';

function jobEncrypted(array $payload): array
{
    $iv = random_bytes(16);

    return ['iv' => base64_encode($iv), 'data' => base64_encode(openssl_encrypt(json_encode($payload), 'aes-256-cbc', JOB_TEST_KEY, OPENSSL_RAW_DATA, $iv))];
}

/**
 * @param  int  ...$pokecardexCopies  copie Pokecardex lette giro dopo giro
 */
function fakeBothSites(int ...$pokecardexCopies): void
{
    $collections = Http::sequence();

    foreach ($pokecardexCopies ?: [3] as $copies) {
        $collections->push(jobEncrypted([
            'cartes' => [],
            'collection' => [['id_collection' => 101, 'id_card' => 26600, 'id_version' => 1, 'id_etat' => 6, 'id_langue' => 4, 'quantite' => $copies]],
        ]));
    }

    Http::fake([
        'www.pokecardex.com/api/collection/get_all_collection' => $collections,
        'www.pokecardex.com/api/collection/get_carte_details' => Http::response(jobEncrypted(['collection' => [
            ['id_collection' => 101, 'quantite' => 3, 'id_card' => 26600, 'id_version' => 1, 'id_langue' => 4, 'id_etat' => 6, 'prix_achat' => null, 'date_achat' => null, 'note' => null],
        ]])),
        'www.pokecardex.com/api/collection/*' => Http::response(null, 204),
        'www.pokecardex.com/' => Http::response('<meta name="csrf-token" content="t">'),
        'api.cardtrader.com/api/v2/orders*' => Http::response([]),
        'api.cardtrader.com/api/v2/*' => Http::response([]),
    ]);
}

function cardtraderWrites(): array
{
    return Http::recorded(fn (Request $request) => str_contains($request->url(), 'api.cardtrader.com')
        && in_array($request->method(), ['POST', 'PUT', 'DELETE'], true))->all();
}

beforeEach(function () {
    Sleep::fake();
    Queue::fake([ApplyDynamicSale::class]);

    $this->user = User::factory()->create(['ct_api_key' => 'test-key']);
    PokecardexAccount::factory()->for($this->user)->create([
        'session_cookies' => [['Name' => 'CardexCookie_sid', 'Value' => 'sid', 'Domain' => 'www.pokecardex.com', 'Path' => '/', 'Expires' => now()->addYear()->timestamp, 'Secure' => false, 'Discard' => false, 'HttpOnly' => true, 'Max-Age' => null]],
        'decrypt_key' => JOB_TEST_KEY,
    ]);

    $expansion = Expansion::factory()->create(['code' => 'twm', 'blueprints_loaded_at' => now()]);
    $series = PokecardexSeries::factory()->create(['code' => 'TWM', 'expansion_id' => $expansion->id, 'cards_loaded_at' => now()]);
    PokecardexCard::factory()->create(['id_card' => 26600, 'id_serie' => $series->id_serie, 'num_card' => '053/167', 'id_cardmarket' => 769227]);
    CtExpansionBlueprint::factory()->create(['blueprint_id' => 287733, 'expansion_ct_id' => $expansion->ct_id, 'collector_number' => '053', 'cardmarket_product_id' => 769227]);
    Product::factory()->for($this->user)->create([
        'product_id' => 5001, 'blueprint_id' => 287733, 'expansion_id' => $expansion->id, 'quantity' => 1, 'sale' => false,
    ]);

    $this->inventory = Mockery::mock(CardTraderInventory::class);
    $this->inventory->shouldReceive('refresh')->byDefault();
    app()->instance(CardTraderInventory::class, $this->inventory);
});

it('stops at the preview on the first run without writing', function () {
    fakeBothSites();

    SyncPokecardex::dispatchSync($this->user);

    $run = PokecardexSyncRun::sole();
    expect($run->outcome)->toBe('preview')
        ->and($run->message)->toContain('Primo')
        ->and($run->fingerprint)->not->toBeNull()
        ->and($run->plan['changes'][0]['after'])->toBe(3)
        ->and(cardtraderWrites())->toBeEmpty()
        ->and(PokecardexSyncState::count())->toBe(0)
        ->and($this->user->notifications()->count())->toBe(1);
    $this->inventory->shouldHaveReceived('refresh')->once();
});

it('applies an approved preview and stores the base', function () {
    fakeBothSites(3, 3);
    SyncPokecardex::dispatchSync($this->user);
    $preview = PokecardexSyncRun::sole();

    SyncPokecardex::dispatchSync($this->user, $preview->id);

    $run = PokecardexSyncRun::latest('id')->first();
    expect($run->outcome)->toBe('applied')
        ->and($run->cardtrader_copies)->toBe(3)
        ->and(PokecardexSyncState::sole()->quantity)->toBe(3);
    Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/products/5001') && $request['quantity'] === 3);
    Queue::assertPushed(ApplyDynamicSale::class);
});

it('does not apply an approval when the plan has changed since', function () {
    fakeBothSites(3, 4);
    SyncPokecardex::dispatchSync($this->user);
    $preview = PokecardexSyncRun::sole();

    SyncPokecardex::dispatchSync($this->user, $preview->id);

    expect(PokecardexSyncRun::latest('id')->first()->outcome)->toBe('preview')
        ->and(PokecardexSyncRun::latest('id')->first()->message)->toContain('cambiato')
        ->and(cardtraderWrites())->toBeEmpty();
});

it('stops before writing when a side lost too many copies', function () {
    fakeBothSites(1);
    PokecardexSyncState::create(['user_id' => $this->user->id, 'blueprint_id' => 287733, 'condition' => 'nm', 'language' => 'it', 'reverse' => false, 'first_edition' => false, 'quantity' => 1]);
    PokecardexSyncRun::create(['user_id' => $this->user->id, 'started_at' => now()->subDay(), 'finished_at' => now()->subDay(), 'outcome' => 'applied', 'pokecardex_copies' => 100, 'cardtrader_copies' => 1]);

    SyncPokecardex::dispatchSync($this->user);

    expect(PokecardexSyncRun::latest('id')->first()->outcome)->toBe('blocked')
        ->and(cardtraderWrites())->toBeEmpty();
});

it('fails without Pokecardex credentials', function () {
    $this->user->pokecardexAccount->delete();

    SyncPokecardex::dispatchSync($this->user->fresh());

    expect(PokecardexSyncRun::sole()->outcome)->toBe('failed')
        ->and(PokecardexSyncRun::sole()->message)->toContain('Credenziali');
});

it('uses the side chosen by the user and forgets it once written', function () {
    fakeBothSites(3, 3);
    PokecardexDecision::create(['user_id' => $this->user->id, 'kind' => 'side', 'subject' => '287733|it|0|0', 'value' => 'cardtrader']);
    SyncPokecardex::dispatchSync($this->user);
    $preview = PokecardexSyncRun::sole();

    expect($preview->plan['changes'][0]['direction'])->toBe('to_pokecardex')
        ->and($preview->plan['changes'][0]['after'])->toBe(1);

    SyncPokecardex::dispatchSync($this->user, $preview->id);

    expect(PokecardexSyncRun::latest('id')->first()->outcome)->toBe('applied')
        ->and(PokecardexDecision::count())->toBe(0);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), 'update_carte_collection') && $request['quantite'] == 1);
});
