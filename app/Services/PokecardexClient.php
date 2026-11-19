<?php

namespace App\Services;

use App\Exceptions\PokecardexCredentialsRejectedException;
use App\Exceptions\PokecardexException;
use App\Models\PokecardexAccount;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use InvalidArgumentException;

/**
 * Client dell'API interna di Pokecardex (§ 2 del design della sincronizzazione).
 *
 * - Il login avviene solo quando serve e al massimo una volta per istanza: la
 *   sessione (cookie di accesso automatico, validi un anno) resta salvata
 *   sull'account e si riusa.
 * - Le letture arrivano cifrate in AES-256-CBC. La chiave sta nel bundle JS
 *   della home: si salva sull'account e si rilegge solo se smette di funzionare.
 * - Le scritture lavorano sempre su una singola riga (`id_collection`). Le
 *   cancellazioni di massa del sito (`delete_all`, `delete_normale_...`) non
 *   sono esposte di proposito.
 */
class PokecardexClient
{
    public const BASE_URL = 'https://www.pokecardex.com';

    /** Cookie che Pokecardex imposta solo dopo un login riuscito. */
    private const AUTH_COOKIE = 'CardexCookie_sid';

    /** Pausa tra due chiamate, per non pesare sul sito. */
    private const PAUSE_MILLISECONDS = 500;

    private const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

    private CookieJar $cookies;

    private ?string $csrfToken = null;

    private ?string $bundlePath = null;

    private bool $hasLoggedIn = false;

    private bool $hasSentRequest = false;

    public function __construct(private PokecardexAccount $account)
    {
        if ($account->credentials_rejected_at !== null) {
            throw new PokecardexCredentialsRejectedException('Pokecardex ha rifiutato le credenziali: salvale di nuovo nel profilo.');
        }

        $this->cookies = new CookieJar(false, $account->session_cookies ?? []);
    }

    /**
     * Tutta la collezione: `cartes` (le carte possedute) e `collection` (le righe).
     *
     * @return array{cartes: list<array<string, mixed>>, collection: list<array{id_collection: int, id_card: int, id_version: int, id_etat: int, id_langue: int, quantite: int}>}&array<string, mixed>
     */
    public function collection(): array
    {
        $data = $this->read('get_all_collection');

        if (! is_array($data['cartes'] ?? null) || ! is_array($data['collection'] ?? null)) {
            throw new PokecardexException('get_all_collection: risposta senza carte o righe.');
        }

        return $data;
    }

    /**
     * Dettaglio di una carta, con le righe complete di prezzo, data d'acquisto e nota.
     *
     * @return array<string, mixed>
     */
    public function cardDetails(int $idCard): array
    {
        $data = $this->read('get_carte_details', ['id_card' => $idCard]);

        if (! is_array($data['collection'] ?? null)) {
            throw new PokecardexException("get_carte_details: risposta senza righe per la carta {$idCard}.");
        }

        return $data;
    }

    /**
     * Anagrafiche: serie, condizioni, lingue, versioni.
     *
     * @return array<string, mixed>
     */
    public function series(): array
    {
        return $this->read('series');
    }

    /**
     * Tutte le carte di una serie, possedute o no.
     *
     * @return array<string, mixed>
     */
    public function seriesCards(int $idSerie, string $lang = 'en'): array
    {
        return $this->read('get_cartes', ['id' => $idSerie, 'lang' => $lang, 'masterSetMode' => 'false']);
    }

    /**
     * Aggiunge una riga e ne restituisce l'id (`id_collection`).
     */
    public function addRow(int $idCard, int $idVersion, int $idEtat, int $idLangue, int $quantity): int
    {
        $this->ensurePositive($quantity);

        $response = $this->call('add_carte_collection', [
            'id_card' => $idCard,
            'quantite' => $quantity,
            'id_etat' => $idEtat,
            'id_version' => $idVersion,
            'id_langue' => $idLangue,
        ]);

        $idCollection = $response->json('id_ajout');

        if (! is_int($idCollection)) {
            throw new PokecardexException('add_carte_collection: risposta senza id della riga.');
        }

        return $idCollection;
    }

