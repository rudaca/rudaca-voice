<?php

namespace Database\Factories;

use App\Enums\IdentityProvider;
use App\Models\Team;
use App\Models\TeamIdentityProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeamIdentityProvider>
 */
class TeamIdentityProviderFactory extends Factory
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
            'provider' => IdentityProvider::Microsoft,
            'tenant_id' => fake()->uuid(),
            'client_id' => fake()->uuid(),
            'client_secret_encrypted' => fake()->uuid(),
            'enabled' => false,
            'enforce_sso' => false,
            'auto_provision_users' => false,
            'default_role' => null,
            'allowed_domains' => [],
            'configured_by' => null,
            'configured_at' => null,
        ];
    }

    /**
     * Indicate that the configuration is fully enabled.
     */
    public function enabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'enabled' => true,
            'configured_at' => now(),
        ]);
    }
}
