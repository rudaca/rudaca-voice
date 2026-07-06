<?php

namespace Database\Factories;

use App\Models\IdeaBoard;
use App\Models\IdeaCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IdeaCategory>
 */
class IdeaCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->randomElement([
            'Process Improvement',
            'Software Request',
            'Automation',
            'Reporting',
            'Customer Service',
            'HR / Onboarding',
            'Cost Savings',
        ]);

        return [
            // Derive team_id from the board so the graph stays internally consistent.
            'board_id' => IdeaBoard::factory(),
            'team_id' => fn (array $attributes) => IdeaBoard::find($attributes['board_id'])->team_id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 999999),
            'description' => fake()->sentence(),
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
