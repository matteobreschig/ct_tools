<?php

use App\Exceptions\ApiRateLimitException;
use App\Models\User;
use App\Services\CTApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\ExpectationFailedException;

uses(RefreshDatabase::class);

/*
 * Limiti dell'app sulle chiamate CardTrader: 8 al secondo per tutti, 100 al
 * minuto per utente. Il giro Pokecardex aspetta, gli altri job falliscono
 * subito e si rimettono in coda da soli.
 */

beforeEach(function () {
    Sleep::fake(syncWithCarbon: true);
    Http::fake(['api.cardtrader.com/*' => Http::response([])]);

    $this->user = User::factory()->create(['ct_api_key' => 'test-key']);

    foreach (range(1, 100) as $attempt) {
        RateLimiter::hit('cardtrader-api:user:'.$this->user->id, 60);
    }
});

it('fails at once when the per-user limit is full and no wait is allowed', function () {
    expect(fn () => (new CTApiClient($this->user))->get('/info'))
        ->toThrow(ApiRateLimitException::class, 'Per-user API rate limit exceeded');

    Http::assertNothingSent();
    Sleep::assertNeverSlept();
});

it('waits for the per-user limit to free up when a wait is allowed', function () {
    (new CTApiClient($this->user, maxRateLimitWaitSeconds: 120))->get('/info');

    Http::assertSentCount(1);
    expect(fn () => Sleep::assertNeverSlept())->toThrow(ExpectationFailedException::class);
});

it('gives up when the limit stays full longer than the allowed wait', function () {
    expect(fn () => (new CTApiClient($this->user, maxRateLimitWaitSeconds: 10))->get('/info'))
        ->toThrow(ApiRateLimitException::class);

    Http::assertNothingSent();
});
