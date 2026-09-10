<?php

namespace Database\Factories;

use App\Models\Idea;
use App\Models\IdeaEditHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IdeaEditHistory>
 */
class IdeaEditHistoryFactory extends Factory
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
            'actor_user_id' => User::factory(),
            'summary' => 'Title updated.',
        ];
    }
}
