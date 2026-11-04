{{--
    General isolation notice (/isolir).

    Opened inside phone captive-portal mini-browsers (Android "Masuk ke
    jaringan", iOS CNA) and normal browsers of isolated PPPoE customers. Rules:
    - pure HTML + inline CSS: no JS, no web fonts, one ~2 KB logo; must render
      fast over an allow-listed connection in a limited mini-browser;
    - no customer identity, billing records, router data or bank accounts
      (anyone behind the redirect sees the same page); only company contact
      info configured in Settings -> Portal Isolir.
--}}
@php
    $hasWa = $waLink !== null;
    $hasOffice = $officeAddress !== '' || $officeHours !== '';
@endphp
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#f5f6f8" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#111418" media="(prefers-color-scheme: dark)">
    <title>Layanan internet diisolir - {{ $companyName }}</title>
    <link rel="icon" type="image/png" href="/images/skynet-mark-96.png">
    <style>
        :root {
            --bg: #f5f6f8;
            --surface: #ffffff;
            --ink: #14181f;
            --muted: #535b68;
            --line: #dcdfe5;
            --accent: #1d4ed8;
            --status-bg: #fdf3e1;
            --status-ink: #7a4300;
            --status-line: #f0d5a6;
            --wa: #0f7a40;
            --wa-ink: #ffffff;
            --btn2-bg: #ffffff;
            --btn2-ink: #14181f;
            --step: #e8ecf3;
            --step-ink: #1f2a3d;
            color-scheme: light dark;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #111418;
                --surface: #1a1e24;
                --ink: #eceef2;
                --muted: #a7aeba;
                --line: #2e343d;
                --accent: #8fb0ff;
                --status-bg: #33260f;
                --status-ink: #f6cf8e;
                --status-line: #54401c;
                --wa: #1fa855;
                --wa-ink: #04170b;
                --btn2-bg: #1a1e24;
                --btn2-ink: #eceef2;
                --step: #2a313c;
                --step-ink: #e3e8f0;
            }
        }
        *, *::before, *::after { box-sizing: border-box; }
        html { -webkit-text-size-adjust: 100%; text-size-adjust: 100%; }
        body {
            margin: 0;
            min-height: 100vh;
            background: var(--bg);
            color: var(--ink);
            font: 400 16px/1.55 system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            padding: 16px 16px 28px;
            padding-top: max(16px, env(safe-area-inset-top));
            padding-bottom: max(28px, env(safe-area-inset-bottom));
        }
        .wrap { max-width: 32rem; margin: 0 auto; }
        a { color: var(--accent); }
        a:focus-visible { outline: 3px solid var(--accent); outline-offset: 2px; }

        .brand { display: flex; align-items: center; gap: 10px; padding: 4px 2px 16px; }
        .brand img { width: 32px; height: 32px; flex: none; border-radius: 6px; }
        .brand span { font-weight: 600; font-size: 15px; }

        .card { background: var(--surface); border: 1px solid var(--line); border-radius: 12px; padding: 20px; }
        .card + .card { margin-top: 12px; }

        .status { display: inline-block; margin: 0; padding: 3px 10px; border: 1px solid var(--status-line); border-radius: 6px; background: var(--status-bg); color: var(--status-ink); font-size: 13px; font-weight: 600; }
        h1 { margin: 12px 0 8px; font-size: 24px; line-height: 1.25; font-weight: 700; letter-spacing: -.01em; }
        .lead { margin: 0; color: var(--muted); }
        .lead.next { margin-top: 10px; color: var(--ink); }

        h2 { margin: 0 0 12px; font-size: 17px; line-height: 1.3; font-weight: 650; }
        .sub { margin: -6px 0 14px; color: var(--muted); font-size: 15px; }

        .btn { display: block; min-height: 48px; padding: 12px 16px; border-radius: 10px; font-weight: 600; font-size: 16px; line-height: 24px; text-align: center; text-decoration: none; }
        .btn + .btn { margin-top: 10px; }
        .btn-wa { background: var(--wa); color: var(--wa-ink); border: 1px solid var(--wa); }
        .btn-tel { background: var(--btn2-bg); color: var(--btn2-ink); border: 1px solid var(--line); }
        .number { margin: 14px 0 0; padding: 12px 14px; border: 1px solid var(--line); border-radius: 10px; }
        .number small { display: block; color: var(--muted); font-size: 13px; }
        .number strong { display: block; font-size: 20px; font-weight: 650; letter-spacing: .02em; font-variant-numeric: tabular-nums; user-select: all; -webkit-user-select: all; }
        .hint { margin: 10px 0 0; color: var(--muted); font-size: 14px; }

        ol.steps { margin: 0; padding: 0; list-style: none; }
        ol.steps li { display: flex; gap: 12px; }
        ol.steps li + li { margin-top: 14px; }
        .n { flex: none; width: 28px; height: 28px; border-radius: 50%; background: var(--step); color: var(--step-ink); font-size: 14px; font-weight: 700; line-height: 28px; text-align: center; }
        ol.steps b { display: block; font-weight: 600; }
        ol.steps p { margin: 2px 0 0; color: var(--muted); font-size: 15px; }

        dl { margin: 0; }
        dt { color: var(--muted); font-size: 13px; }
        dd { margin: 2px 0 0; }
        dd + dt { margin-top: 12px; }

        footer { margin: 18px 4px 0; color: var(--muted); font-size: 13px; }
        footer p { margin: 0 0 6px; }
    </style>
