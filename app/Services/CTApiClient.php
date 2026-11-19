<?php

namespace App\Services;

use App\Exceptions\ApiRateLimitException;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;

class CTApiClient
{
    protected User $user;

    protected string $baseUrl;

    protected int $maxAttempts = 100;

    protected int $decaySeconds = 60;

    protected array $defaultQueryParams = [
        'game_id' => '5',
    ];

    /**
     * @param  int  $maxRateLimitWaitSeconds  secondi che una chiamata può aspettare quando un limite
     *                                        è pieno, prima di lanciare ApiRateLimitException. Con 0
     *                                        (predefinito) lancia subito, come si aspettano i job che
     *                                        si rimettono in coda da soli.
     */
    public function __construct(Authenticatable $user, protected int $maxRateLimitWaitSeconds = 0)
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

    public function post(string $endpoint, array $payload = [], array $query = []): Response
    {
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
        $this->waitForRateLimit($globalKey, 8, 'Global CardTrader API rate limit exceeded');
        RateLimiter::hit($globalKey, 1);

        // Per-user cap: prevent a single user from monopolising the quota
        $userKey = 'cardtrader-api:user:'.$this->user->id;
        $this->waitForRateLimit($userKey, $this->maxAttempts, 'Per-user API rate limit exceeded');
        RateLimiter::hit($userKey, $this->decaySeconds);
    }

    /**
     * Aspetta che il limite si liberi, al massimo $maxRateLimitWaitSeconds secondi in tutto.
     *
     * @throws ApiRateLimitException se il limite resta pieno oltre l'attesa consentita
     */
    protected function waitForRateLimit(string $key, int $maxAttempts, string $message): void
    {
        $waited = 0;

        while (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $seconds = max(1, RateLimiter::availableIn($key));

            if ($waited + $seconds > $this->maxRateLimitWaitSeconds) {
                throw new ApiRateLimitException($message);
            }

            Sleep::for($seconds)->seconds();
            $waited += $seconds;
        }
    }
}