    /**
     * Riscrive una riga. Pokecardex vuole tutti i campi, quindi prezzo, data
     * d'acquisto e nota si rileggono dal dettaglio della carta e si rimandano
     * uguali.
     */
    public function updateRow(int $idCard, int $idCollection, int $idVersion, int $idEtat, int $idLangue, int $quantity): void
    {
        $this->ensurePositive($quantity);

        $row = collect($this->cardDetails($idCard)['collection'])
            ->firstWhere('id_collection', $idCollection);

        if ($row === null) {
            throw new PokecardexException("update_carte_collection: la riga {$idCollection} della carta {$idCard} non esiste più.");
        }

        $this->call('update_carte_collection', [
            'id_card' => $idCard,
            'quantite' => $quantity,
            'id_etat' => $idEtat,
            'id_version' => $idVersion,
            'id_langue' => $idLangue,
            'prix_achat' => $row['prix_achat'] ?? '',
            'date_achat' => $row['date_achat'] ?? '',
            'note' => $row['note'] ?? '',
            'id_collection' => $idCollection,
        ]);
    }

    public function deleteRow(int $idCollection): void
    {
        $this->call('delete_carte_collection', ['id_collection' => $idCollection]);
    }

    /**
     * Chiamata in lettura: la risposta è cifrata e si decifra.
     *
     * @param  array<string, int|string>  $parameters
     * @return array<string, mixed>
     */
    private function read(string $action, array $parameters = []): array
    {
        $payload = $this->call($action, $parameters)->json();

        if (! is_array($payload) || ! is_string($payload['iv'] ?? null) || ! is_string($payload['data'] ?? null)) {
            throw new PokecardexException("{$action}: risposta non cifrata, probabilmente il sito è cambiato.");
        }

        $hadSavedKey = $this->account->decrypt_key !== null;
        $data = $this->decrypt($payload, $this->decryptKey());

        if ($data === null && $hadSavedKey) {
            $data = $this->decrypt($payload, $this->decryptKey(refresh: true));
        }

        if ($data === null) {
            throw new PokecardexException("{$action}: decifratura fallita anche con la chiave appena letta.");
        }

        return $data;
    }

    /**
     * Chiamata all'API con la sessione salvata. Se la sessione è rifiutata
     * rifà il login una volta sola e riprova.
     *
     * @param  array<string, int|string>  $parameters
     */
    private function call(string $action, array $parameters): Response
    {
        if (! $this->hasAuthCookie()) {
            $this->login();
        }

        $response = $this->post($action, $parameters);

        if ($this->isSessionRefused($response) && ! $this->hasLoggedIn) {
            $this->login();
            $response = $this->post($action, $parameters);
        }

        if ($this->isSessionRefused($response)) {
            throw new PokecardexException("{$action}: sessione rifiutata anche dopo un nuovo login (HTTP {$response->status()}).");
        }

        if (! $response->successful()) {
            throw new PokecardexException("{$action}: Pokecardex ha risposto HTTP {$response->status()}: ".mb_substr($response->body(), 0, 200));
        }

        $this->saveSession();

        return $response;
    }

    /**
     * @param  array<string, int|string>  $parameters
     */
    private function post(string $action, array $parameters): Response
    {
        return $this->send(fn (PendingRequest $request) => $request
            ->asForm()
            ->withHeaders([
                'X-CSRF-TOKEN' => $this->csrfToken(),
                'X-Requested-With' => 'XMLHttpRequest',
                'Accept' => 'application/json, text/plain, */*',
                'Origin' => self::BASE_URL,
                'Referer' => self::BASE_URL.'/',
                'Sec-Fetch-Site' => 'same-origin',
                'Sec-Fetch-Mode' => 'cors',
                'Sec-Fetch-Dest' => 'empty',
            ])
            ->post("/api/collection/{$action}", ['lang' => 'en', ...$parameters]));
    }

    private function login(): void
    {
        if ($this->hasLoggedIn) {
            throw new PokecardexException('Login già fatto in questo giro: niente nuovi tentativi.');
        }

        $this->hasLoggedIn = true;
        $this->csrfToken = null;

        $page = $this->send(fn (PendingRequest $request) => $request->get('/connexion'));
        $loginToken = $this->metaCsrfToken($page->body());

        if (! $page->successful() || $loginToken === null) {
            throw new PokecardexException("Pagina di login inattesa (HTTP {$page->status()}): forse Cloudflare blocca le richieste dal server.");
        }

        $this->send(fn (PendingRequest $request) => $request->asForm()->post('/connexion', [
            'csrf_test_name' => $loginToken,
            'redirect' => '/',
            'username' => $this->account->username,
            'password' => $this->account->password,
            'autologin' => '1',
        ]));

        if (! $this->hasAuthCookie()) {
            $this->account->forceFill([
                'session_cookies' => null,
                'credentials_rejected_at' => now(),
            ])->save();

            throw new PokecardexCredentialsRejectedException('Pokecardex ha rifiutato le credenziali: salvale di nuovo nel profilo.');
        }

        $this->saveSession();
    }

