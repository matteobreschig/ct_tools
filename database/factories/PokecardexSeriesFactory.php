<?php

namespace Database\Factories;

use App\Models\PokecardexSeries;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PokecardexSeries>
 */
class PokecardexSeriesFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id_serie' => fake()->unique()->numberBetween(1, 999999),
            'lang' => 'en',
            'code' => fake()->unique()->lexify('???'),
            'name_us' => fake()->words(2, true),
            'id_cardmarket' => fake()->numberBetween(1000, 9999),
        ];
    }
}
