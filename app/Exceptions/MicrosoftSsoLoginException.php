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
 *
 * `$context` optionally carries short diagnostic detail (e.g. the error
 * code and description a provider's token endpoint returned) for the same
 * log/audit destinations as `$logReason` — never shown to the user, and
 * never a token, secret, or full request/response body.
 */
class MicrosoftSsoLoginException extends RuntimeException
{
    /**
     * @param  array<string, string>  $context
     */
    public function __construct(
        public readonly string $publicMessage,
        public readonly string $logReason,
        public readonly ?int $teamId = null,
        public readonly array $context = [],
    ) {
        parent::__construct($logReason);
    }
}
