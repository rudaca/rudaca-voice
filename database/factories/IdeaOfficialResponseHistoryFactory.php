<?php

namespace Database\Factories;

use App\Models\Idea;
use App\Models\IdeaOfficialResponseHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IdeaOfficialResponseHistory>
 */
class IdeaOfficialResponseHistoryFactory extends Factory
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
            'official_response_id' => null,
            'actor_user_id' => User::factory(),
            'action' => IdeaOfficialResponseHistory::ACTION_PUBLISHED,
        ];
    }
}
