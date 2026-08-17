<?php

namespace App\Enums;

/**
 * The auditable events in an organization owner's recovery flow.
 */
enum OwnerRecoveryAuditAction: string
{
    case Requested = 'requested';
    case DeniedNotOwner = 'denied_not_owner';
    case CodeSent = 'code_sent';
    case CodeFailed = 'code_failed';
    case AttemptsExceeded = 'attempts_exceeded';
    case Expired = 'expired';
    case Succeeded = 'succeeded';

    /**
     * Get the display label for the action.
     */
    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Requested',
            self::DeniedNotOwner => 'Denied (not an owner)',
            self::CodeSent => 'Code sent',
            self::CodeFailed => 'Code failed',
            self::AttemptsExceeded => 'Attempts exceeded',
            self::Expired => 'Expired',
            self::Succeeded => 'Succeeded',
        };
    }
}
