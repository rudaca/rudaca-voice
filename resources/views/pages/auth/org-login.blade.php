<x-layouts::auth :title="__('Log in to :team', ['team' => $team->name])">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="$team->name" :description="__('Sign in to continue to :team', ['team' => $team->name])" />

        <x-auth-session-status class="text-center" :status="session('status')" />

        @if (session('error'))
            <div class="text-center text-sm font-medium text-red-600">
                {{ session('error') }}
            </div>
        @endif

        @if ($showMicrosoft)
            <flux:button variant="primary" class="w-full" :href="route('org.login.microsoft', $team)" data-test="microsoft-login-button">
                {{ __('Continue with Microsoft') }}
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
                    :label="__('Email address')"
                    :value="old('email')"
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
