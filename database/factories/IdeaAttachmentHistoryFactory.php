<?php

namespace Database\Factories;

use App\Models\Idea;
use App\Models\IdeaAttachmentHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IdeaAttachmentHistory>
 */
class IdeaAttachmentHistoryFactory extends Factory
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
            'idea_attachment_id' => null,
            'actor_user_id' => User::factory(),
            'action' => IdeaAttachmentHistory::ACTION_ADDED,
            'original_filename' => $this->faker->word().'.pdf',
        ];
    }
}
