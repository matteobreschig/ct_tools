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
        'session_cookies',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'session_cookies' => 'encrypted:array',
            'credentials_rejected_at' => 'datetime',
        ];
    }

    /**
     * Nuove credenziali: la sessione salvata e il blocco per credenziali
     * rifiutate non valgono più.
     *
     * @param  array{username: string, password?: string}  $credentials
     */
    public function fillCredentials(array $credentials): static
    {
        $this->fill($credentials);

        if ($this->isDirty(['username', 'password'])) {
            $this->forceFill([
                'session_cookies' => null,
                'credentials_rejected_at' => null,
            ]);
        }

        return $this;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
