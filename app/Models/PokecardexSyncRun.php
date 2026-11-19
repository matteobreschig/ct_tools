<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un giro di sincronizzazione. `outcome`: `applied` (scritture fatte),
 * `preview` (in attesa di approvazione), `blocked` (fermato da un freno),
 * `failed` (errore di lettura o scrittura). `plan` è il piano del giro per
 * l'anteprima e lo storico; `fingerprint` identifica le sue scritture, così
 * un'anteprima approvata si applica solo se il piano non è cambiato.
 */
class PokecardexSyncRun extends Model
{
    protected $fillable = [
        'user_id',
        'started_at',
        'finished_at',
        'outcome',
        'pokecardex_copies',
        'cardtrader_copies',
        'message',
        'plan',
        'fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'plan' => 'array',
        ];
    }
}
