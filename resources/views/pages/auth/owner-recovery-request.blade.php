<x-layouts::auth :title="__('Recover owner access')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Recover owner access')" :description="__('For :team owners locked out of both password and Microsoft sign-in.', ['team' => $team->name])" />

        <x-auth-session-status class="text-center" :status="session('status')" />

        @if (session('error'))
            <div class="text-center text-sm font-medium text-red-600">
                {{ session('error') }}
            </div>
        @endif

        <form method="POST" action="{{ route('org.recovery.store', $team) }}" class="flex flex-col gap-6">
            @csrf

            <flux:input
                name="email"
                :label="__('Owner email address')"
                :value="old('email')"
                type="email"
                required
                autofocus
                autocomplete="email"
                data-test="owner-recovery-email"
            />

            <flux:button variant="primary" type="submit" class="w-full" data-test="owner-recovery-request-button">
                {{ __('Send recovery instructions') }}
            </flux:button>
        </form>

        <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-slate-700">
            <span>{{ __('Or, return to') }}</span>
            <flux:link :href="route('org.login', $team)" wire:navigate>{{ __('sign in') }}</flux:link>
        </div>
    </div>
</x-layouts::auth>
