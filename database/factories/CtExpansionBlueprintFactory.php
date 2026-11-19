<?php

namespace Database\Factories;

use App\Models\CtExpansionBlueprint;
use App\Models\Expansion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CtExpansionBlueprint>
 */
class CtExpansionBlueprintFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $cardmarketId = fake()->unique()->numberBetween(100000, 999999);

        return [
            'blueprint_id' => fake()->unique()->numberBetween(1, 9999999),
            'expansion_ct_id' => Expansion::factory()->create()->ct_id,
            'name' => fake()->word(),
            'collector_number' => (string) fake()->numberBetween(1, 100),
            'cardmarket_product_id' => $cardmarketId,
            'cardmarket_product_ids' => [$cardmarketId],
        ];
    }
}
