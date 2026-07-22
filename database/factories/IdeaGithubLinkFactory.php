<?php

namespace Database\Factories;

use App\Models\Idea;
use App\Models\IdeaGithubLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IdeaGithubLink>
 */
class IdeaGithubLinkFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $owner = 'ellisontravel';
        $repo = fake()->randomElement(['booking-system', 'website', 'tour-leader-app', 'internal-tools']);
        $number = fake()->unique()->numberBetween(1, 9999);

        return [
            'idea_id' => Idea::factory(),
            'github_owner' => $owner,
            'github_repo' => $repo,
            'github_issue_number' => $number,
            'github_issue_url' => "https://github.com/{$owner}/{$repo}/issues/{$number}",
            'github_issue_state' => 'open',
            'github_issue_status' => fake()->randomElement(['backlog', 'ready', 'in_progress', 'done']),
            'last_synced_at' => null,
        ];
    }
}
