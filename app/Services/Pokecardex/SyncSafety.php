<?php

namespace App\Services\Pokecardex;

use App\Models\PokecardexSyncRun;
use App\Models\User;

/**
 * Freni della sincronizzazione (§ 7 del design) applicati a un piano.
 *
 * - Un lato con meno del 90% delle copie dell'ultimo giro riuscito: stop. Copre
 *   sessioni scadute o siti cambiati che rispondono con collezioni vuote.
 * - Primo giro, o più di 20 copie da togliere: niente scritture dirette, serve
 *   l'anteprima approvata dall'utente.
 * - Con `always_preview` (attivo di serie) ogni giro con scritture passa
 *   dall'anteprima.
 *
 * Le soglie stanno in `config/services.php` (`pokecardex`).
 */
final class SyncSafety
{
    public function check(User $user, SyncPlan $plan): SafetyVerdict
    {
        $previous = PokecardexSyncRun::query()
            ->where('user_id', $user->id)
            ->where('outcome', 'applied')
            ->latest('finished_at')
            ->latest('id')
            ->first();

        $ratio = (float) config('services.pokecardex.min_copies_ratio', 0.9);

        foreach ([
            'Pokecardex' => [$plan->pokecardexCopies, $previous?->pokecardex_copies],
            'CardTrader' => [$plan->cardtraderCopies, $previous?->cardtrader_copies],
        ] as $side => [$now, $before]) {
            if ($before !== null && $before > 0 && $now < $before * $ratio) {
                return new SafetyVerdict(blockedReason: "{$side}: {$now} copie lette contro {$before} dell'ultimo giro riuscito. Giro fermato prima di scrivere.");
            }
        }

        if ($plan->firstRun) {
            return new SafetyVerdict(previewReason: 'Primo allineamento: serve la tua approvazione.');
        }

        $maxRemoved = (int) config('services.pokecardex.max_removed_copies', 20);
        $removed = $plan->removedCopies();

        if ($removed > $maxRemoved) {
            return new SafetyVerdict(previewReason: "Il giro toglierebbe {$removed} copie (soglia {$maxRemoved}): serve la tua approvazione.");
        }

        if ($plan->changes !== [] && config('services.pokecardex.always_preview', true)) {
            return new SafetyVerdict(previewReason: 'Anteprima richiesta per ogni giro: controlla le scritture prima di applicarle.');
        }

        return new SafetyVerdict;
    }
}
