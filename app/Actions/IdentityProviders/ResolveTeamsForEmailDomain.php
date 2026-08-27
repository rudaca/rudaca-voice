<?php

namespace App\Actions\IdentityProviders;

use App\Enums\IdentityProvider;
use App\Models\Team;
use App\Models\TeamIdentityProvider;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class ResolveTeamsForEmailDomain
{
    /**
     * Find every organization whose Microsoft sign-in is configured to
     * accept this email's domain, for the common (non-org-scoped) login
     * page — which has no team in the URL to consult a single
     * organization's allow-list against.
     *
     * A team with no `allowed_domains` configured is deliberately excluded
     * rather than treated as "any domain": on the org-scoped login page an
     * empty allow-list means "don't restrict sign-in for this team, whose
     * identity is already known from the URL", but here there is no team
     * yet — matching every such team on every domain would make this
     * lookup meaningless.
     *
     * @return Collection<int, Team>
     */
    public function handle(string $email): Collection
    {
        $domain = Str::lower(Str::after($email, '@'));

        if (blank($domain)) {
            return new Collection;
        }

        return TeamIdentityProvider::query()
            ->provider(IdentityProvider::Microsoft)
            ->where('enabled', true)
            ->with('team')
            ->get()
            ->filter(fn (TeamIdentityProvider $identityProvider) => $identityProvider->isConfigurable()
                && filled($identityProvider->allowed_domains)
                && in_array($domain, array_map(Str::lower(...), $identityProvider->allowed_domains), true))
            ->map(fn (TeamIdentityProvider $identityProvider) => $identityProvider->team)
            ->unique('id')
            ->values();
    }
}
