<?php

namespace Database\Factories;

use App\Models\Idea;
use App\Models\IdeaOfficialResponse;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IdeaOfficialResponse>
 */
class IdeaOfficialResponseFactory extends Factory
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
            'responded_by_user_id' => User::factory(),
            'body' => "Thanks for the feedback here — we've reviewed this and wanted to share where things stand.",
            'published_at' => now(),
        ];
    }
}
