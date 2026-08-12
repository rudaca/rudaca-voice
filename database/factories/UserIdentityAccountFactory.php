<?php

namespace Database\Factories;

use App\Enums\IdentityProvider;
use App\Models\Team;
use App\Models\User;
use App\Models\UserIdentityAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserIdentityAccount>
 */
class UserIdentityAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'team_id' => Team::factory(),
            'provider' => IdentityProvider::Microsoft,
            'provider_tenant_id' => fake()->uuid(),
            'provider_subject_id' => fake()->uuid(),
            'email_at_link_time' => fake()->safeEmail(),
            'display_name' => fake()->name(),
            'last_login_at' => now(),
        ];
    }
}
