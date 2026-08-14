<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Continuing…') }}</title>
    <noscript>
        <meta http-equiv="refresh" content="0;url={{ $targetUrl }}">
    </noscript>
    <style>
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            margin: 0;
            font-family: system-ui, sans-serif;
            color: #52525b;
        }
    </style>
</head>
<body>
    <p>
        {{ __('Continuing…') }}
        <a href="{{ $targetUrl }}">{{ __('Click here if this window does not close automatically.') }}</a>
    </p>

    <script>
        (function () {
            var targetUrl = @json($targetUrl);

            // Opened as a popup: hand the result to the window that opened
            // us and close ourselves, rather than showing the destination
            // page inside the popup itself.
            if (window.opener && !window.opener.closed) {
                try {
                    window.opener.location.href = targetUrl;
                    window.close();

                    return;
                } catch (e) {
                    // Opener no longer reachable (navigated cross-origin, or
                    // closed mid-flight) — fall through to a normal redirect.
                }
            }

            // No popup opener (popups were blocked, or this link was opened
            // in the same tab) — just continue on here instead.
            window.location.replace(targetUrl);
        })();
    </script>
</body>
</html>
