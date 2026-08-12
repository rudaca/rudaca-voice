<?php

namespace App\Actions\IdentityProviders;

use App\Enums\UserIdentityAccountAuditAction;
use App\Models\User;
use App\Models\UserIdentityAccount;
use App\Models\UserIdentityAccountAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UnlinkUserIdentityAccount
{
    /**
     * Unlink an external identity from the user it's linked to.
     *
     * Refuses to remove a user's only linked identity unless `$force` is set,
     * since that account may have no other way to sign in.
     */
    public function handle(UserIdentityAccount $identityAccount, User $performedBy, bool $force = false): void
    {
        if (! $force && $this->isOnlyIdentityAccount($identityAccount)) {
            throw ValidationException::withMessages([
                'identityAccount' => [__('This is the only sign-in method linked to this user. Unlinking it may lock them out. Confirm again to unlink anyway.')],
            ]);
        }

        DB::transaction(function () use ($identityAccount, $performedBy, $force) {
            UserIdentityAccountAudit::create([
                'team_id' => $identityAccount->team_id,
                'user_identity_account_id' => $identityAccount->id,
                'user_id' => $identityAccount->user_id,
                'provider' => $identityAccount->provider,
                'action' => UserIdentityAccountAuditAction::Unlinked,
                'changed_fields' => $force ? ['forced'] : [],
                'performed_by_user_id' => $performedBy->id,
            ]);

            $identityAccount->delete();
        });
    }

    /**
     * Whether this is the only external identity linked to the user, anywhere.
     */
    private function isOnlyIdentityAccount(UserIdentityAccount $identityAccount): bool
    {
        return UserIdentityAccount::where('user_id', $identityAccount->user_id)->count() === 1;
    }
}
