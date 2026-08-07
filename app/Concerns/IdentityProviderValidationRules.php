<?php

namespace App\Concerns;

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
     * `$enabling` toggles the rules that only apply when the organization is
     * turning Microsoft sign-in on: tenant id, client id, and (only on first
     * enablement — enforced in the action, since "unchanged" can't be expressed
     * as a validation rule) the client secret.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function microsoftIdentityProviderRules(bool $enabling, bool $requireSecret): array
    {
        return [
            'tenantId' => [$enabling ? 'required' : 'nullable', 'string', new MicrosoftTenantId],
            'clientId' => [$enabling ? 'required' : 'nullable', 'string', 'uuid'],
            'newSecretInput' => [$requireSecret ? 'required' : 'nullable', 'string'],
            'enforceSso' => ['boolean'],
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
