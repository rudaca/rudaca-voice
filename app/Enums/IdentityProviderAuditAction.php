<?php

namespace App\Enums;

/**
 * The auditable operations on an organization's identity provider configuration.
 */
enum IdentityProviderAuditAction: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Enabled = 'enabled';
    case Disabled = 'disabled';
    case Disconnected = 'disconnected';
    case LoginSucceeded = 'login succeeded';
    case UserProvisioned = 'user provisioned';
    case LoginFailed = 'login failed';
    case ConnectionTestSucceeded = 'connection test succeeded';
    case ConnectionTestFailed = 'connection test failed';

    /**
     * Get the display label for the action.
     */
    public function label(): string
    {
        return ucfirst($this->value);
    }
}
