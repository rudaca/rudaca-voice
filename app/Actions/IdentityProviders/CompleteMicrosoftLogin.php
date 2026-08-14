<?php

namespace App\Actions\IdentityProviders;

use App\Data\MicrosoftLoginResult;
use App\Enums\IdentityProvider;
use App\Enums\IdentityProviderAuditAction;
use App\Enums\UserIdentityAccountAuditAction;
use App\Exceptions\MicrosoftSsoLoginException;
use App\Models\Team;
use App\Models\TeamIdentityProvider;
use App\Models\User;
use App\Models\UserIdentityAccount;
use App\Models\UserIdentityAccountAudit;
use App\Services\Microsoft\MicrosoftAuthorizationCodeExchanger;
use App\Services\Microsoft\MicrosoftIdTokenValidator;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CompleteMicrosoftLogin
{
    public function __construct(
        private readonly MicrosoftAuthorizationCodeExchanger $codeExchanger,
        private readonly MicrosoftIdTokenValidator $tokenValidator,
    ) {}

    /**
     * Complete a Microsoft sign-in callback, returning the authenticated
     * (or newly provisioned) user and the organization they signed into.
     *
     * Every rejection point audits (or, when no organization could even be
     * resolved, logs) a sanitized reason — never a raw token, secret, or
     * provider error body.
     */
    public function handle(Request $request): MicrosoftLoginResult
    {
        $state = (string) $request->query('state');
        $stored = filled($state) ? Cache::pull("oidc_state:{$state}") : null;

        if (! $stored) {
            $this->reject(null, 'invalid_state', __('Your Microsoft sign-in request has expired. Please try signing in again.'));
        }

        $team = Team::find($stored['team_id']);
        $identityProvider = $team?->identityProviderFor(IdentityProvider::Microsoft);

        if (! $team || ! $identityProvider || ! $identityProvider->enabled || ! $identityProvider->isConfigurable()) {
            $this->reject($identityProvider, 'provider_disabled', __('Microsoft sign-in is not available for this organization.'), $team?->id);
        }

        if ($request->filled('error')) {
            $this->reject($identityProvider, 'provider_error', __('Microsoft sign-in was cancelled or denied.'));
        }

        $code = (string) $request->query('code');

        if (blank($code)) {
            $this->reject($identityProvider, 'missing_code', __('Your Microsoft sign-in could not be completed. Please try again.'));
        }

        $idToken = $this->exchangeCodeForIdToken($identityProvider, $code, $stored['code_verifier']);

        try {
            $claims = $this->tokenValidator->validate($idToken, $identityProvider, $stored['nonce']);
        } catch (MicrosoftSsoLoginException $e) {
            // Re-thrown through reject() (rather than left to propagate as-is)
            // so this failure gets the same audit trail entry as every other
            // rejection — MicrosoftIdTokenValidator has no audit access of
            // its own.
            $this->reject($identityProvider, $e->logReason, $e->publicMessage);
        }

        $email = $claims['email'] ?? $claims['preferred_username'] ?? null;

        if (! is_string($email) || blank($email)) {
            $this->reject($identityProvider, 'missing_email_claim', __('Your Microsoft account does not have an email address we can sign you in with.'));
        }

        $name = is_string($claims['name'] ?? null) ? $claims['name'] : $email;

        $user = $this->resolveUser($team, $identityProvider, $claims, $email, $name);

        $this->audit($identityProvider, IdentityProviderAuditAction::LoginSucceeded, $user->id);

        return new MicrosoftLoginResult($user, $team);
    }

    /**
     * Exchange the authorization code for the raw ID token, re-throwing any
     * failure through reject() so it gets the same audit trail entry as
     * every other rejection in this flow.
     */
    private function exchangeCodeForIdToken(TeamIdentityProvider $identityProvider, string $code, string $codeVerifier): string
    {
        try {
            return $this->codeExchanger->exchange($identityProvider, $code, $codeVerifier);
        } catch (MicrosoftSsoLoginException $e) {
            $this->reject($identityProvider, $e->logReason, $e->publicMessage);
        }
    }

    /**
     * Resolve the authenticated user for this callback.
     *
     * A previously linked identity is always authoritative and is looked up
     * first, entirely independent of the claims' current email — this is what
     * makes a later email change on the Microsoft side a non-event for an
     * already-linked account. Only when no link exists yet does this fall
     * back to matching (and then linking) an existing member by email, or
     * provisioning a new one.
     *
     * @param  array<string, mixed>  $claims
     */
    private function resolveUser(Team $team, TeamIdentityProvider $identityProvider, array $claims, string $email, string $name): User
    {
        $tenantId = (string) $claims['tid'];
        $subjectId = $claims['oid'] ?? $claims['sub'] ?? null;

        if (! is_string($subjectId) || blank($subjectId)) {
            $this->reject($identityProvider, 'missing_subject_claim', __('Your Microsoft account is missing information we need to sign you in. Please try again.'));
        }

        $link = UserIdentityAccount::query()
            ->provider(IdentityProvider::Microsoft)
            ->where('provider_tenant_id', $tenantId)
            ->where('provider_subject_id', $subjectId)
            ->first();

        if ($link) {
            if ($link->team_id !== $team->id) {
                $this->reject($identityProvider, 'identity_linked_to_other_organization', __('This Microsoft account is linked to a different organization and cannot sign in here.'));
            }

            $user = $link->user;
            $this->assertActive($identityProvider, $user);

            $link->update(['last_login_at' => now()]);

            return $user;
        }

        $user = $this->locateOrProvisionUser($team, $identityProvider, $email, $name);
        $this->assertActive($identityProvider, $user);

        $this->linkIdentity($team, $identityProvider, $user, $tenantId, $subjectId, $email, $name);

        return $user;
    }

    /**
     * Reject the login if the resolved user's account has been deactivated,
     * matching the message Fortify's password login already uses for the
     * same rule (see FortifyServiceProvider::configureActions()).
     */
    private function assertActive(TeamIdentityProvider $identityProvider, User $user): void
    {
        if (! $user->is_active) {
            $this->reject($identityProvider, 'account_inactive', __('Your account has been deactivated. Contact your administrator for access.'));
        }
    }

    /**
     * Create the identity link for a user just matched (or provisioned) by
     * email, so subsequent logins locate them by tenant + subject instead.
     *
     * A unique-constraint violation here means a concurrent login raced this
     * one to link the same identity first; rather than trying to reconcile,
     * the safest response is to ask the user to try again — the retry will
     * find the link that just won the race and succeed cleanly.
     */
    private function linkIdentity(Team $team, TeamIdentityProvider $identityProvider, User $user, string $tenantId, string $subjectId, string $email, string $name): void
    {
        try {
            DB::transaction(function () use ($team, $user, $tenantId, $subjectId, $email, $name) {
                $link = UserIdentityAccount::create([
                    'user_id' => $user->id,
                    'team_id' => $team->id,
                    'provider' => IdentityProvider::Microsoft,
                    'provider_tenant_id' => $tenantId,
                    'provider_subject_id' => $subjectId,
                    'email_at_link_time' => $email,
                    'display_name' => $name,
                    'last_login_at' => now(),
                ]);

                UserIdentityAccountAudit::create([
                    'team_id' => $team->id,
                    'user_identity_account_id' => $link->id,
                    'user_id' => $user->id,
                    'provider' => IdentityProvider::Microsoft,
                    'action' => UserIdentityAccountAuditAction::Linked,
                    'performed_by_user_id' => $user->id,
                ]);
            });
        } catch (QueryException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }

            $this->reject($identityProvider, 'identity_link_race', __('Your Microsoft sign-in could not be completed. Please try again.'));
        }
    }

    /**
     * Find an existing member of the organization by email, or provision one
     * per the organization's auto-provisioning settings.
     *
     * More than one member matching case-insensitively is treated as an
     * ambiguous match rather than picking one arbitrarily — the account's
     * database-level uniqueness is case-sensitive, so this can only happen
     * for genuinely distinct accounts differing only in email case.
     */
    private function locateOrProvisionUser(Team $team, TeamIdentityProvider $identityProvider, string $email, string $name): User
    {
        $matches = $team->members()
            ->whereRaw('LOWER(users.email) = ?', [Str::lower($email)])
            ->get();

        if ($matches->count() > 1) {
            $this->reject($identityProvider, 'ambiguous_email_match', __('Multiple accounts in this organization match your email address. Contact your administrator.'));
        }

        if ($matches->count() === 1) {
            return $matches->first();
        }

        if (! $identityProvider->auto_provision_users) {
            $this->reject($identityProvider, 'unauthorized_account', __('No account was found for you in this organization, and automatic sign-up is not enabled.'));
        }

        if (! $identityProvider->default_role) {
            $this->reject($identityProvider, 'misconfigured_default_role', __('Microsoft sign-in is not fully configured for this organization.'));
        }

        $allowedDomains = $identityProvider->allowed_domains;

        if (filled($allowedDomains)) {
            $domain = Str::lower(Str::after($email, '@'));

            if (! in_array($domain, array_map(Str::lower(...), $allowedDomains), true)) {
                $this->reject($identityProvider, 'domain_not_allowed', __('Your email domain is not authorized to sign in to this organization.'));
            }
        }

        // Only the writes are transactional. The checks above throw (and
        // audit) before this point, and must never be rolled back by it.
        $user = DB::transaction(function () use ($team, $identityProvider, $email, $name) {
            // forceCreate: email_verified_at isn't mass-assignable (see
            // User's #[Fillable] attribute), but a Microsoft-authenticated
            // address is already verified by definition.
            // is_active is set explicitly (rather than left to the column's
            // database default) because forceCreate() only populates the
            // in-memory model with the attributes given here — leaving it out
            // would make the assertActive() check below read a null (falsy)
            // is_active on the very user it just created.
            $user = User::forceCreate([
                'name' => $name,
                'email' => $email,
                'password' => Str::password(64),
                'email_verified_at' => now(),
                'is_active' => true,
            ]);

            $team->memberships()->create([
                'user_id' => $user->id,
                'role' => $identityProvider->default_role,
            ]);

            return $user;
        });

        $this->audit($identityProvider, IdentityProviderAuditAction::UserProvisioned, $user->id);

        return $user;
    }

    /**
     * Record the outcome and throw. When no identity provider could be
     * resolved (e.g. an invalid/expired state), there is nothing to audit
     * against, so this only logs a sanitized reason instead.
     */
    private function reject(?TeamIdentityProvider $identityProvider, string $reason, string $publicMessage, ?int $teamId = null): never
    {
        if ($identityProvider) {
            $this->audit($identityProvider, IdentityProviderAuditAction::LoginFailed, null, $reason);
        } else {
            Log::warning('microsoft_sso_login_failed', ['reason' => $reason, 'team_id' => $teamId]);
        }

        throw new MicrosoftSsoLoginException($publicMessage, $reason, $teamId ?? $identityProvider?->team_id);
    }

    private function audit(TeamIdentityProvider $identityProvider, IdentityProviderAuditAction $action, ?int $performedByUserId, ?string $reason = null): void
    {
        $identityProvider->audits()->create([
            'team_id' => $identityProvider->team_id,
            'provider' => $identityProvider->provider,
            'action' => $action,
            'changed_fields' => $reason ? [$reason] : [],
            'performed_by_user_id' => $performedByUserId,
        ]);
    }
}
