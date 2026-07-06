<?php

namespace Database\Factories;

use App\Models\Idea;
use App\Models\IdeaStatusHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IdeaStatusHistory>
 */
class IdeaStatusHistoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'idea_id' => Idea::factory(),
            'changed_by_user_id' => User::factory(),
            'old_status' => 'new',
            'new_status' => fake()->randomElement([
                'under_review', 'planned', 'in_progress', 'released', 'not_doing',
            ]),
            'note' => fake()->optional()->sentence(),
        ];
    }
}
