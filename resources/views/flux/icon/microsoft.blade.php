@blaze(fold: true)

{{-- Microsoft's official four-square mark, used to identify the Microsoft
     Entra ID (Microsoft 365) sign-in integration. Unlike Heroicons/Lucide
     icons this is a fixed-color brand mark, not a themable outline icon. --}}

@php
$classes = Flux::classes('shrink-0')->add('[:where(&)]:size-6');
@endphp

<svg
    {{ $attributes->class($classes) }}
    data-flux-icon
    xmlns="http://www.w3.org/2000/svg"
    viewBox="0 0 21 21"
    aria-hidden="true"
    data-slot="icon"
>
  <rect x="1" y="1" width="9" height="9" fill="#F25022" />
  <rect x="11" y="1" width="9" height="9" fill="#7FBA00" />
  <rect x="1" y="11" width="9" height="9" fill="#00A4EF" />
  <rect x="11" y="11" width="9" height="9" fill="#FFB900" />
</svg>
