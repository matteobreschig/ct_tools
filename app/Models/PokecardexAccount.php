<?php

namespace App\Models;

use Database\Factories\PokecardexAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Credenziali Pokecardex di un utente. La password serve in chiaro per il
 * login sul sito, quindi è cifrata con la chiave dell'applicazione e non
 * hashata.
 */
class PokecardexAccount extends Model
{
    /** @use HasFactory<PokecardexAccountFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'username',
        'password',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
