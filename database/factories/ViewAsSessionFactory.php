<?php

namespace Database\Factories;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Models\ViewAsSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ViewAsSession>
 */
class ViewAsSessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'super_admin_id' => User::factory()->state(['is_super_admin' => true]),
            'target_user_id' => User::factory(),
            'team_id' => Team::factory(),
            'role_viewed_as' => TeamRole::Employee,
            'started_at' => now(),
            'last_activity_at' => now(),
        ];
    }

    /**
     * Indicate that the session has ended.
     */
    public function ended(): static
    {
        return $this->state(fn (array $attributes) => [
            'ended_at' => now(),
            'ended_reason' => 'manual',
        ]);
    }
}
