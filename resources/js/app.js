// Carry the current dark/light class straight through a wire:navigate swap,
// before the browser paints. Without this, there's a brief window where the
// outgoing <body> is gone and the incoming one hasn't rendered yet, which can
// flash the wrong theme for a frame. This intentionally doesn't call into
// Flux's own appearance API: window.Flux only exposes `applyAppearance` in the
// bootstrap script that runs before Flux's main bundle loads (in <head>).
// Once that bundle loads (`@fluxScripts`, which persists across navigations
// via data-navigate-once), it replaces window.Flux with its Alpine store,
// which has no `applyAppearance` method — calling it here breaks navigation.
document.addEventListener('livewire:navigating', (event) => {
    let isDark = document.documentElement.classList.contains('dark');

    event.detail.onSwap(() => {
        document.documentElement.classList.toggle('dark', isDark);
    });
});

// Drives the sliding active-pill indicator for Flux's segmented radio groups
// (see resources/views/flux/radio, which override Flux's stock views to add
// the `data-segmented-thumb` element and call this via x-init="initSegmentedThumb($el)").
function positionSegmentedThumb(group, animate) {
    let thumb = group.querySelector('[data-segmented-thumb]');
    let checked = group.querySelector('[data-flux-radio-segmented][data-checked]');

    if (! thumb) {
        return;
    }

    if (! checked) {
        thumb.style.opacity = '0';
        return;
    }

    let groupRect = group.getBoundingClientRect();
    let checkedRect = checked.getBoundingClientRect();

    thumb.style.transition = animate ? 'transform 150ms ease, width 150ms ease, height 150ms ease' : 'none';
    thumb.style.opacity = '1';
    thumb.style.width = `${checkedRect.width}px`;
    thumb.style.height = `${checkedRect.height}px`;
    thumb.style.transform = `translate(${checkedRect.left - groupRect.left}px, ${checkedRect.top - groupRect.top}px)`;
}

// Kept off `window` scope for observers: both are only referenced by this
// closure and the `group`/`thumb` elements, so they're eligible for garbage
// collection once a wire:navigate swap detaches the group instead of leaking
// across navigations like a `window`-level listener would.
window.initSegmentedThumb = function (group) {
    positionSegmentedThumb(group, false);

    new MutationObserver(() => positionSegmentedThumb(group, true)).observe(group, {
        attributes: true,
        attributeFilter: ['data-checked'],
        subtree: true,
    });

    new ResizeObserver(() => positionSegmentedThumb(group, false)).observe(group);
};

// Backstop for wire:model(.live)-bound groups (Moderate Comments' and Review
// Queue's status filters): each selection triggers a real Livewire request,
// and if its DOM morph swaps in a fresh group node rather than patching the
// existing one in place, the MutationObserver above ends up watching a
// detached element and silently stops firing. Livewire's `morphed` hook fires
// unconditionally after every update regardless of which case happened, so
// re-run positioning from there too.
document.addEventListener('livewire:init', () => {
    Livewire.hook('morphed', () => {
        document.querySelectorAll('[data-flux-radio-group-segmented]').forEach((group) => {
            positionSegmentedThumb(group, true);
        });
    });
});

// Counts a dashboard stat card's number up from 0 to its target value (see
// resources/views/pages/dashboard.blade.php, called via x-init="initStatCounter($el, target)").
function animateStatValue(el, target) {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        el.textContent = target;
        return;
    }

    let duration = 900;
    let start = null;

    function step(timestamp) {
        if (start === null) {
            start = timestamp;
        }

        let progress = Math.min((timestamp - start) / duration, 1);
        let eased = 1 - Math.pow(1 - progress, 3);
        el.textContent = Math.round(eased * target);

        if (progress < 1) {
            requestAnimationFrame(step);
        } else {
            el.textContent = target;
        }
    }

    requestAnimationFrame(step);
}

// Each stat card carries a wire:key that includes the active tab (see the
// dashboard's For You / By Status toggle), so switching tabs mounts fresh
// elements and re-triggers x-init — replaying the count-up on every switch.
// On the very first load we hold off until the page has fully finished
// loading, so the animation doesn't compete with other page-load work.
function initStatCounter(el, target) {
    if (document.readyState === 'complete') {
        animateStatValue(el, target);
    } else {
        window.addEventListener('load', () => animateStatValue(el, target), {once: true});
    }
}

// Replay any calls the head partial's stub queued before this module ran (see
// resources/views/partials/head.blade.php for why that race exists), then swap
// in the real implementation for the rest of the page's life.
(window.__pendingStatCounters || []).forEach(([el, target]) => initStatCounter(el, target));
delete window.__pendingStatCounters;
window.initStatCounter = initStatCounter;
