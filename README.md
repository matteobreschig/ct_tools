# CT Alert

Applicazione Laravel per monitorare prezzi e disponibilità di carte Pokémon e prodotti Amazon, con notifiche in tempo reale su Telegram e un pannello di gestione Filament.

## Cosa fa

- **Monitoraggio carte Cardtrader.** Sincronizza i blueprint dal marketplace Cardtrader (gioco Pokémon) e invia un alert quando il prezzo di una carta tracciata scende sotto la soglia impostata.
- **Repricing dinamico.** Aggiorna in automatico il prezzo delle proprie inserzioni Cardtrader e applica ribassi programmati.
- **Monitoraggio Amazon.** Controlla a intervalli regolari le pagine prodotto Amazon per intercettare cambi di disponibilità e di prezzo, con un tetto di frequenza globale che protegge dall'essere bloccati.
- **Notifiche Telegram.** Ogni evento rilevante raggiunge l'utente tramite un bot Telegram.
- **Pannello Filament.** Gestione di carte tracciate, prodotti, monitoraggi Amazon, gruppi di carte e utenti da un'unica interfaccia amministrativa.

## Stack tecnico

| Ambito | Tecnologia |
| --- | --- |
| Linguaggio | PHP 8.4 |
| Framework | Laravel 12 |
| Pannello admin | Filament 5 |
| Interfaccia reattiva | Livewire 4 |
| Code e worker | Laravel Horizon (Redis) |
| Dati carte | tcgdex SDK |
| Notifiche | Telegram |
| Test | Pest 4 |
| Stili | Tailwind CSS 4 |
| Database | MariaDB |

## Requisiti

- PHP 8.4 con le estensioni `dom` e `pdo`
- Composer
- Node.js e npm
- MariaDB (o MySQL)
- Redis, consigliato per le code tramite Horizon

## Installazione

```bash
git clone <url-del-repository> ctalert
cd ctalert

composer install
npm install

cp .env.example .env
php artisan key:generate
```

Configura le credenziali del database nel file `.env`, poi crea lo schema:

```bash
php artisan migrate
```

Compila gli asset e avvia l'ambiente di sviluppo:

```bash
npm run dev
php artisan serve
```

In alternativa `composer run dev` avvia insieme server, code, log e Vite.

## Variabili d'ambiente principali

Oltre alla configurazione standard di Laravel, l'applicazione usa:

| Variabile | Descrizione |
| --- | --- |
| `TELEGRAM_BOT_TOKEN` | Token del bot Telegram per le notifiche |
| `CT_DISABLE_PRICE` | Prezzo sentinella usato per disattivare un'inserzione Cardtrader |
| `AMAZON_MONITOR_MIN_INTERVAL` | Intervallo minimo di polling in secondi, tetto di sicurezza condiviso da tutti i monitoraggi |
| `AMAZON_MONITOR_DEFAULT_INTERVAL` | Intervallo proposto sui nuovi monitoraggi |
| `AMAZON_MONITOR_PRUNE_DAYS` | Giorni di storico dei controlli da conservare |
| `AMAZON_MONITOR_PROXY` | Proxy opzionale per le richieste verso Amazon |

## Attività pianificate

Lo scheduler di Laravel coordina i lavori ricorrenti:

- controllo orario dei prezzi dei blueprint tracciati e invio degli alert;
- sincronizzazione giornaliera dei blueprint da Cardtrader;
- pulizia giornaliera dello storico dei controlli Amazon.

Il monitoraggio Amazon gira come processo continuo tramite il comando `php artisan amazon:monitor-loop`, mentre le code sono gestite da Horizon.

Per attivare lo scheduler in produzione basta un cron ogni minuto:

```bash
* * * * * cd /percorso/ctalert && php artisan schedule:run >> /dev/null 2>&1
```

## Docker

Il repository include un `Dockerfile` e un `docker-compose.yml` per avviare l'applicazione in container.

```bash
docker compose up -d
```

## Test

La suite è scritta con Pest:

```bash
php artisan test
```

## Qualità del codice

Prima di aprire una pull request formatta il codice con Pint:

```bash
vendor/bin/pint
```

## Licenza

Distribuito con licenza MIT. I dettagli sono nel file [LICENSE](LICENSE).
