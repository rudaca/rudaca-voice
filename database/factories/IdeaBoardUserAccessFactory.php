<?php

namespace Database\Factories;

use App\Models\IdeaBoard;
use App\Models\IdeaBoardUserAccess;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IdeaBoardUserAccess>
 */
class IdeaBoardUserAccessFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'board_id' => IdeaBoard::factory(),
            'user_id' => User::factory(),
            'access_level' => fake()->randomElement(['view', 'contribute', 'manage']),
        ];
    }
}
