<?php

namespace App\Enums;

/**
 * The auditable operations on a user's linked external identity.
 */
enum UserIdentityAccountAuditAction: string
{
    case Linked = 'linked';
    case Unlinked = 'unlinked';

    /**
     * Get the display label for the action.
     */
    public function label(): string
    {
        return ucfirst($this->value);
    }
}
