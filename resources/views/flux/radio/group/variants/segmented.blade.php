@blaze(fold: true, unsafe: [
    // flux:with-field props
    'name', 'label', 'badge',
    'description', 'description:trailing',
    'label:badge', 'label:aside', 'label:trailing',
    'error:name', 'error:bag', 'error:message', 'error:icon', 'error:nested', 'error:deep',
])


@props([
    'name' => null,
    'variant' => null,
    'size' => null,
])

@php
// We only want to show the name attribute on the checkbox if it has been set
// manually, but not if it has been set from the wire:model attribute...
$showName = isset($name);

if (! isset($name)) {
    $name = $attributes->whereStartsWith('wire:model')->first();
}

$classes = Flux::classes()
    ->add('relative block flex p-1')
    ->add('rounded-lg bg-zinc-800/5 dark:bg-white/10')
    ->add($size === 'sm' ? 'h-8 py-[3px] px-[3px]' : 'h-10 p-1')
    ->add($size === 'sm' ? '-my-px h-[calc(2rem+2px)]' : '')
    ;
@endphp

<flux:with-field :$attributes>
    <ui-radio-group
        {{ $attributes->class($classes) }}
        @if($showName) name="{{ $name }}" @endif
        data-flux-radio-group-segmented
        x-init="initSegmentedThumb($el)"
    >
        {{--
            Sliding active-state indicator. This overrides Flux's stock segmented
            radio group (see resources/views/flux/radio) so the active pill slides
            between options instead of popping instantly. Position/size are driven
            by initSegmentedThumb() in resources/js/app.js, which mirrors the
            currently [data-checked] radio's rect onto this element via a
            MutationObserver (selection changes) and a ResizeObserver (layout changes).

            wire:ignore: for groups bound with wire:model(.live) (e.g. Moderate
            Comments' status filter), every selection triggers a real Livewire
            request, and its DOM morph would otherwise reset this element's
            JS-owned inline style back to the server-rendered "opacity: 0" right
            before our observer repositions it — collapsing the transition into an
            instant snap. wire:ignore keeps this subtree untouched by that morph.
        --}}
        <div
            data-segmented-thumb
            wire:ignore
            class="pointer-events-none absolute left-0 top-0 rounded-md border border-zinc-300 bg-white shadow-xs dark:border-white/25 dark:bg-white/20"
            style="opacity: 0"
        ></div>

        {{ $slot }}
    </ui-radio-group>
</flux:with-field>
