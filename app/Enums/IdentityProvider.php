<?php

namespace App\Enums;

/**
 * The external identity providers an organization may configure for sign-in.
 *
 * This enum is the allow-list backing `team_identity_providers.provider`, which
 * is a plain string column — adding Google, Okta, or GitHub later is a new case
 * here plus a `config/services.php` entry, with no schema change.
 */
enum IdentityProvider: string
{
    case Microsoft = 'microsoft';

    /**
     * Get the display label for the provider.
     */
    public function label(): string
    {
        return match ($this) {
            self::Microsoft => __('Microsoft'),
        };
    }

    /**
     * Get the longer, vendor-accurate name shown on the configuration panel.
     */
    public function description(): string
    {
        return match ($this) {
            self::Microsoft => __('Microsoft Entra ID (Microsoft 365)'),
        };
    }

    /**
     * Whether the provider is scoped to a directory/tenant identifier.
     *
     * Providers that aren't (Google, GitHub) simply leave `tenant_id` null, which
     * is why the column is nullable rather than required at the schema level.
     */
    public function requiresTenantId(): bool
    {
        return match ($this) {
            self::Microsoft => true,
        };
    }

    /**
     * Get the fully-qualified OAuth callback URL to register with the provider.
     *
     * Displayed read-only on the configuration panel and deliberately not stored:
     * it is derived from the application URL so it can never drift out of date.
     */
    public function redirectUrl(): string
    {
        return url("/auth/{$this->value}/callback");
    }
}
