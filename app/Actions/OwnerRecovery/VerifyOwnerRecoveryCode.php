<?php

namespace App\Actions\OwnerRecovery;

use App\Enums\OwnerRecoveryAuditAction;
use App\Exceptions\OwnerRecoveryException;
use App\Models\OwnerRecoveryAudit;
use App\Models\OwnerRecoveryToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class VerifyOwnerRecoveryCode
{
    /**
     * Verify a one-time recovery code and return the owner to log in as.
     *
     * Every rejection throws the same generic, public-facing message
     * regardless of the reason (expired, used, exceeded attempts, ownership
     * changed, wrong code) — only the audit trail records which. Ownership
     * is re-checked here, not just at request time, in case the team's
     * owner changed while the token was outstanding.
     */
    public function handle(OwnerRecoveryToken $token, string $code): User
    {
        $rejected = __('This recovery link is invalid or has expired.');

        // Deliberately NOT one big DB::transaction() around the whole method:
        // every rejection branch below needs its attempt increment/audit row
        // to persist, but throwing inside a transaction closure rolls back
        // everything that happened in it, including those side effects. Only
        // the success path (the last few lines) needs transactional atomicity.
        $token->refresh();

        if ($token->isUsed()) {
            $this->audit($token, OwnerRecoveryAuditAction::CodeFailed, ['reason' => 'used']);

            throw new OwnerRecoveryException($rejected, 'token_already_used');
        }

        if ($token->isExpired()) {
            $this->audit($token, OwnerRecoveryAuditAction::Expired);

            throw new OwnerRecoveryException($rejected, 'token_expired');
        }

        if ($token->hasExceededAttempts()) {
            $this->audit($token, OwnerRecoveryAuditAction::AttemptsExceeded);

            throw new OwnerRecoveryException($rejected, 'attempts_exceeded');
        }

        if (! $token->user->ownsTeam($token->team)) {
            $this->audit($token, OwnerRecoveryAuditAction::DeniedNotOwner);

            throw new OwnerRecoveryException($rejected, 'no_longer_owner');
        }

        if (! Hash::check($code, $token->code_hash)) {
            $token->increment('attempts');

            $this->audit(
                $token,
                $token->hasExceededAttempts() ? OwnerRecoveryAuditAction::AttemptsExceeded : OwnerRecoveryAuditAction::CodeFailed,
            );

            throw new OwnerRecoveryException($rejected, 'code_mismatch');
        }

        return DB::transaction(function () use ($token) {
            $token->update(['used_at' => now()]);

            $this->audit($token, OwnerRecoveryAuditAction::Succeeded);

            return $token->user;
        });
    }

    /**
     * Record an audit entry for this token's team.
     *
     * @param  array<string, mixed>|null  $context
     */
    private function audit(OwnerRecoveryToken $token, OwnerRecoveryAuditAction $action, ?array $context = null): void
    {
        OwnerRecoveryAudit::create([
            'team_id' => $token->team_id,
            'owner_recovery_token_id' => $token->id,
            'user_id' => $token->user_id,
            'action' => $action,
            'changed_fields' => $context,
        ]);
    }
}
