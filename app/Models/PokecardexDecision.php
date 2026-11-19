<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Decisione dell'utente dalla pagina della sincronizzazione.
 *
 * - `side`: per una carta (`subject` = blueprint|lingua|reverse|prima edizione)
 *   quale lato vince al prossimo giro (`value` = `pokecardex` o `cardtrader`).
 *   Vale per il primo giro e per i conflitti, e si consuma quando il giro
 *   scrive.
 * - `ignore`: una revisione da non mostrare più (`subject` = impronta della
 *   revisione). Resta finché l'utente non la ripristina.
 */
class PokecardexDecision extends Model
{
    public const SIDE = 'side';

    public const IGNORE = 'ignore';

    protected $fillable = [
        'user_id',
        'kind',
        'subject',
        'value',
    ];

    /**
     * Lato scelto per carta.
     *
     * @return array<string, string>
     */
    public static function sidesFor(User $user): array
    {
        return self::query()->where('user_id', $user->id)->where('kind', self::SIDE)->pluck('value', 'subject')->all();
    }
}
