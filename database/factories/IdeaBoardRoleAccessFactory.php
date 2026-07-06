<?php

namespace Database\Factories;

use App\Enums\TeamRole;
use App\Models\IdeaBoard;
use App\Models\IdeaBoardRoleAccess;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IdeaBoardRoleAccess>
 */
class IdeaBoardRoleAccessFactory extends Factory
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
            'role' => fake()->randomElement([
                TeamRole::Manager,
                TeamRole::Employee,
                TeamRole::Viewer,
            ]),
        ];
    }
}
