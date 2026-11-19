<?php

namespace Database\Factories;

use App\Models\PokecardexCard;
use App\Models\PokecardexSeries;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PokecardexCard>
 */
class PokecardexCardFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id_card' => fake()->unique()->numberBetween(1, 999999),
            'id_serie' => PokecardexSeries::factory(),
            'num_card' => sprintf('%03d/100', fake()->numberBetween(1, 100)),
            'name_en' => fake()->word(),
            'id_cardmarket' => fake()->unique()->numberBetween(100000, 999999),
            'versions' => [
                ['id_version' => 1, 'nom_version_us' => 'Standard', 'langues' => array_map(fn (int $id): array => ['statut' => 'confirmed', 'id_langue' => $id], [1, 2, 3, 4, 5, 6, 7])],
                ['id_version' => 2, 'nom_version_us' => 'Reverse', 'langues' => array_map(fn (int $id): array => ['statut' => 'confirmed', 'id_langue' => $id], [1, 2, 3, 4, 5, 6, 7])],
            ],
        ];
    }
}
