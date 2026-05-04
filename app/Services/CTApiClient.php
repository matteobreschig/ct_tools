<?php

namespace App\Services;

use App\Exceptions\ApiRateLimitException;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Http\Client\Response;

class CTApiClient
{
    protected User $user;
    protected string $baseUrl;

    protected int $maxAttempts = 100;
    protected int $decaySeconds = 60;

    protected array $defaultQueryParams = [
        'game_id' => '5',
    ];

    public function __construct(Authenticatable $user)
    {
        if (! $user->hasCtApiKey()) {
            throw new \RuntimeException('Utente senza API token configurato');
        }

        $this->user = $user;
        $this->baseUrl = 'https://api.cardtrader.com/api/v2';
    }

    public function get(string $endpoint, array $query = []): Response
    {
        return $this->request('get', $endpoint, [], $query);
    }

    public function post(string $endpoint, array $payload = [], array $query = []): Response {
        return $this->request('post', $endpoint, $payload, $query);
    }

    public function put(string $endpoint, array $payload = [], array $query = []): Response
    {
        return $this->request('put', $endpoint, $payload, $query);
    }

    public function delete(string $endpoint, array $payload = [], array $query = []): Response
    {
        return $this->request('delete', $endpoint, $payload, $query);
    }

    protected function buildQueryParams(array $query = []): array
    {
        return array_merge($this->defaultQueryParams, $query);
    }

    protected function request(
        string $method,
        string $endpoint,
        array $data = [],
        array $query = []
    ): Response {
        $this->checkRateLimit();

        $queryParams = $this->buildQueryParams($query);

        $response = Http::retry(3, 1000, function ($exception) {
            return $exception->response?->status() === 429;
        })
            ->baseUrl($this->baseUrl)
            ->withToken($this->user->ct_api_key)
            ->acceptJson()
            ->withOptions([
                'query' => $queryParams,
            ])
            ->{$method}($endpoint, $data);

        if ($response->status() === 401) {
            // Token non valido o revocato
            throw new \RuntimeException('API token non valido o revocato');
        }

        $response->throw();
        return $response;
    }

    protected function checkRateLimit(): void
    {
        // Global cap: stay under the CardTrader API's 10 req/sec limit
        $globalKey = 'cardtrader-api:global';
        if (RateLimiter::tooManyAttempts($globalKey, 8)) {
            throw new ApiRateLimitException('Global CardTrader API rate limit exceeded');
        }
        RateLimiter::hit($globalKey, 1);

        // Per-user cap: prevent a single user from monopolising the quota
        $userKey = 'cardtrader-api:user:' . $this->user->id;
        if (RateLimiter::tooManyAttempts($userKey, $this->maxAttempts)) {
            throw new ApiRateLimitException('Per-user API rate limit exceeded');
        }
        RateLimiter::hit($userKey, $this->decaySeconds);
    }
}
