<?php

namespace App\Jobs;

use App\Exceptions\PokecardexException;
use App\Models\PokecardexDecision;
use App\Models\PokecardexSyncRun;
use App\Models\User;
use App\Services\CTApiClient;
use App\Services\Pokecardex\BlueprintCatalog;
use App\Services\Pokecardex\CardMatcher;
use App\Services\Pokecardex\CardTraderInventory;
use App\Services\Pokecardex\CardTraderSales;
use App\Services\Pokecardex\CardTraderWriter;
use App\Services\Pokecardex\PokecardexCatalog;
use App\Services\Pokecardex\PokecardexWriter;
use App\Services\Pokecardex\SyncApplier;
use App\Services\Pokecardex\SyncPlanner;
use App\Services\Pokecardex\SyncSafety;
use App\Services\PokecardexClient;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Un giro di sincronizzazione Pokecardex ↔ CardTrader, avviato solo dal
 * bottone "Sincronizza ora" (§ 1.1 del design).
 *
 * 1. Rilegge le inserzioni CardTrader (la tabella locale può essere vecchia).
 * 2. Legge la collezione Pokecardex e, al primo giro, lo storico vendite.
 * 3. Calcola il piano (confronto a tre) e applica i freni (§ 7).
 * 4. Se serve l'anteprima la salva e si ferma; se `$approvedRunId` indica
 *    un'anteprima approvata con lo stesso piano, scrive.
 * 5. Dopo scritture su CardTrader rilegge le inserzioni e accoda la vendita
 *    dinamica.
 *
 * Un giro alla volta per utente (`ShouldBeUnique`). Ogni errore di lettura
 * ferma il giro prima di qualunque scrittura.
 */
