<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Abbinamento (`id_card`, `id_version`) → blueprint CardTrader. `source`:
 * `cardmarket` (per ID Cardmarket), `rule` (regola di serie) o `manual`
 * (scelto dall'utente, vince sempre).
 */
class PokecardexCardMatch extends Model
{
    protected $fillable = [
        'id_card',
        'id_version',
        'blueprint_id',
        'source',
    ];
}
