<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un giro di sincronizzazione. `outcome`: `applied` (scritture fatte),
 * `preview` (in attesa di approvazione), `blocked` (fermato da un freno),
 * `failed` (errore di lettura o scrittura).
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
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
