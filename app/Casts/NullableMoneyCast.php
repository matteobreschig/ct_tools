<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Come MoneyCast, ma distingue "nessun valore" da "zero euro".
 *
 * MoneyCast trasforma null in 0.0 in entrambe le direzioni. Va bene per le
 * colonne con default 0, non per un prezzo obiettivo o per l'ultimo prezzo
 * rilevato, dove null significa "non lo so" e zero significherebbe "gratis".
 */
class NullableMoneyCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?float
    {
        if ($value === null) {
            return null;
        }

        return round(floatval($value) / 100, precision: 2);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?float
    {
        if ($value === null) {
            return null;
        }

        return round(floatval($value) * 100);
    }
}
