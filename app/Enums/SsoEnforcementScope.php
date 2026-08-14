<?php

namespace App\Enums;

/**
 * How broadly `team_identity_providers.enforce_sso` blocks password sign-in.
 */
enum SsoEnforcementScope: string
{
    case Global = 'global';
    case Organization = 'organization';

    /**
     * Get the display label for the scope.
     */
    public function label(): string
    {
        return match ($this) {
            self::Global => __('Everywhere this member signs in'),
            self::Organization => __('This organization only'),
        };
    }

    /**
     * Get the explanatory description shown beneath the option.
     */
    public function description(): string
    {
        return match ($this) {
            self::Global => __("Blocks the member's password entirely, including for any other organization or personal team they belong to."),
            self::Organization => __("Hides the password field on this organization's sign-in page only. The member can still sign in with a password elsewhere and switch into this organization — this is not a hard security boundary."),
        };
    }
}
