<x-layouts::app.sidebar :title="$title ?? null">
    <div
        id="breadcrumbs-bar"
        x-data="{ scrolled: false }"
        x-bind:style="{ position: 'sticky', top: $el.offsetTop + 'px' }"
        x-on:scroll.window="scrolled = window.scrollY > 4"
        x-bind:class="scrolled ? 'border-zinc-200 dark:border-white/10' : 'border-transparent'"
        class="z-10 mx-auto flex w-full items-center border-b bg-white px-6 pt-3 pb-3 transition-colors duration-200 dark:bg-zinc-800 lg:px-8"
    >@stack('breadcrumbs')</div>

    <flux:main >
        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
