<?php

namespace App\Jobs;

use App\Models\CardGroup;
use App\Models\CardGroupSetting;
use App\Models\User;
use App\Services\CTApiClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Vendita dinamica: per ogni gruppo di carte tiene ferme le copie minime
 * richieste (deroga del gruppo o minimo globale dell'utente) e mette in
 * vendita il resto, spingendo i prezzi a Cardtrader in un'unica chiamata.
 */
class ApplyDynamicSale implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public function __construct(public User $user)
    {
    }

    public function handle(): void
    {
        $hasMinimums = $this->user->keep_quantity !== null
            || $this->user->keep_quantity_pokemon !== null
            || $this->user->keep_quantity_trainer !== null
            || $this->user->keep_quantity_energy !== null;
        $overrides = CardGroupSetting::where('user_id', $this->user->id)
            ->pluck('keep_quantity', 'group_key');

        if (! $hasMinimums && $overrides->isEmpty()) {
            return;
        }

        $ctData = ['products' => []];

        CardGroup::where('user_id', $this->user->id)
            ->get()
            ->each(function (CardGroup $group) use ($overrides, &$ctData) {
                // Tengo le copie più economiche e vendo prima quelle di maggior
                // valore. Secondo criterio l'id, così l'ordine resta stabile a
                // parità di prezzo.
                $products = $group->products()
                    ->orderByRaw('COALESCE(cardmarket_price_low, price, 0)')
                    ->orderBy('id')
                    ->get();

                $keep = $overrides[$group->group_key]
                    ?? $this->user->keepQuantityForType($products->first()?->card_type);

                if ($keep === null) {
                    return;
                }

                $kept = 0;
                foreach ($products as $product) {
                    $sell = $kept >= $keep;

                    if (! $sell) {
                        // La riga di confine resta tutta fuori vendita: meglio
                        // qualche copia in più che scendere sotto il minimo
                        // (l'inserzione Cardtrader o è tutta in vendita o no).
                        $kept += $product->quantity;
                    }

                    // Senza un prezzo proprio pubblico al prezzo trend Cardmarket.
                    if ($sell && $product->price <= 0 && $product->cardmarket_price_trend > 0) {
                        $product->price = $product->cardmarket_price_trend;
                    }

                    // Senza prezzo né trend l'inserzione non è pubblicabile.
                    if ($sell && $product->price <= 0) {
                        continue;
                    }

                    // Spingo anche le righe già in vendita a cui ho appena
                    // assegnato il prezzo trend.
                    if ($product->sale === $sell && ! $product->isDirty('price')) {
                        continue;
                    }

                    $product->sale = $sell;
                    $product->save();

                    $ctData['products'][] = [
                        'id' => $product->product_id,
                        'price' => $sell ? $product->price : config('app.ct_disable_price'),
                    ];
                }
            });

        if ($ctData['products'] !== []) {
            (new CTApiClient($this->user))->post('/products/bulk_update', $ctData);
        }
    }
}
