<?php

namespace App\Concerns;

use App\Enums\SsoEnforcementScope;
use App\Enums\TeamRole;
use App\Rules\MicrosoftTenantId;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

trait IdentityProviderValidationRules
{
    /**
     * Get the validation rules used to validate a Microsoft identity provider
     * configuration.
     *
     * Tenant id and client id are always required — the panel does not allow
     * saving without a complete app registration, whether or not sign-in is
     * currently enabled. `$requireSecret` gates the client secret, since a
     * secret already on file may be left blank to keep it unchanged.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function microsoftIdentityProviderRules(bool $requireSecret): array
    {
        return [
            'tenantId' => ['required', 'string', new MicrosoftTenantId],
            'clientId' => ['required', 'string', 'uuid'],
            'newSecretInput' => [$requireSecret ? 'required' : 'nullable', 'string'],
            'enforceSso' => ['boolean'],
            'enforceSsoScope' => ['required', Rule::enum(SsoEnforcementScope::class)],
            'autoProvisionUsers' => ['boolean'],
            'defaultRole' => [
                Rule::requiredIf(fn () => $this->autoProvisionUsers === true),
                'nullable',
                Rule::enum(TeamRole::class),
            ],
            'allowedDomains' => ['array'],
            'allowedDomains.*' => ['string', 'max:255', 'regex:/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.[a-z0-9-]{1,63})+$/i'],
        ];
    }

    /**
     * Detect case-insensitive duplicate domains in the submitted list, so the
     * caller can surface a friendly validation error before anything is saved.
     *
     * @param  array<int, string>  $domains
     */
    protected function hasDuplicateDomains(array $domains): bool
    {
        $normalized = array_map(fn (string $domain) => strtolower(trim($domain)), $domains);

        return count($normalized) !== count(array_unique($normalized));
    }
}
