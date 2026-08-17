<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An owner-recovery code verification attempt was rejected.
 *
 * `$publicMessage` is deliberately the same generic copy for every failure
 * reason (expired, used, wrong code, attempts exceeded) — the caller must
 * never let a requester distinguish which one occurred. `$logReason` is a
 * short, stable token suitable for the recovery audit trail.
 */
class OwnerRecoveryException extends RuntimeException
{
    public function __construct(
        public readonly string $publicMessage,
        public readonly string $logReason,
    ) {
        parent::__construct($logReason);
    }
}
