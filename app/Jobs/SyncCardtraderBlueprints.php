<?php

namespace App\Jobs;

use App\Exceptions\ApiRateLimitException;
use App\Models\CtBlueprint;
use App\Models\Product;
use App\Models\User;
use App\Services\CTApiClient;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;

class SyncCardtraderBlueprints implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $timeout = 120;

    /**
     * Una sola istanza in coda o in esecuzione alla volta: viene accodato sia
     * dalla pianificazione giornaliera sia in coda a SyncProducts.
     * Finestra allineata a retryUntil.
     */
    public int $uniqueFor = 10800;

    /**
     * Utente di cui usare la API key. Se null, usa il primo disponibile.
     */
    public ?User $user;

    public function __construct(?User $user = null)
    {
        $this->user = $user;
    }

    /**
     * Riprova entro questa finestra: al rate limit il job si rimette in coda e
     * riparte dai blueprint mancanti, senza consumare un contatore di tentativi.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(3);
    }

    /**
     * Recupera il `meta_name` (identità carta CardTrader) per ogni blueprint
     * posseduto e non ancora mappato. Endpoint /blueprints/{id} sincrono: niente
     * export asincrono. Al rate limit rilascia e riprende dai mancanti.
     */
    public function handle(): void
    {
        $user = $this->user ?? User::whereNotNull('ct_api_key')->first();

        if (! $user) {
            return;
        }

        $client = new CTApiClient($user);

        // Blueprint posseduti non ancora mappati. Sottoquery NOT EXISTS per
        // evitare il limite di placeholder di un whereNotIn con array enorme.
        $todo = Product::query()
            ->whereNotNull('blueprint_id')
            ->whereNotExists(fn ($q) => $q
                ->selectRaw('1')
                ->from('ct_blueprints')
                ->whereColumn('ct_blueprints.blueprint_id', 'products.blueprint_id'))
            ->distinct()
            ->pluck('blueprint_id');

        foreach ($todo as $blueprintId) {
            // Cadenza ~7 req/s per restare sotto il cap globale (8/s) e colpire
            // solo il limite per-utente (100/60s), che gestisco con release.
            usleep(150000);

            try {
                $blueprint = $client->get('/blueprints/' . $blueprintId)->json();
            } catch (ApiRateLimitException $e) {
                // Quota per-utente esaurita: riverso intanto quanto mappato e
                // riprendo dai mancanti alla prossima esecuzione (il job non
                // perde progresso, fa upsert man mano).
                $this->backfillProductMetaNames();
                $this->release(random_int(60, 75));

                return;
            } catch (RequestException $e) {
                // Blueprint inesistente o errore non recuperabile: lo registro
                // comunque (meta_name null) per non riprovarlo all'infinito.
                $blueprint = null;
            }

            CtBlueprint::updateOrCreate(
                ['blueprint_id' => $blueprintId],
                [
                    'meta_name' => $blueprint['meta_name'] ?? null,
                    'name' => $blueprint['name'] ?? null,
                ],
            );
        }

        $this->backfillProductMetaNames();
    }

    /**
     * Riversa i meta_name appena mappati sui prodotti che ne sono privi, così
     * il raggruppamento compare senza aspettare la prossima sincronizzazione
     * prodotti. Non tocca mai un meta_name già valorizzato: le unioni manuali
     * sopravvivono.
     */
    protected function backfillProductMetaNames(): void
    {
        DB::table('products')
            ->join('ct_blueprints', 'ct_blueprints.blueprint_id', '=', 'products.blueprint_id')
            ->whereNull('products.meta_name')
            ->whereNotNull('ct_blueprints.meta_name')
            ->update(['products.meta_name' => DB::raw('ct_blueprints.meta_name')]);
    }
}
