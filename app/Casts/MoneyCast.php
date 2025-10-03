<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

class MoneyCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): float
    {
        // Transform the integer stored in the database into a float.
        return round(floatval($value) / 100, precision: 2);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): float {
        // Transform the float into an integer for storage.
        return round(floatval($value) * 100);
    }
}
