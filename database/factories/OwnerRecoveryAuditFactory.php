<?php

namespace Database\Factories;

use App\Enums\OwnerRecoveryAuditAction;
use App\Models\OwnerRecoveryAudit;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OwnerRecoveryAudit>
 */
class OwnerRecoveryAuditFactory extends Factory
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
            'owner_recovery_token_id' => null,
            'user_id' => null,
            'action' => OwnerRecoveryAuditAction::Requested,
            'changed_fields' => null,
        ];
    }
}
