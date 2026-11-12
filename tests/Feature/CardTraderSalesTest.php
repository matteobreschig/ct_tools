<?php

use App\Enums\ConditionType;
use App\Enums\Language;
use App\Models\User;
use App\Services\CTApiClient;
use App\Services\Pokecardex\CardKey;
use App\Services\Pokecardex\CardTraderSales;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

/*
 * Storico vendite CardTrader (`/orders` come venditore), con la forma delle
 * risposte vere osservate il 2026-11-12.
 */

function saleItem(int $blueprintId, int $quantity, array $properties = []): array
{
    return [
        'blueprint_id' => $blueprintId, 'category_id' => 73, 'quantity' => $quantity,
        'properties' => array_merge([
            'condition' => 'Near Mint', 'signed' => false, 'altered' => false, 'first_edition' => false,
            'pokemon_language' => 'it', 'pokemon_reverse' => false,
        ], $properties),
    ];
}

function saleOrder(int $id, string $paidAt, array $items, string $state = 'done', ?string $cancelledAt = null): array
{
    return ['id' => $id, 'code' => "C{$id}", 'state' => $state, 'paid_at' => $paidAt, 'cancelled_at' => $cancelledAt, 'order_items' => $items];
}

beforeEach(function () {
    Sleep::fake();
    $this->sales = new CardTraderSales(new CTApiClient(User::factory()->create(['ct_api_key' => 'test-key'])));
});

it('groups sold copies by card key, newest sale first', function () {
    Http::fake([
        'api.cardtrader.com/api/v2/orders*' => Http::sequence()->push([
            saleOrder(3, '2026-11-03T14:22:48.000Z', [saleItem(351495, 1)]),
            saleOrder(2, '2026-08-14T10:00:00.000Z', [saleItem(351495, 2), saleItem(351495, 1, ['pokemon_reverse' => true])]),
        ]),
    ]);

    $sold = $this->sales->soldByKey();

    $standard = (new CardKey(351495, ConditionType::NM, Language::IT, false, false))->id();
    $reverse = (new CardKey(351495, ConditionType::NM, Language::IT, true, false))->id();

    expect($sold[$standard])->toHaveCount(2)
        ->and(array_sum(array_column($sold[$standard], 'quantity')))->toBe(3)
        ->and($sold[$standard][0]['paid_at'])->toBe('2026-11-03')
        ->and($sold[$standard][0]['order'])->toBe('C3')
        ->and($sold[$reverse][0]['quantity'])->toBe(1);
});

it('skips cancelled orders and signed, altered or non-card items', function () {
    Http::fake([
        'api.cardtrader.com/api/v2/orders*' => Http::response([
            saleOrder(5, '2026-11-01T00:00:00.000Z', [saleItem(1, 1)], state: 'cancelled', cancelledAt: '2026-11-02T00:00:00.000Z'),
            saleOrder(4, '2026-10-01T00:00:00.000Z', [
                saleItem(2, 1, ['signed' => true]),
                saleItem(3, 1, ['altered' => true]),
                [...saleItem(4, 1), 'category_id' => 74],
            ]),
        ]),
    ]);

    expect($this->sales->soldByKey())->toBe([]);
});

it('reads every page of orders', function () {
    $fullPage = array_map(fn (int $id) => saleOrder($id, '2026-01-01T00:00:00.000Z', [saleItem(7, 1)]), range(1, 100));

    Http::fake([
        'api.cardtrader.com/api/v2/orders*' => Http::sequence()
            ->push($fullPage)
            ->push([saleOrder(101, '2025-01-01T00:00:00.000Z', [saleItem(7, 1)])]),
    ]);

    $sold = $this->sales->soldByKey();

    expect(array_sum(array_column($sold[(new CardKey(7, ConditionType::NM, Language::IT, false, false))->id()], 'quantity')))->toBe(101);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'order_as=seller') && str_contains($request->url(), 'page=2'));
});
