<!DOCTYPE html>
<html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        <!-- Favicon -->
        <link rel="icon" type="image/x-icon" href="/favicon.ico">
        <link rel="icon" type="image/png" sizes="32x32" href="/logo.png">
        <link rel="apple-touch-icon" href="/logo.png">

        <!-- Theme: apply before paint to avoid flash -->
        <script>
            (function () {
                var t = null;
                try { t = localStorage.getItem('theme'); } catch (e) {}
                var dark = t === 'dark' || (t !== 'light' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                document.documentElement.classList.toggle('dark', dark);
            })();
        </script>

        {{--
          Ziggy: filtered via config/ziggy.php (frontend routes only).
          Without filter, every HTML body inlined ~200 routes (~40KB) — looks like "loading all data".
        --}}
        @routes
        @viteReactRefresh
        @vite(['resources/js/app.tsx'])
        @inertiaHead
    </head>
    {{-- Shell only. Inertia hydrates #app from page props (not "all DB data"). --}}
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
