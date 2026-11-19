<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\PokecardexClient;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Prova usa e getta (§ 10.1 del design Pokecardex): login da server, lettura
 * della collezione e decifratura. Non stampa mai password né valori dei cookie.
 * Da cancellare dopo la prova, non va committata.
 */
class PokecardexProbe extends Command
{
    protected $signature = 'pokecardex:probe {email : Email dell\'utente con le credenziali Pokecardex nel profilo}';

    protected $description = 'Prova di login e lettura della collezione Pokecardex da server';

    private const BASE = 'https://www.pokecardex.com';

    public function handle(): int
    {
        $account = User::where('email', $this->argument('email'))->first()?->pokecardexAccount;

        if ($account === null) {
            $this->error('Utente inesistente o senza credenziali Pokecardex nel profilo.');

            return self::FAILURE;
        }

        $username = $account->username;
        $password = $account->password;

        $jar = new CookieJar;
        $http = fn (): PendingRequest => Http::withOptions(['cookies' => $jar])
            ->withHeaders([
                'User-Agent' => PokecardexClient::USER_AGENT,
                'Accept-Language' => 'it-IT,it;q=0.9,en;q=0.8',
            ])
            ->timeout(30);

        // 1. Pagina di login: Cloudflare lascia passare?
        $page = $http()->get(self::BASE.'/connexion');
        $this->line("GET /connexion → {$page->status()} server=".$page->header('server').' cf-mitigated='.($page->header('cf-mitigated') ?: '-'));
        $csrf = $this->metaCsrf($page->body());

        if ($csrf === null) {
            $this->error('Token CSRF non trovato: probabile pagina di verifica Cloudflare.');
            $this->line(substr(strip_tags($page->body()), 0, 300));

            return self::FAILURE;
        }

        // 2. Login.
        $login = $http()->asForm()->post(self::BASE.'/connexion', [
            'csrf_test_name' => $csrf,
            'redirect' => '/',
            'username' => $username,
            'password' => $password,
            'autologin' => '1',
        ]);
        $this->line("POST /connexion → {$login->status()}");

        foreach ($jar->toArray() as $cookie) {
            $expires = $cookie['Expires'] ? date('Y-m-d H:i', $cookie['Expires']) : 'sessione';
            $this->line("  cookie {$cookie['Name']} (scade: {$expires}, HttpOnly: ".($cookie['HttpOnly'] ? 'sì' : 'no').')');
        }

        // 3. Chiave di decifratura dal bundle della home, token CSRF aggiornato.
        $home = $http()->get(self::BASE.'/');
        $csrf = $this->metaCsrf($home->body()) ?? $csrf;
        $this->line('Home: '.(str_contains($home->body(), 'Bienvenue') ? 'collegata' : 'NON collegata'));

        preg_match('#/react/accueil/assets/index-[^"&]+\.js#', html_entity_decode($home->body()), $bundle);
        $key = null;
        if ($bundle) {
            $js = $http()->get(self::BASE.$bundle[0])->body();
            if (preg_match('/const \w+="([A-Za-z0-9]{32})";async function \w+\(\w\)\{const\{data/', $js, $match)) {
                $key = $match[1];
            }
        }
        $this->line('Bundle: '.($bundle[0] ?? 'non trovato').' chiave: '.($key ? 'trovata ('.strlen($key).' caratteri)' : 'NON trovata'));

        if ($key === null) {
            return self::FAILURE;
        }

        // 4. Collezione intera.
        $started = microtime(true);
        $response = $http()->asForm()
            ->withHeaders(['X-CSRF-TOKEN' => $csrf, 'X-Requested-With' => 'XMLHttpRequest'])
            ->post(self::BASE.'/api/collection/get_all_collection', ['lang' => 'en']);
        $this->line("POST get_all_collection → {$response->status()} in ".round(microtime(true) - $started, 1).'s, '.strlen($response->body()).' byte');

        $payload = $response->json();
        if (! isset($payload['iv'], $payload['data'])) {
            $this->error('Risposta non cifrata: '.substr($response->body(), 0, 300));

            return self::FAILURE;
        }

        $plain = openssl_decrypt(base64_decode($payload['data']), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, base64_decode($payload['iv']));
        $data = json_decode((string) $plain, true);

        if (! is_array($data)) {
            $this->error('Decifratura fallita.');

            return self::FAILURE;
        }

        $rows = collect($data['collection'] ?? []);
        $this->info(sprintf(
            'OK: %d carte, %d righe, %d copie, %d righe Delta Reign',
            count($data['cartes'] ?? []),
            $rows->count(),
            $rows->sum('quantite'),
            collect($data['cartes'] ?? [])->where('nom_court', 'DLR')->count(),
        ));

        $shape = fn ($value): string => is_array($value)
            ? collect($value)->map(fn ($v, $k) => $k.':'.get_debug_type($v))->implode(', ')
            : get_debug_type($value);
        $this->line('Chiavi risposta: '.implode(', ', array_keys($data)));
        $this->line('Carta: '.$shape($data['cartes'][0] ?? []));
        $this->line('Versione: '.$shape($data['cartes'][0]['versions'][0] ?? []));
        $this->line('Riga: '.$shape($rows->first() ?? []));
        $this->line('Righe con prix_achat/date_achat/note valorizzati: '.$rows->filter(fn ($r) => filled($r['prix_achat'] ?? null) || filled($r['date_achat'] ?? null) || filled($r['note'] ?? null))->count());

        $details = $http()->asForm()
            ->withHeaders(['X-CSRF-TOKEN' => $csrf, 'X-Requested-With' => 'XMLHttpRequest'])
            ->post(self::BASE.'/api/collection/get_carte_details', ['id_card' => 26642, 'lang' => 'en'])
            ->json();
        $detail = json_decode((string) openssl_decrypt(base64_decode($details['data']), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, base64_decode($details['iv'])), true);
        $this->line('Dettaglio chiavi: '.$shape($detail));
        foreach ($detail as $k => $v) {
            if (is_array($v) && isset($v[0]) && is_array($v[0])) {
                $this->line("  {$k}[0]: ".$shape($v[0]));
            } elseif (is_array($v) && ! isset($v[0])) {
                $this->line("  {$k}: ".$shape($v));
            }
        }

        return self::SUCCESS;
    }

    private function metaCsrf(string $html): ?string
    {
        return preg_match('/<meta name="csrf-token" content="([^"]+)"/', $html, $match) ? $match[1] : null;
    }
}
