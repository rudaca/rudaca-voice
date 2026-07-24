<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

@fonts

<script>
    // The fluxScripts directive (near the end of the body) renders a classic,
    // render-blocking script that bundles Alpine + Livewire and starts Alpine as
    // soon as it runs — before this deferred `type="module"` app.js below has
    // executed. That means Alpine can call `initStatCounter` (defined in app.js)
    // on the initial page's dashboard stat cards before it exists. This stub
    // queues any such early calls, keeping the element reference and target, so
    // app.js can replay them once it loads instead of Alpine throwing a
    // ReferenceError and leaving that card stuck at 0.
    window.initStatCounter = function (el, target) {
        (window.__pendingStatCounters ??= []).push([el, target]);
    };
</script>

@vite(['resources/css/app.css', 'resources/js/app.js'])
<script>
    if (window.localStorage.getItem('flux.appearance.seeded') === null) {
        window.localStorage.setItem('flux.appearance.seeded', '1');

        if (window.localStorage.getItem('flux.appearance') === null) {
            window.localStorage.setItem('flux.appearance', 'light');
        }
    }
</script>
@fluxAppearance
