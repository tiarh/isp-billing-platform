<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Terjadi kendala') - Skynet E-Billing</title>
    <style>
        :root { color-scheme: light; font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #f8fafc; color: #0f172a; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 1.5rem; box-sizing: border-box; }
        main { width: min(100%, 32rem); padding: 2rem; border: 1px solid #cbd5e1; border-radius: 12px; background: #fff; box-sizing: border-box; }
        .code { margin: 0 0 .75rem; color: #475569; font: 600 1rem/1.5 ui-monospace, SFMono-Regular, Menlo, monospace; letter-spacing: .08em; }
        h1 { margin: 0; font-size: 1.5rem; line-height: 1.25; }
        p { line-height: 1.6; }
        a { display: inline-block; margin-top: .75rem; color: #0f172a; font-weight: 600; }
        @media (prefers-color-scheme: dark) {
            :root { color-scheme: dark; background: #0f172a; color: #e2e8f0; }
            main { border-color: #334155; background: #1e293b; }
            .code { color: #94a3b8; }
            a { color: #f8fafc; }
        }
    </style>
</head>
<body>
    <main role="main">
        <p class="code">@yield('error_code', '500')</p>
        <h1>@yield('title', 'Terjadi kendala')</h1>
        <p>@yield('message', 'Layanan tidak dapat menyelesaikan permintaan. Silakan coba lagi.') </p>
        <a href="{{ url('/') }}">Kembali ke beranda</a>
    </main>
</body>
</html>
