<?php

use App\Enums\IdentityProvider;
use App\Models\Team;
use App\Models\TeamIdentityProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Microsoft SSO Setup Guide')] class extends Component
{
    public function mount(): void
    {
        Gate::authorize('viewAny', [TeamIdentityProvider::class, $this->team]);
    }

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    #[Computed]
    public function redirectUrl(): string
    {
        return IdentityProvider::Microsoft->redirectUrl();
    }

    public function render()
    {
        return $this->view();
    }
}; ?>

<section class="mx-auto w-full space-y-8 px-6 pb-7 lg:px-8" data-test="microsoft-sso-guide">
    <div>
        <flux:link :href="route('ideas.settings', ['current_team' => $this->team->slug, 'tab' => 'authentication'])" wire:navigate class="inline-flex items-center gap-1 text-sm">
            <flux:icon.arrow-left class="size-4" />
            {{ __('Back to Authentication settings') }}
        </flux:link>
    </div>

    <div>
        <flux:heading size="xl" class="font-bold">{{ __('Microsoft Entra SSO setup') }}</flux:heading>
        <flux:subheading>
            {{ __('A walkthrough for connecting your organization\'s Microsoft Entra ID tenant to :app.', ['app' => config('app.name')]) }}
        </flux:subheading>
    </div>

    <div class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:text>
            {{ __('Every organization — and every installation — configures its own Microsoft app registration. There is no shared or default app registration. If you are self-hosting, each environment (staging, production, a local dev instance) needs its own app registration too, because the redirect URI below is different for each one.') }}
        </flux:text>
    </div>

    <div class="space-y-4">
        <flux:heading size="lg" class="font-bold">{{ __('Before you start') }}</flux:heading>
        <ul class="list-disc space-y-2 ps-5 text-sm text-slate-700 dark:text-slate-300">
            <li>{{ __('Global Administrator or Application Administrator access to your organization\'s Microsoft Entra tenant.') }}</li>
            <li>{{ __('Owner, or an authentication-management permission, on the organization you\'re configuring.') }}</li>
            <li>{{ __(':app reachable over HTTPS in production. Microsoft Entra accepts an http://localhost redirect URI for local development, but rejects a non-HTTPS redirect URI for any other host.', ['app' => config('app.name')]) }}</li>
        </ul>
    </div>

    <div class="space-y-4">
        <flux:heading size="lg" class="font-bold">{{ __('Setup steps') }}</flux:heading>
        <ol class="list-decimal space-y-4 ps-5 text-sm text-slate-700 dark:text-slate-300">
            <li>
                <strong>{{ __('Open the Microsoft Entra admin center') }}</strong>
                {{ __('at entra.microsoft.com, signed in as an admin for the tenant you want members to sign in with.') }}
            </li>
            <li>
                <strong>{{ __('Create a new app registration') }}</strong>
                — {{ __('go to Identity → Applications → App registrations → New registration. Give it a recognizable name, e.g. ":name — Production".', ['name' => config('app.name')]) }}
            </li>
            <li>
                <strong>{{ __('Configure supported account types as single-tenant') }}</strong>
                — {{ __('select "Accounts in this organizational directory only". A multi-tenant or personal-account app registration will not match what the connection test validates against, and it will fail with a tenant mismatch.') }}
            </li>
            <li>
                <strong>{{ __('Add the redirect URI shown below') }}</strong>
                — {{ __('in the app registration, go to Authentication → Add a platform → Web, and paste in the exact URL shown in the box below.') }}

                <div class="mt-2 rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2 font-mono text-xs break-all dark:border-zinc-700 dark:bg-zinc-800" data-test="guide-redirect-url">
                    {{ $this->redirectUrl }}
                </div>
            </li>
            <li>
                <strong>{{ __('Create a client secret, not a certificate') }}</strong>
                — {{ __('go to Certificates & secrets → Client secrets → New client secret. Certificates are not supported; only client secrets are.') }}
            </li>
            <li>
                <strong>{{ __('Copy the tenant ID (Directory/Tenant ID)') }}</strong>
                — {{ __('found on the app registration\'s Overview page. This identifies your Microsoft Entra directory, not the application itself.') }}
            </li>
            <li>
                <strong>{{ __('Copy the application/client ID') }}</strong>
                — {{ __('also on the Overview page, listed as Application (client) ID. This identifies the app registration, not the directory.') }}
            </li>
            <li>
                <strong>{{ __('Copy the client secret\'s value before leaving the secrets screen') }}</strong>
                — {{ __('after creating a client secret, Microsoft Entra shows a Secret ID and a Value. You need the Value. It is only ever shown once — if you navigate away without copying it, you must create a new secret.') }}
            </li>
            <li>
                <strong>{{ __('Enter these values under Organization Settings → Authentication') }}</strong>
                — {{ __('tenant ID, client ID, and the client secret value from the steps above.') }}
            </li>
            <li><strong>{{ __('Save configuration.') }}</strong></li>
            <li>
                <strong>{{ __('Run "Test Connection."') }}</strong>
                {{ __('This starts a real Microsoft sign-in using the values you just saved and confirms the tenant Microsoft returns actually matches the tenant ID you configured. A successful test records the date, time, and the administrator who ran it.') }}
            </li>
            <li>
                <strong>{{ __('Enable auto-provisioning only if desired') }}</strong>
                — {{ __('lets Microsoft accounts that don\'t already have an account get one created automatically on first sign-in, with a default role you choose.') }}
            </li>
            <li>
                <strong>{{ __('Enable Microsoft-only enforcement only after a successful test') }}</strong>
                — {{ __('this is blocked until a connection test has succeeded for the currently saved configuration, specifically to prevent an organization from locking itself out with bad credentials.') }}
            </li>
        </ol>
    </div>

    <div class="space-y-4">
        <flux:heading size="lg" class="font-bold">{{ __('Key terms, explained') }}</flux:heading>
        <ul class="list-disc space-y-3 ps-5 text-sm text-slate-700 dark:text-slate-300">
            <li>
                <strong>{{ __('Tenant ID vs. Application (client) ID') }}</strong>
                — {{ __('the tenant ID identifies your organization\'s Microsoft Entra directory; the application ID identifies this specific app registration within that directory. Both are on the Overview page, right next to each other.') }}
            </li>
            <li>
                <strong>{{ __('Client secret value vs. client secret ID') }}</strong>
                — {{ __('the secret ID is just a label used to tell secrets apart; it is not a credential. The secret value is the actual credential — only the value is ever entered here, and Microsoft Entra only shows it once.') }}
            </li>
            <li>
                <strong>{{ __('Redirect URI') }}</strong>
                — {{ __('the URL Microsoft redirects back to after sign-in. It must match exactly what\'s registered in the app registration, and it is specific to the environment this installation runs in.') }}
            </li>
        </ul>
    </div>

    <div class="space-y-4">
        <flux:heading size="lg" class="font-bold">{{ __('Troubleshooting') }}</flux:heading>
        <div class="overflow-x-auto rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Symptom') }}</flux:table.column>
                    <flux:table.column>{{ __('Likely cause') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    <flux:table.row>
                        <flux:table.cell>{{ __('"Redirect URI mismatch" or Microsoft shows AADSTS50011') }}</flux:table.cell>
                        <flux:table.cell>{{ __('The redirect URI in the app registration doesn\'t exactly match the one shown above — check for a trailing slash or http vs https mismatch.') }}</flux:table.cell>
                    </flux:table.row>
                    <flux:table.row>
                        <flux:table.cell>{{ __('Connection test fails with a tenant mismatch') }}</flux:table.cell>
                        <flux:table.cell>{{ __('The app registration is multi-tenant, or the tenant ID entered doesn\'t match the directory the app registration lives in.') }}</flux:table.cell>
                    </flux:table.row>
                    <flux:table.row>
                        <flux:table.cell>{{ __('Microsoft shows a consent/permissions prompt or AADSTS65001') }}</flux:table.cell>
                        <flux:table.cell>{{ __('An admin hasn\'t granted consent for the app registration yet. Sign in once as a Global Administrator to grant tenant-wide consent.') }}</flux:table.cell>
                    </flux:table.row>
                    <flux:table.row>
                        <flux:table.cell>{{ __('Connection test fails with an authentication/credential error') }}</flux:table.cell>
                        <flux:table.cell>{{ __('The client secret has expired or was rotated in Microsoft Entra without updating it here. Create a new secret and re-save, then re-run the connection test.') }}</flux:table.cell>
                    </flux:table.row>
                    <flux:table.row>
                        <flux:table.cell>{{ __('"Configuration incomplete" status never clears') }}</flux:table.cell>
                        <flux:table.cell>{{ __('One of tenant ID, client ID, or client secret is still missing or blank.') }}</flux:table.cell>
                    </flux:table.row>
                </flux:table.rows>
            </flux:table>
        </div>
    </div>

    <div class="space-y-4">
        <flux:heading size="lg" class="font-bold">{{ __('Hosted vs. self-hosted deployments') }}</flux:heading>
        <ul class="list-disc space-y-2 ps-5 text-sm text-slate-700 dark:text-slate-300">
            <li>{{ __('Hosted: your organization still creates and owns its own app registration — there is no shared one on your behalf. The redirect URI above points at your hosted instance\'s domain.') }}</li>
            <li>{{ __('Self-hosted: the redirect URI is derived from your instance\'s configured application URL. Multiple environments (staging, production, or several customer instances) each need their own app registration, since each has its own redirect URI.') }}</li>
            <li>{{ __('Production HTTPS is required — Microsoft Entra will reject a non-localhost redirect URI that isn\'t HTTPS.') }}</li>
        </ul>
    </div>
</section>
