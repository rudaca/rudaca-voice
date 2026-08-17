<?php

namespace App\Actions\OwnerRecovery;

use App\Enums\OwnerRecoveryAuditAction;
use App\Models\OwnerRecoveryAudit;
use App\Models\OwnerRecoveryToken;
use App\Models\Team;
use App\Notifications\Auth\OwnerRecoveryRequested;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class RequestOwnerRecovery
{
    /**
     * Request an owner-recovery token for an organization.
     *
     * Only ever creates a token (and sends an email) when `$email` matches
     * this team's owner — every other case is a silent, audited no-op, so
     * the caller can show the exact same response either way and never
     * reveal whether an email belongs to an owner.
     */
    public function handle(Team $team, string $email, string $ip): void
    {
        $owner = $team->owner();

        if (! $owner || Str::lower(trim($email)) !== Str::lower($owner->email)) {
            OwnerRecoveryAudit::create([
                'team_id' => $team->id,
                'action' => OwnerRecoveryAuditAction::DeniedNotOwner,
                'changed_fields' => ['ip' => $ip],
            ]);

            return;
        }

        $code = (string) random_int(100000, 999999);

        $token = OwnerRecoveryToken::create([
            'team_id' => $team->id,
            'user_id' => $owner->id,
            'code' => Str::random(64),
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(15),
        ]);

        $owner->notify(new OwnerRecoveryRequested($token, $code));

        OwnerRecoveryAudit::create([
            'team_id' => $team->id,
            'owner_recovery_token_id' => $token->id,
            'user_id' => $owner->id,
            'action' => OwnerRecoveryAuditAction::Requested,
            'changed_fields' => ['ip' => $ip],
        ]);

        OwnerRecoveryAudit::create([
            'team_id' => $team->id,
            'owner_recovery_token_id' => $token->id,
            'user_id' => $owner->id,
            'action' => OwnerRecoveryAuditAction::CodeSent,
        ]);
    }
}
