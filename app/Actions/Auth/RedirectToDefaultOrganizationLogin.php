<?php

namespace App\Actions\Auth;

use App\Models\Team;
use Illuminate\Support\Facades\Log;

class RedirectToDefaultOrganizationLogin
{
    /**
     * Get the default organization's login URL for this deployment, if configured.
     *
     * Single-tenant deployments can set `organizations.default_slug` to send
     * the common login page straight to that organization's own login page.
     * Returns null when unset or when the configured slug doesn't match a
     * real organization, so the caller falls back to the generic login page
     * instead of redirect-looping or erroring.
     */
    public function handle(): ?string
    {
        $slug = config('organizations.default_slug');

        if (! is_string($slug) || $slug === '') {
            return null;
        }

        $team = Team::where('slug', $slug)->first();

        if (! $team) {
            Log::warning('default_organization_slug_invalid', ['slug' => $slug]);

            return null;
        }

        return route('org.login', $team);
    }
}
