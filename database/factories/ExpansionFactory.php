<?php

namespace Database\Factories;

use App\Models\Expansion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expansion>
 */
class ExpansionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ct_id' => fake()->unique()->numberBetween(1000, 999999),
            'code' => fake()->unique()->lexify('???'),
            'name' => fake()->words(2, true),
            'name_en' => fake()->words(2, true),
        ];
    }
}
