<x-layouts::auth :title="__('Log in')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Log in to your account')" :description="__('Enter your email and password below to log in')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        @if (session('error'))
            <div class="text-center text-sm font-medium text-red-600">
                {{ session('error') }}
            </div>
        @endif

        @if ($teamInvitation)
            <x-team-invitation-alert :invitation="$teamInvitation" :action="__('Log in')" />
        @endif

        @if ($showMicrosoft)
            <form
                method="POST"
                action="{{ route('login.microsoft.resolve') }}"
                class="flex flex-col gap-3"
                x-data="{ email: @js(old('email', '')) }"
            >
                @csrf

                <flux:input
                    name="email"
                    :label="__('Work email address')"
                    type="email"
                    x-model="email"
                    required
                    autocomplete="email"
                    placeholder="email@ellisontravel.com"
                    data-test="microsoft-login-email"
                />

                <flux:button
                    variant="primary"
                    type="submit"
                    class="w-full"
                    data-test="microsoft-login-button"
                    :loading="false"
                    x-bind:disabled="!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)"
                >
                    <span class="flex items-center justify-center gap-2">
                        <flux:icon.microsoft class="size-5" />
                        {{ __('Continue with Microsoft') }}
                    </span>
                </flux:button>
            </form>

            <flux:separator :text="__('or')" />
        @endif

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-6">
            @csrf

            <!-- Email Address -->
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

            <!-- Password -->
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

            <!-- Remember Me -->
            <flux:checkbox name="remember" :label="__('Remember me')" :checked="old('remember')" />

            <div class="flex items-center justify-end">
                <flux:button variant="primary" type="submit" class="w-full" data-test="login-button">
                    {{ __('Log in') }}
                </flux:button>
            </div>
        </form>

    </div>
</x-layouts::auth>