</head>
<body>
<div class="wrap">
    <header class="brand">
        <img src="/images/skynet-mark-96.png" width="32" height="32" alt="">
        <span>{{ $companyName }}</span>
    </header>

    <main>
        <section class="card" aria-labelledby="isolir-title">
            <p class="status">Status layanan: diisolir</p>
            <h1 id="isolir-title">Internet Anda sedang diisolir</h1>
            <p class="lead">
                Layanan internet Anda sedang diisolir karena tagihan belum tercatat lunas atau masa aktif
                layanan telah berakhir. Akses internet akan dibuka kembali setelah pembayaran tercatat.
            </p>
            @if ($hasWa || $hasOffice)
                <p class="lead next">
                    @if ($hasWa && $hasOffice)
                        Untuk informasi tagihan dan pembayaran, silakan chat CS kami atau datang langsung ke kantor kami.
                    @elseif ($hasWa)
                        Untuk informasi tagihan dan pembayaran, silakan chat CS kami.
                    @else
                        Untuk informasi tagihan dan pembayaran, silakan datang langsung ke kantor kami.
                    @endif
                </p>
            @endif
        </section>

        @if ($hasWa)
            <section class="card" aria-labelledby="cs-title">
                <h2 id="cs-title">Hubungi Customer Service</h2>
                <p class="sub">Tanyakan jumlah tagihan dan cara pembayaran. Sebutkan nama dan alamat pemasangan Anda.</p>
                <a class="btn btn-wa" href="{{ $waLink }}" rel="noopener">Chat CS via WhatsApp</a>
                <a class="btn btn-tel" href="{{ $telLink }}">Telepon CS</a>
                <p class="number">
                    <small>Nomor CS</small>
                    <strong>{{ $waAdmin }}</strong>
                </p>
                <p class="hint">WhatsApp tidak terbuka? Matikan WiFi lalu gunakan data seluler, atau catat nomor di atas.</p>
            </section>
        @endif

        <section class="card" aria-labelledby="steps-title">
            <h2 id="steps-title">Cara mengaktifkan kembali</h2>
            <ol class="steps">
                <li>
                    <span class="n" aria-hidden="true">1</span>
                    <div>
                        <b>Hubungi CS</b>
                        <p>{{ $hasWa ? 'Lewat WhatsApp atau telepon ke nomor di atas' : 'Hubungi CS kami' }} untuk memastikan jumlah tagihan.</p>
                    </div>
                </li>
                <li>
                    <span class="n" aria-hidden="true">2</span>
                    <div>
                        <b>Lakukan pembayaran</b>
                        <p>{{ $hasOffice ? 'Bayar langsung di kantor kami, atau ikuti cara pembayaran yang diberikan CS.' : 'Ikuti cara pembayaran yang diberikan CS.' }}</p>
                    </div>
                </li>
                <li>
                    <span class="n" aria-hidden="true">3</span>
                    <div>
                        <b>Internet aktif kembali otomatis</b>
                        <p>Setelah pembayaran tercatat, akses pulih tanpa perlu kunjungan teknisi. Jika halaman ini masih muncul setelah beberapa menit, matikan lalu nyalakan kembali modem/router Anda.</p>
                    </div>
                </li>
            </ol>
        </section>

        @if ($hasOffice)
            <section class="card" aria-labelledby="office-title">
                <h2 id="office-title">Kantor layanan</h2>
                <dl>
                    @if ($officeAddress !== '')
                        <dt>Alamat</dt>
                        <dd>{{ $officeAddress }}</dd>
                    @endif
                    @if ($officeHours !== '')
                        <dt>Jam buka</dt>
                        <dd>{{ $officeHours }}</dd>
                    @endif
                </dl>
            </section>
        @endif
    </main>

    <footer>
        <p>Halaman ini muncul otomatis karena akses internet pada sambungan ini sedang dibatasi. Sudah membayar tetapi halaman ini masih muncul? Hubungi CS kami.</p>
        <p>&copy; {{ now()->year }} {{ $companyName }}</p>
    </footer>
</div>
</body>
</html>
