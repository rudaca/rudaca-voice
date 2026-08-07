<?php

namespace App\Rules;

use App\Models\TeamIdentityProvider;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Validates a Microsoft Entra ID tenant identifier.
 *
 * Accepts a directory GUID, or one of the multi-tenant/personal-account
 * literals Microsoft also allows in place of a real tenant id.
 */
class MicrosoftTenantId implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail(__('The :attribute must be a valid Microsoft tenant id.'));

            return;
        }

        if (in_array($value, TeamIdentityProvider::MULTI_TENANT_IDENTIFIERS, true)) {
            return;
        }

        if (! Str::isUuid($value)) {
            $fail(__('The :attribute must be a directory (tenant) GUID, or one of: common, organizations, consumers.'));
        }
    }
}
