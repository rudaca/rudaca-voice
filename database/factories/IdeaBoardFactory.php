<?php

namespace Database\Factories;

use App\Models\IdeaBoard;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IdeaBoard>
 */
class IdeaBoardFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->randomElement([
            'General Ideas',
            'Operations',
            'Technology',
            'Website',
            'Accounting',
            'Customer Service',
            'Tour Leader App',
        ]);

        return [
            'team_id' => Team::factory(),
            'board_group_id' => null,
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 999999),
            'description' => fake()->sentence(),
            'visibility' => 'internal',
            'sort_order' => 0,
            'is_active' => true,
            'created_by_user_id' => User::factory(),
        ];
    }

    /**
     * Indicate the board's visibility level.
     */
    public function visibility(string $visibility): static
    {
        return $this->state(fn (array $attributes) => [
            'visibility' => $visibility,
        ]);
    }
}
