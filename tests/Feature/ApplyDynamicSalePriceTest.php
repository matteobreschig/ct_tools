<?php

use App\Jobs\ApplyDynamicSale;
use App\Models\Product;
use App\Models\User;
use App\Services\CTApiClient;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
 * Le copie in vendita della stessa versione seguono il trend Cardmarket,
 * così non restano due prezzi per la stessa carta.
 */

function trendJob(User $user): ApplyDynamicSale
{
    return new class($user) extends ApplyDynamicSale
    {
        /** @return array{products: list<array<string, mixed>>} */
        public function run(EloquentCollection $products, ?int $groupKeep): array
        {
            $ctData = ['products' => []];
            $this->applyToProducts($products, $groupKeep, collect(), new CTApiClient($this->user), $ctData);

            return $ctData;
        }
    };
}

function trendProduct(User $user, float $price, ?float $trend, array $overrides = []): Product
{
    return Product::factory()->for($user)->create(array_merge([
        'blueprint_id' => 131, 'quantity' => 1, 'price' => $price, 'cardmarket_price_trend' => $trend,
        'sale' => true, 'condition' => 'nm', 'language' => 'it', 'reverse' => false,
    ], $overrides));
}

beforeEach(function () {
    Http::preventStrayRequests();
    $this->createdListing = ['id' => 777, 'quantity' => 1];
    Http::fake(['api.cardtrader.com/*' => fn () => Http::response(['resource' => $this->createdListing])]);
    $this->user = User::factory()->create(['ct_api_key' => 'test-key']);
    $this->job = trendJob($this->user);
});

it('reprices every copy on sale of a version to the Cardmarket trend', function () {
    $old = trendProduct($this->user, 0.13, 3.63);
    $new = trendProduct($this->user, 3.63, 3.63);

    $ctData = $this->job->run(new EloquentCollection([$old, $new]), 0);

    expect($old->refresh()->price)->toBe(3.63)
        ->and($new->refresh()->price)->toBe(3.63)
        ->and(collect($ctData['products'])->pluck('price')->all())->toBe([3.63, 3.63]);
});

it('leaves the price of kept copies alone', function () {
    $kept = trendProduct($this->user, 0.13, 3.63);
    $sold = trendProduct($this->user, 0.13, 3.63);

    $this->job->run(new EloquentCollection([$kept, $sold]), 1);

    expect($kept->refresh()->price)->toBe(0.13)
        ->and($sold->refresh()->price)->toBe(3.63);
});

it('keeps its own price when the trend is unknown', function () {
    $product = trendProduct($this->user, 0.13, null);

    $this->job->run(new EloquentCollection([$product]), 0);

    expect($product->refresh()->price)->toBe(0.13);
});

it('does not reprice locked rows', function () {
    $locked = trendProduct($this->user, 0.13, 3.63, ['sale_locked' => true]);

    $this->job->run(new EloquentCollection([$locked]), 0);

    expect($locked->refresh()->price)->toBe(0.13);
});

it('publishes the split excess at the trend price', function () {
    $row = trendProduct($this->user, 0.13, 3.63, ['quantity' => 3, 'product_id' => 9001]);

    $this->job->run(new EloquentCollection([$row]), 1);

    $createdPrices = collect(Http::recorded())
        ->map(fn (array $pair): Request => $pair[0])
        ->filter(fn (Request $request): bool => $request->method() === 'POST' && isset($request['blueprint_id']))
        ->map(fn (Request $request): float => (float) $request['price'])
        ->values()
        ->all();

    expect($createdPrices)->toBe([3.63]);
});
