<x-layouts::auth :title="__('Enter recovery code')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Enter recovery code')" :description="__('Check the email we just sent for a one-time code.')" />

        @if (session('error'))
            <div class="text-center text-sm font-medium text-red-600">
                {{ session('error') }}
            </div>
        @endif

        <form method="POST" action="{{ route('org.recovery.confirm', ['team' => $team, 'token' => $token]) }}" class="flex flex-col gap-6">
            @csrf

            <flux:input
                name="code"
                :label="__('One-time code')"
                required
                autofocus
                autocomplete="one-time-code"
                inputmode="numeric"
                maxlength="6"
                data-test="owner-recovery-code"
            />

            <flux:button variant="primary" type="submit" class="w-full" data-test="owner-recovery-confirm-button">
                {{ __('Verify and continue') }}
            </flux:button>
        </form>
    </div>
</x-layouts::auth>
