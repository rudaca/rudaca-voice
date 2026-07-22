<?php

namespace Database\Factories;

use App\Models\Idea;
use App\Models\IdeaComment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IdeaComment>
 */
class IdeaCommentFactory extends Factory
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
            'user_id' => User::factory(),
            'body' => fake()->randomElement($this->publicBodies()),
            'is_internal' => false,
        ];
    }

    /**
     * Indicate that the comment is internal (visible to managers/admins/owners only).
     */
    public function internal(): static
    {
        return $this->state(fn (array $attributes) => [
            'body' => fake()->randomElement($this->internalBodies()),
            'is_internal' => true,
        ]);
    }

    /**
     * Realistic public comments an employee might leave on an idea.
     *
     * @return array<int, string>
     */
    private function publicBodies(): array
    {
        return [
            '+1 from me, we run into this almost every week.',
            "Would love to see this prioritized — it's a real time sink for our team.",
            "Any update on where this stands? Happy to help test if there's a draft.",
            'We worked around this manually for years, so a proper fix would be a big win.',
            'Same issue here, just from a different angle. Glad someone finally wrote it up.',
            "This would also help onboard new hires faster since they'd stop hitting the same snag.",
            'Curious how this would work with our existing process — happy to jump on a call to walk through it.',
            'Not a huge priority for me personally, but I can see how this helps other teams.',
            "We tried something similar last year and it didn't stick. What would be different this time?",
            "This lines up with feedback we've been getting from clients too.",
            'Small thing, but it adds up across the number of times we do this each month.',
            'I ran into this exact problem yesterday — good timing on this idea.',
        ];
    }

    /**
     * Realistic internal notes reviewers leave that only managers/admins/owners see.
     *
     * @return array<int, string>
     */
    private function internalBodies(): array
    {
        return [
            'Checked with IT — feasible, but will need a bit of budget for third-party tooling.',
            'Flagging for next planning meeting, seems like a quick win.',
            'Spoke with the requester, this affects more people than the description suggests.',
            "Need to confirm this doesn't conflict with the reporting changes already in progress.",
            "Good idea, but let's hold until after the busy season before committing resources.",
            'Escalating — this keeps coming up in different forms from multiple departments.',
        ];
    }
}
