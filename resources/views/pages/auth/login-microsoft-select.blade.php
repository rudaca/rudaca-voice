<x-layouts::auth :title="__('Choose an organization')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Choose an organization')" :description="__('Your email matches more than one organization. Select which one to sign in to.')" />

        <div class="flex flex-col gap-3">
            @foreach ($teams as $team)
                <flux:button
                    :href="route('org.login.microsoft', ['team' => $team, 'email' => $email])"
                    variant="filled"
                    class="w-full justify-start"
                    data-test="microsoft-select-team-{{ $team->slug }}"
                >
                    {{ $team->name }}
                </flux:button>
            @endforeach
        </div>

        <flux:link :href="route('login')" wire:navigate class="text-center text-sm">
            {{ __('Back to log in') }}
        </flux:link>
    </div>
</x-layouts::auth>
