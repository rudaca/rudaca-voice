<?php

namespace Database\Factories;

use App\Models\OwnerRecoveryToken;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<OwnerRecoveryToken>
 */
class OwnerRecoveryTokenFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'user_id' => User::factory(),
            'code' => Str::random(64),
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(15),
            'used_at' => null,
            'attempts' => 0,
        ];
    }

    /**
     * Indicate that the token has expired.
     */
    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subMinute()]);
    }

    /**
     * Indicate that the token has already been used.
     */
    public function used(): static
    {
        return $this->state(fn () => ['used_at' => now()]);
    }
}
