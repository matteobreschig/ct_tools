<?php

use App\Jobs\ApplyDynamicSale;
use App\Models\Product;
use App\Models\User;
use App\Models\VersionKeepSetting;
use App\Services\CTApiClient;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
 * Copie da tenere per versione: si sommano al minimo del gruppo e valgono
 * anche quando il gruppo non ha nessun minimo.
 */

function versionKeepJob(User $user): ApplyDynamicSale
{
    return new class($user) extends ApplyDynamicSale
    {
        /** @return array{products: list<array<string, mixed>>} */
        public function run(EloquentCollection $products, ?int $groupKeep, array $keeps): array
        {
            $ctData = ['products' => []];
            $this->applyToProducts(
                $products,
                $groupKeep,
                collect($keeps),
                new CTApiClient($this->user),
                $ctData,
            );

            return $ctData;
        }
    };
}

function versionProduct(User $user, int $blueprintId, int $quantity, float $price, array $overrides = []): Product
{
    return Product::factory()->for($user)->create(array_merge([
        'blueprint_id' => $blueprintId, 'quantity' => $quantity, 'price' => $price,
        'sale' => true, 'condition' => 'nm', 'language' => 'it', 'reverse' => false,
    ], $overrides));
}

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake(['api.cardtrader.com/*' => Http::response(['resource' => ['id' => 777, 'quantity' => 1]])]);
    $this->user = User::factory()->create(['ct_api_key' => 'test-key']);
    $this->job = versionKeepJob($this->user);
});

it('keeps the version copies on top of the group minimum', function () {
    $versionA = versionProduct($this->user, 100, 1, 5.0);
    $versionBCheap = versionProduct($this->user, 200, 1, 1.0);
    $versionBDear = versionProduct($this->user, 200, 1, 2.0);

    $this->job->run(
        new EloquentCollection([$versionBCheap, $versionA, $versionBDear]),
        1,
        [VersionKeepSetting::keyFor($versionA) => 1],
    );

    expect($versionA->refresh()->sale)->toBeFalse()
        ->and($versionBCheap->refresh()->sale)->toBeFalse()
        ->and($versionBDear->refresh()->sale)->toBeTrue();
});

it('applies a version keep when the group has no minimum', function () {
    $first = versionProduct($this->user, 100, 1, 1.0);
    $second = versionProduct($this->user, 100, 1, 2.0);
    $other = versionProduct($this->user, 200, 1, 3.0);

    $ctData = $this->job->run(
        new EloquentCollection([$first, $second, $other]),
        null,
        [VersionKeepSetting::keyFor($first) => 1],
    );

    expect($first->refresh()->sale)->toBeFalse()
        ->and($second->refresh()->sale)->toBeTrue()
        ->and($other->refresh()->sale)->toBeTrue()
        ->and(collect($ctData['products'])->pluck('id')->all())->toBe([$first->product_id]);
});

it('keeps all copies when the keep exceeds what is owned', function () {
    $first = versionProduct($this->user, 100, 1, 1.0);
    $second = versionProduct($this->user, 100, 1, 2.0);

    $this->job->run(
        new EloquentCollection([$first, $second]),
        0,
        [VersionKeepSetting::keyFor($first) => 3],
    );

    expect($first->refresh()->sale)->toBeFalse()
        ->and($second->refresh()->sale)->toBeFalse();
});

it('counts the keep over all rows of the same version', function () {
    $first = versionProduct($this->user, 100, 1, 1.0);
    $second = versionProduct($this->user, 100, 1, 2.0);

    $this->job->run(
        new EloquentCollection([$first, $second]),
        0,
        [VersionKeepSetting::keyFor($first) => 1],
    );

    expect($first->refresh()->sale)->toBeFalse()
        ->and($second->refresh()->sale)->toBeTrue();
});

it('splits the boundary row', function () {
    $row = versionProduct($this->user, 100, 3, 1.5, ['product_id' => 9001]);

    $this->job->run(
        new EloquentCollection([$row]),
        null,
        [VersionKeepSetting::keyFor($row) => 1],
    );

    $methods = collect(Http::recorded())
        ->map(fn (array $pair): Request => $pair[0])
        ->map(fn (Request $request): string => $request->method().' '.($request['quantity'] ?? ''))
        ->all();

    expect($row->refresh()->quantity)->toBe(1)
        ->and($row->sale)->toBeFalse()
        ->and($methods)->toContain('PUT 1')
        ->and($methods)->toContain('POST 2');
});

it('ignores locked rows', function () {
    $locked = versionProduct($this->user, 100, 1, 1.0, ['sale_locked' => true, 'sale' => true]);
    $free = versionProduct($this->user, 100, 1, 2.0);

    $this->job->run(
        new EloquentCollection([$locked, $free]),
        null,
        [VersionKeepSetting::keyFor($locked) => 1],
    );

    expect($locked->refresh()->sale)->toBeTrue()
        ->and($free->refresh()->sale)->toBeFalse();
});
