<x-layouts::auth :title="__('Log in to :team', ['team' => $team->name])">
    <div
        class="flex flex-col gap-6"
        x-data="{
            email: @js(old('email', '')),
            allowedDomains: @js($allowedDomains ?? []),
            get emailIsValid() {
                return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(this.email);
            },
            get domain() {
                return this.email.includes('@') ? this.email.split('@').pop() : '';
            },
            get domainIsAllowed() {
                return this.domain === ''
                    || this.allowedDomains.length === 0
                    || this.allowedDomains.some((allowed) => allowed.toLowerCase() === this.domain.toLowerCase());
            },
        }"
    >
        <x-auth-header :title="$team->name" :description="__('Sign in to continue to :team', ['team' => $team->name])" />

        <x-auth-session-status class="text-center" :status="session('status')" />

        @if (session('error'))
            <div class="text-center text-sm font-medium text-red-600">
                {{ session('error') }}
            </div>
        @endif

        @if ($showMicrosoft)
            @if ($enforceSso)
                <div>
                    <flux:input
                        x-model="email"
                        x-bind:data-invalid="!domainIsAllowed"
                        :label="__('Email address')"
                        type="email"
                        autofocus
                        autocomplete="email"
                        placeholder="email@ellisontravel.com"
                        data-test="microsoft-email-input"
                    />

                    <p
                        x-show="!domainIsAllowed"
                        x-cloak
                        class="mt-2 text-sm text-red-600 dark:text-red-400"
                        data-test="microsoft-email-domain-error"
                    >
                        {{ __('Email address domain is not allowed.') }}
                    </p>
                </div>
            @endif

            <flux:button
                variant="primary"
                class="w-full"
                type="button"
                x-bind:disabled="@js($enforceSso) && (!emailIsValid || !domainIsAllowed)"
                x-on:click="
                    const url = @js(route('org.login.microsoft', $team)) + (email ? '?email=' + encodeURIComponent(email) : '');
                    const popup = window.open(url, 'microsoft-oauth', 'width=500,height=650');

                    if (!popup) {
                        window.location.href = url;
                    }
                "
                data-test="microsoft-login-button"
            >
                <span class="flex items-center justify-center gap-2">
                    <flux:icon.microsoft class="size-5" />
                    {{ __('Continue with Microsoft') }}
                </span>
            </flux:button>

            @unless ($enforceSso)
                <flux:separator :text="__('or')" />
            @endunless
        @endif

        @unless ($enforceSso)
            <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-6">
                @csrf

                <flux:input
                    name="email"
                    x-model="email"
                    :label="__('Email address')"
                    type="email"
                    required
                    autofocus
                    autocomplete="email"
                    placeholder="email@ellisontravel.com"
                />

                <div class="relative">
                    <flux:input
                        name="password"
                        :label="__('Password')"
                        type="password"
                        required
                        autocomplete="current-password"
                        :placeholder="__('Password')"
                        viewable
                    />

                    @if (Route::has('password.request'))
                        <flux:link class="absolute top-0 text-sm end-0" :href="route('password.request')" wire:navigate>
                            {{ __('Forgot your password?') }}
                        </flux:link>
                    @endif
                </div>

                <flux:checkbox name="remember" :label="__('Remember me')" :checked="old('remember')" />

                <div class="flex items-center justify-end">
                    <flux:button variant="primary" type="submit" class="w-full" data-test="login-button">
                        {{ __('Log in') }}
                    </flux:button>
                </div>
            </form>
        @endunless
    </div>
</x-layouts::auth>
