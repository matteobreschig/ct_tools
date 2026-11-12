<?php

namespace App\Jobs;

use App\Exceptions\PokecardexException;
use App\Models\PokecardexSeries;
use App\Models\PokecardexSyncRun;
use App\Models\PokecardexSyncState;
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

    public function __construct(public User $user, public ?int $approvedRunId = null) {}

    public function uniqueId(): string
    {
        return (string) $this->user->id;
    }

    public function handle(CardTraderInventory $inventory): void
    {
        $run = PokecardexSyncRun::create(['user_id' => $this->user->id, 'started_at' => now()]);

        try {
            $account = $this->user->pokecardexAccount
                ?? throw new PokecardexException('Credenziali Pokecardex mancanti: salvale nel profilo.');

            $inventory->refresh($this->user);

            $cardtraderClient = new CTApiClient($this->user);
            $pokecardexClient = new PokecardexClient($account);
            $catalog = new PokecardexCatalog($pokecardexClient);

            if (PokecardexSeries::query()->where('updated_at', '>', now()->subDay())->doesntExist()) {
                $catalog->refreshSeries();
            }

            $collection = $pokecardexClient->collection();
            $catalog->remember($collection['cartes']);

            $firstRun = PokecardexSyncState::query()->where('user_id', $this->user->id)->doesntExist();
            $sales = $firstRun ? (new CardTraderSales($cardtraderClient))->soldByKey() : [];

            $plan = (new SyncPlanner(new CardMatcher($catalog, new BlueprintCatalog($cardtraderClient))))
                ->plan($this->user, $collection, $sales);

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
