<?php

namespace Database\Factories;

use App\Models\PokecardexAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PokecardexAccount>
 */
class PokecardexAccountFactory extends Factory
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
            'username' => fake()->userName(),
            'password' => fake()->password(12),
        ];
    }
}
