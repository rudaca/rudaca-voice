<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A Microsoft sign-in attempt was rejected.
 *
 * Carries two messages on purpose: `$publicMessage` is safe to show the
 * user, while `$logReason` is a short, stable token (never a raw provider
 * or JWT error object) suitable for logs and the identity provider audit
 * trail.
 */
class MicrosoftSsoLoginException extends RuntimeException
{
    public function __construct(
        public readonly string $publicMessage,
        public readonly string $logReason,
        public readonly ?int $teamId = null,
    ) {
        parent::__construct($logReason);
    }
}