class SyncPokecardex implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    /**
     * Segno in cache dell'ultimo aggiornamento dell'elenco serie. Le date delle
     * righe non bastano: una serie importata o abbinata a mano oggi farebbe
     * saltare l'aggiornamento di tutte le altre.
     */
    public const SERIES_REFRESHED_KEY = 'pokecardex:series-refreshed';

    /**
     * Memoria minima per un giro. Rilettura delle inserzioni, collezione,
     * cataloghi e piano stanno insieme in memoria: con i 128 MB predefiniti
     * dell'immagine PHP il primo giro in produzione è morto nel piano.
     */
    public const MEMORY_LIMIT = '512M';

    /**
     * Attesa massima di ogni chiamata CardTrader quando il limite dell'app è pieno
     * (8 al secondo per tutti, 100 al minuto per utente). Il giro ne fa centinaia
     * di fila e lo controllo prezzi orario intasa il limite comune: meglio
     * aspettare che fallire a metà.
     */
    public const CARDTRADER_RATE_LIMIT_WAIT_SECONDS = 120;

    public function __construct(public User $user, public ?int $approvedRunId = null) {}

    public function uniqueId(): string
    {
        return (string) $this->user->id;
    }

    public function handle(CardTraderInventory $inventory): void
    {
        $this->raiseMemoryLimit();

        $run = PokecardexSyncRun::create(['user_id' => $this->user->id, 'started_at' => now()]);

        try {
            $account = $this->user->pokecardexAccount
                ?? throw new PokecardexException('Credenziali Pokecardex mancanti: salvale nel profilo.');

            $inventory->refresh($this->user);

            $cardtraderClient = new CTApiClient($this->user, self::CARDTRADER_RATE_LIMIT_WAIT_SECONDS);
            $pokecardexClient = new PokecardexClient($account);
            $catalog = new PokecardexCatalog($pokecardexClient);

            if (! Cache::has(self::SERIES_REFRESHED_KEY)) {
                $catalog->refreshSeries();
                Cache::put(self::SERIES_REFRESHED_KEY, now()->toIso8601String(), now()->addDay());
            }

            $collection = $pokecardexClient->collection();
            $catalog->remember($collection['cartes']);

            $firstRun = PokecardexSyncRun::isFirstRunFor($this->user);
            $sales = $firstRun ? (new CardTraderSales($cardtraderClient))->soldByKey() : [];

            $plan = (new SyncPlanner(new CardMatcher($catalog, new BlueprintCatalog($cardtraderClient))))
                ->plan($this->user, $collection, $sales, PokecardexDecision::sidesFor($this->user));

            $run->fill([
                'pokecardex_copies' => $plan->pokecardexCopies,
                'cardtrader_copies' => $plan->cardtraderCopies,
                'plan' => $plan->toArray(),
                'fingerprint' => $plan->fingerprint(),
            ]);

            $verdict = (new SyncSafety)->check($this->user, $plan);

            if ($verdict->isBlocked()) {
                $this->finish($run, 'blocked', $verdict->blockedReason);

                return;
            }

            if ($verdict->needsPreview() && ! $this->isApproved($plan->fingerprint())) {
                $message = $this->approvedRunId !== null
                    ? 'Il piano è cambiato dopo la tua approvazione: controlla la nuova anteprima.'
                    : $verdict->previewReason;
                $this->finish($run, 'preview', $message);

                return;
            }

            $result = (new SyncApplier(new CardTraderWriter($cardtraderClient), new PokecardexWriter($pokecardexClient)))
                ->apply($this->user, $plan);

            if ($result->cardtraderWrites > 0) {
                $inventory->refresh($this->user);
                ApplyDynamicSale::dispatch($this->user);
            }

            if (! $result->failed()) {
                // Le scelte di lato valgono per un giro: dopo le scritture la
                // base concordata ne tiene già conto.
                PokecardexDecision::query()->where('user_id', $this->user->id)->where('kind', PokecardexDecision::SIDE)->delete();

                $copies = $plan->copiesAfter();
                $run->fill(['pokecardex_copies' => $copies['pokecardex'], 'cardtrader_copies' => $copies['cardtrader']]);
            }

            $this->finish(
                $run,
                $result->failed() ? 'failed' : 'applied',
                $result->failure ?? "Scritture fatte: {$result->cardtraderWrites} su CardTrader, {$result->pokecardexWrites} su Pokecardex.",
            );
        } catch (Throwable $exception) {
            $this->finish($run, 'failed', $exception->getMessage());
        }
    }

    /**
     * Il job è morto fuori da handle() (tempo scaduto, errore fatale): chiude il
     * giro rimasto aperto, che altrimenti resterebbe "in corso".
     */
    public function failed(?Throwable $exception): void
    {
        $run = PokecardexSyncRun::query()
            ->where('user_id', $this->user->id)
            ->whereNull('finished_at')
            ->latest('id')
            ->first();

        if ($run !== null) {
            $this->finish($run, 'failed', 'Il giro si è interrotto: '.($exception?->getMessage() ?: 'motivo sconosciuto'));
        }
    }

    /**
     * Alza il limite di memoria a MEMORY_LIMIT, mai lo abbassa (né toglie
     * "senza limite").
     */
    private function raiseMemoryLimit(): void
    {
        $current = (string) ini_get('memory_limit');

        if ($current === '-1' || self::bytes($current) >= self::bytes(self::MEMORY_LIMIT)) {
            return;
        }

        ini_set('memory_limit', self::MEMORY_LIMIT);
    }

    /**
     * Valore di php.ini come "128M" o "1G" in byte.
     */
    private static function bytes(string $value): int
    {
        $number = (int) $value;

        return match (strtolower(substr(trim($value), -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    private function isApproved(string $fingerprint): bool
    {
        if ($this->approvedRunId === null) {
            return false;
        }

        return PokecardexSyncRun::query()
            ->whereKey($this->approvedRunId)
            ->where('user_id', $this->user->id)
            ->where('outcome', 'preview')
            ->where('fingerprint', $fingerprint)
            ->exists();
    }

    private function finish(PokecardexSyncRun $run, string $outcome, ?string $message): void
    {
        $run->fill(['outcome' => $outcome, 'message' => $message, 'finished_at' => now()])->save();

        $notification = Notification::make()->title(match ($outcome) {
            'applied' => 'Sincronizzazione Pokecardex completata',
            'preview' => 'Sincronizzazione Pokecardex: anteprima da approvare',
            'blocked' => 'Sincronizzazione Pokecardex fermata',
            default => 'Sincronizzazione Pokecardex non riuscita',
        })->body($message);

        match ($outcome) {
            'applied' => $notification->success(),
            'preview' => $notification->warning(),
            default => $notification->danger(),
        };

        $notification->sendToDatabase($this->user);
    }
}
