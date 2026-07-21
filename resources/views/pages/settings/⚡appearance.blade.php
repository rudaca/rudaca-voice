<?php

use Livewire\Component;
use Livewire\Attributes\Title;

new #[Title('Appearance settings')] class extends Component {
    //
}; ?>

@push('breadcrumbs')
    <x-breadcrumbs :items="[
        ['label' => __('Settings'), 'href' => route('profile.edit')],
        ['label' => __('Appearance'), 'href' => null],
    ]" />
@endpush

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Appearance settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Appearance')" :subheading="__('Update the appearance settings for your account')">
        <flux:radio.group x-data variant="segmented" x-model="$flux.appearance">
            <flux:radio value="light" icon="sun" class="transition-[background-color,box-shadow,color] duration-200 ease-out">{{ __('Light') }}</flux:radio>
            <flux:radio value="dark" icon="moon" class="transition-[background-color,box-shadow,color] duration-200 ease-out">{{ __('Dark') }}</flux:radio>
            <flux:radio value="system" icon="computer-desktop" class="transition-[background-color,box-shadow,color] duration-200 ease-out">{{ __('System') }}</flux:radio>
        </flux:radio.group>
    </x-pages::settings.layout>
</section>
