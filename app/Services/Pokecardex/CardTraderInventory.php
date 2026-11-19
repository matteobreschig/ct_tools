<?php

namespace App\Services\Pokecardex;

use App\Jobs\SyncPokecardex;
use App\Jobs\SyncProducts;
use App\Models\User;

/**
 * Rilegge le inserzioni CardTrader dell'utente nella tabella `products`,
 * senza accodare la vendita dinamica: il giro la accoda dopo le sue scritture.
 */
class CardTraderInventory
{
    public function refresh(User $user): void
    {
        (new SyncProducts($user, applyDynamicSale: false, rateLimitWaitSeconds: SyncPokecardex::CARDTRADER_RATE_LIMIT_WAIT_SECONDS))->sync();
    }
}
