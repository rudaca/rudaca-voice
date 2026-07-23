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
            'note' => fn (array $attributes) => $attributes['new_status'] === 'new'
                ? null
                : fake()->randomElement($this->notesFor($attributes['new_status'])),
        ];
    }

    /**
     * Realistic reviewer notes for the given status, explaining the "why"
     * behind the change (most important for "not_doing", where reviewers
     * need to see the reasoning for declining an idea).
     *
     * @return array<int, string>
     */
    private function notesFor(string $status): array
    {
        return match ($status) {
            'under_review' => [
                "Added to the queue for next week's planning meeting.",
                'Checking feasibility with IT before committing to this.',
                'Reviewing alongside a couple of similar requests from other teams.',
            ],
            'planned' => [
                'Approved and added to the roadmap for next quarter.',
                'Prioritized during planning — scheduling for the next sprint.',
                'Greenlit, pending budget sign-off before we kick off.',
            ],
            'in_progress' => [
                'Work has started with the internal tools team.',
                'In development, targeting a release in the next few weeks.',
                'Kicked off — an early version is being tested with a small group.',
            ],
            'released' => [
                'Shipped and live for everyone as of this week.',
                'Rolled out after a successful pilot with one team.',
                'Live in production. Watching for feedback before a wider rollout.',
            ],
            'not_doing' => [
                'Not moving forward — the cost of building and maintaining this outweighs the benefit right now.',
                'Declined for now: overlaps with a vendor feature already on our roadmap, so building our own would be duplicate work.',
                'Reviewed and deferred. Lower priority than other work this year; we will revisit at the next planning cycle.',
                'Not doing this one — projected usage is too low to justify the development time.',
                'Closed without action: not feasible with our current booking system without a larger platform change.',
            ],
            'duplicate' => [
                'Marked as a duplicate — the same request is already being tracked under another idea.',
                'This overlaps with an existing idea already in progress, so merging the discussion there instead.',
            ],
            default => ['Status updated.'],
        };
    }
}