    /**
     * Token CSRF per l'API, letto dalla home. La stessa pagina indica il bundle
     * che contiene la chiave di decifratura.
     */
    private function csrfToken(): string
    {
        if ($this->csrfToken !== null) {
            return $this->csrfToken;
        }

        $home = $this->send(fn (PendingRequest $request) => $request->get('/'));
        $html = html_entity_decode($home->body());

        $this->csrfToken = $this->metaCsrfToken($html)
            ?? throw new PokecardexException("Token CSRF assente nella home (HTTP {$home->status()}).");

        if (preg_match('#/react/accueil/assets/index-[^"&\s]+\.js#', $html, $match)) {
            $this->bundlePath = $match[0];
        }

        return $this->csrfToken;
    }

    private function decryptKey(bool $refresh = false): string
    {
        if (! $refresh && $this->account->decrypt_key !== null) {
            return $this->account->decrypt_key;
        }

        $this->csrfToken();

        if ($this->bundlePath === null) {
            throw new PokecardexException('Bundle con la chiave di decifratura non trovato nella home.');
        }

        $bundle = $this->send(fn (PendingRequest $request) => $request->get($this->bundlePath))->body();

        if (! preg_match('/const \w+="([A-Za-z0-9]{32})";async function \w+\(\w\)\{const\{data/', $bundle, $match)) {
            throw new PokecardexException("Chiave di decifratura non trovata nel bundle {$this->bundlePath}.");
        }

        $this->account->forceFill(['decrypt_key' => $match[1]])->save();

        return $match[1];
    }

    /**
     * @param  array{iv: string, data: string}  $payload
     * @return array<string, mixed>|null
     */
    private function decrypt(array $payload, string $key): ?array
    {
        $plain = openssl_decrypt(
            (string) base64_decode($payload['data'], true),
            'aes-256-cbc',
            $key,
            OPENSSL_RAW_DATA,
            (string) base64_decode($payload['iv'], true),
        );

        $data = $plain === false ? null : json_decode($plain, true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param  callable(PendingRequest): Response  $callback
     */
    private function send(callable $callback): Response
    {
        if ($this->hasSentRequest) {
            Sleep::for(self::PAUSE_MILLISECONDS)->milliseconds();
        }

        $this->hasSentRequest = true;

        $request = Http::baseUrl(self::BASE_URL)
            ->withOptions(['cookies' => $this->cookies, 'allow_redirects' => false])
            ->withHeaders([
                'User-Agent' => self::USER_AGENT,
                'Accept-Language' => 'it-IT,it;q=0.9,en;q=0.8',
            ])
            ->timeout(60);

        try {
            return $callback($request);
        } catch (ConnectionException $exception) {
            throw new PokecardexException('Pokecardex non raggiungibile: '.$exception->getMessage(), previous: $exception);
        }
    }

    private function isSessionRefused(Response $response): bool
    {
        return in_array($response->status(), [301, 302, 303, 401, 403, 419], true);
    }

    private function hasAuthCookie(): bool
    {
        $cookie = $this->cookies->getCookieByName(self::AUTH_COOKIE);

        return $cookie instanceof SetCookie && ! $cookie->isExpired() && $cookie->getValue() !== '';
    }

    private function saveSession(): void
    {
        $this->account->forceFill(['session_cookies' => $this->cookies->toArray()])->save();
    }

    private function metaCsrfToken(string $html): ?string
    {
        return preg_match('/<meta name="csrf-token" content="([^"]+)"/', $html, $match) ? $match[1] : null;
    }

    private function ensurePositive(int $quantity): void
    {
        if ($quantity < 1) {
            throw new InvalidArgumentException('Quantità minore di 1: per togliere una riga si usa deleteRow.');
        }
    }
}
