<?php

use App\Jobs\ApplyDynamicSale;
use App\Models\Product;
use App\Models\User;
use App\Services\CTApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
 * Divisione di un'inserzione di confine: le copie tolte da quella ferma non
 * devono sparire se la nuova inserzione in vendita non nasce.
 */

function splittingJob(User $user): ApplyDynamicSale
{
    return new class($user) extends ApplyDynamicSale
    {
        public function split(CTApiClient $client, Product $product, int $excess, float $price): void
        {
            $this->splitListing($client, $product, $excess, $price);
        }
    };
}

beforeEach(function () {
    Http::preventStrayRequests();
    $this->user = User::factory()->create(['ct_api_key' => 'test-key']);
    $this->client = new CTApiClient($this->user);
    $this->product = Product::factory()->for($this->user)->create([
        'product_id' => 9001, 'blueprint_id' => 391057, 'quantity' => 2, 'sale' => false,
        'condition' => 'nm', 'language' => 'it', 'reverse' => false,
    ]);
});

it('puts the copies back on the frozen listing when the new one cannot be created', function () {
    Http::fake([
        'api.cardtrader.com/api/v2/products/9001*' => Http::response(['resource' => ['id' => 9001]]),
        'api.cardtrader.com/api/v2/products?*' => Http::response(['error' => 'boom'], 500),
    ]);

    expect(fn () => splittingJob($this->user)->split($this->client, $this->product, 1, 1.5))
        ->toThrow(RequestException::class);

    $quantities = collect(Http::recorded())
        ->map(fn (array $pair): Request => $pair[0])
        ->filter(fn (Request $request): bool => $request->method() === 'PUT')
        ->map(fn (Request $request): int => $request['quantity'])
        ->values()
        ->all();

    expect($quantities)->toBe([1, 2])
        ->and($this->product->refresh()->quantity)->toBe(2);
});

it('fails loudly when the copies cannot be put back either', function () {
    Http::fakeSequence('api.cardtrader.com/api/v2/products/9001*')
        ->push(['resource' => ['id' => 9001]])
        ->push(['error' => 'down'], 500);
    Http::fake(['api.cardtrader.com/api/v2/products?*' => Http::response(['error' => 'boom'], 500)]);

    expect(fn () => splittingJob($this->user)->split($this->client, $this->product, 1, 1.5))
        ->toThrow(RuntimeException::class, 'Inserzione 9001 ridotta da 2 copie e non ripristinata');
});
