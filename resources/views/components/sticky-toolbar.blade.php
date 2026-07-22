{{--
    Docks the slot content directly beneath the app header and global breadcrumbs
    bar (#breadcrumbs-bar in layouts/app.blade.php) once the page scrolls past
    them. Mirrors the sort/filter bar on the All Ideas page (pages/ideas/⚡index.blade.php) —
    kept as a shared component so every page's sticky toolbar behaves identically.
--}}
<div
    x-data="{ top: 0, stuck: false }"
    x-init="top = (document.querySelector('[data-flux-header]')?.getBoundingClientRect().height ?? 0) + (document.querySelector('#breadcrumbs-bar')?.getBoundingClientRect().height ?? 0)"
    x-on:scroll.window="stuck = $el.getBoundingClientRect().top <= top"
    :style="`top: ${top}px`"
    :class="stuck ? 'border-zinc-200 dark:border-zinc-700' : 'border-transparent'"
    {{ $attributes->class('sticky z-10 border-b bg-white transition-colors duration-200 dark:bg-zinc-800') }}
>
    {{ $slot }}
</div>
