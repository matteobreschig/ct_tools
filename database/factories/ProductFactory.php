<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'product_id' => fake()->unique()->numberBetween(1, 99999999),
            'name' => fake()->word(),
            'quantity' => 1,
            'price' => 1.0,
            'sale' => false,
            'sale_locked' => false,
            'blueprint_id' => fake()->numberBetween(1, 9999999),
            'collector_number' => (string) fake()->numberBetween(1, 200),
            'rarity' => 'Common',
            'tournament_legal' => true,
            'last_update' => now(),
            'condition' => 'nm',
            'language' => 'it',
            'reverse' => false,
            'signed' => false,
            'altered' => false,
            'first_edition' => false,
        ];
    }
}
