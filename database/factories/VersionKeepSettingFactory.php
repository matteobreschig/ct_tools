<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\VersionKeepSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VersionKeepSetting>
 */
class VersionKeepSettingFactory extends Factory
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
            'blueprint_id' => fake()->numberBetween(1, 9999999),
            'language' => 'it',
            'condition' => 'nm',
            'reverse' => false,
            'keep_quantity' => 1,
        ];
    }
}
