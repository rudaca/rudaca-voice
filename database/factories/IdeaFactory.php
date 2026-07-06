<?php

namespace Database\Factories;

use App\Models\Idea;
use App\Models\IdeaBoard;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Idea>
 */
class IdeaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = rtrim(fake()->unique()->sentence(6), '.');

        return [
            // Derive team_id and board_group_id from the board for consistency.
            'board_id' => IdeaBoard::factory(),
            'team_id' => fn (array $attributes) => IdeaBoard::find($attributes['board_id'])->team_id,
            'board_group_id' => fn (array $attributes) => IdeaBoard::find($attributes['board_id'])->board_group_id,
            'category_id' => null,
            'submitted_by_user_id' => User::factory(),
            'title' => $title,
            'slug' => Str::slug($title).'-'.fake()->unique()->numberBetween(1, 999999),
            'description' => fake()->paragraphs(2, true),
            'status' => fake()->randomElement([
                'new', 'under_review', 'planned', 'in_progress', 'released', 'not_doing', 'duplicate',
            ]),
            'priority' => fake()->randomElement(['low', 'medium', 'high']),
            'impact' => fake()->randomElement(['low', 'medium', 'high']),
            'effort' => fake()->randomElement(['small', 'medium', 'large']),
            'is_anonymous' => false,
            'is_private' => false,
            'duplicate_of_idea_id' => null,
        ];
    }

    /**
     * Indicate that the idea was submitted anonymously.
     */
    public function anonymous(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_anonymous' => true,
        ]);
    }

    /**
     * Indicate that the idea is private.
     */
    public function private(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_private' => true,
        ]);
    }

    /**
     * Indicate the idea's status.
     */
    public function status(string $status): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => $status,
        ]);
    }
}
