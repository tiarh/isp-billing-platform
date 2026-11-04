{{--
    Site-branded isolation notice (/isolir for a site variant, see
    App\Services\IsolirSiteVariants). Same rules as isolir/general: pure HTML +
    inline CSS, no JS, no web fonts, only one small static logo, no customer
    data. All text values come from the `isolir_site_variants` setting.
--}}
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="light">
    <meta name="theme-color" content="#0b3a8c">
    <title>Akun diisolir - {{ $brand }}</title>
    <style>
        :root { --blue: #0b3a8c; --blue2: #1558c9; --red: #d6121f; --red2: #a30c17; --ink: #0f1b33; --bg: #eef4ff; color-scheme: light; }
        *, *::before, *::after { box-sizing: border-box; }
        html { -webkit-text-size-adjust: 100%; text-size-adjust: 100%; }
        body { margin: 0; min-height: 100vh; display: flex; flex-direction: column; background: linear-gradient(#fff, var(--bg) 60%, #dbe8ff); color: var(--ink); font: 400 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; }
        .wrap { width: 100%; max-width: 30rem; margin: 0 auto; padding: 14px 16px 20px; flex: 1; }
        .logo { display: block; margin: 0 auto 8px; width: 200px; max-width: 60%; height: auto; }
        .brand { margin: 0 0 8px; text-align: center; font-size: 22px; font-weight: 800; color: var(--blue); }
        .hero { position: relative; margin: 6px 0 0; padding: 14px 14px 12px 84px; min-height: 96px; color: #fff; background: linear-gradient(135deg, var(--red), var(--red2)); border-radius: 14px 14px 0 0; }
        .hero svg { position: absolute; left: 12px; top: 50%; margin-top: -29px; width: 58px; height: 58px; }
        .hero h1 { margin: 0; font-size: 26px; line-height: 1.1; font-weight: 900; text-transform: uppercase; letter-spacing: .01em; }
        .why { margin: 0; padding: 8px 12px; text-align: center; background: var(--red2); color: #fff; font-size: 14px; font-weight: 700; text-transform: uppercase; border-radius: 0 0 14px 14px; }
        .msg { margin: 16px 4px 0; text-align: center; }
        .msg b { display: block; font-size: 17px; line-height: 1.35; }
        .msg b em { font-style: normal; color: var(--red); }
        .msg p { margin: 8px 0 0; font-size: 15px; }
        .cards { display: grid; gap: 12px; margin-top: 16px; }
        .card { border-radius: 14px; overflow: hidden; box-shadow: 0 2px 8px rgba(11, 58, 140, .18); }
        .cs { background: linear-gradient(135deg, var(--blue), var(--blue2)); color: #fff; padding: 14px; }
        .bank { background: #fff; border: 2px solid var(--blue); }
        .card h2 { margin: 0 0 6px; font-size: 14px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; }
        .bank h2 { margin: 0; padding: 8px 14px; background: var(--blue); color: #fff; }
        .num { display: block; margin: 4px 0 10px; font-size: 28px; line-height: 1.15; font-weight: 800; font-variant-numeric: tabular-nums; color: #fff; text-decoration: none; word-break: break-all; }
        .btns { display: flex; gap: 8px; margin: 0 0 10px; }
        .btn { flex: 1; min-height: 46px; padding: 11px 8px; border-radius: 10px; text-align: center; font-weight: 700; font-size: 16px; text-decoration: none; background: #fff; color: var(--blue); }
        .btn.wa { background: #12a150; color: #fff; }
        .btn:focus-visible, .num:focus-visible { outline: 3px solid #ffd54a; outline-offset: 2px; }
        .help { margin: 0; padding-top: 8px; border-top: 1px solid rgba(255, 255, 255, .45); font-size: 14px; }
        .bank div { padding: 12px 14px 14px; }
        .bank .bn { margin: 0; font-size: 22px; font-weight: 900; font-style: italic; color: var(--blue2); }
        .bank .ac { margin: 2px 0 8px; font-size: 30px; line-height: 1.15; font-weight: 800; font-variant-numeric: tabular-nums; word-break: break-all; user-select: all; -webkit-user-select: all; }
        .bank .ho { margin: 0; padding-top: 8px; border-top: 1px solid #b9c8e6; font-weight: 700; }
        .off { display: flex; align-items: center; gap: 12px; margin-top: 16px; padding: 12px 16px; border-radius: 999px; background: linear-gradient(135deg, var(--red), var(--red2)); color: #fff; font-weight: 800; text-transform: uppercase; font-size: 15px; line-height: 1.25; box-shadow: 0 2px 8px rgba(163, 12, 23, .3); }
        .off svg { flex: none; width: 36px; height: 36px; }
        footer { padding: 14px 16px 18px; text-align: center; color: #fff; background: linear-gradient(135deg, #072a69, var(--blue)); }
        footer b { display: block; font-size: 17px; font-style: italic; letter-spacing: .02em; }
        footer small { display: block; margin-top: 2px; font-size: 12px; letter-spacing: .12em; text-transform: uppercase; opacity: .9; }
    </style>
</head>
<body>
<div class="wrap">
    @if ($logo !== null)
        <img class="logo" src="{{ $logo }}" width="200" height="128" alt="{{ $brand }}">
    @else
        <p class="brand">{{ $brand }}</p>
    @endif

    <main>
        <section aria-labelledby="isolir-title">
            <div class="hero">
                <svg viewBox="0 0 64 64" aria-hidden="true"><circle cx="32" cy="32" r="31" fill="#fff"/><path d="M22 29v-5a10 10 0 0 1 20 0v5" fill="none" stroke="#d6121f" stroke-width="5" stroke-linecap="round"/><rect x="17" y="28" width="30" height="24" rx="5" fill="#d6121f"/><circle cx="32" cy="38" r="3.5" fill="#fff"/><rect x="30.3" y="39" width="3.4" height="7" rx="1.5" fill="#fff"/></svg>
                <h1 id="isolir-title">Akun Anda diisolir</h1>
            </div>
            <p class="why">Karena sudah waktunya pembayaran</p>
        </section>

        <div class="msg">
            <b>Agar layanan internet tetap aktif dan lancar, segera lakukan <em>pembayaran tagihan Anda.</em></b>
            <p>Jika sudah melakukan pembayaran, mohon konfirmasi ke Admin dengan melampirkan bukti pembayaran.</p>
        </div>

        <div class="cards">
            <section class="card cs" aria-labelledby="cs-title">
                <h2 id="cs-title">Customer Service</h2>
                @if ($telLink !== null)
                    <a class="num" href="{{ $telLink }}">{{ $csPhone }}</a>
                    <div class="btns">
                        <a class="btn wa" href="{{ $waLink }}" rel="noopener">WhatsApp</a>
                        <a class="btn" href="{{ $telLink }}">Telepon</a>
                    </div>
                @else
                    <span class="num">{{ $csPhone }}</span>
                @endif
                <p class="help">Hubungi kami jika ada kendala atau pertanyaan seputar pembayaran.</p>
            </section>

            <section class="card bank" aria-labelledby="bank-title">
                <h2 id="bank-title">Rekening Pembayaran</h2>
                <div>
                    <p class="bn">{{ $bankName }}</p>
                    <p class="ac">{{ $bankAccount }}</p>
                    <p class="ho">{{ $bankHolder }}</p>
                </div>
            </section>
        </div>

        <p class="off">
            <svg viewBox="0 0 48 48" aria-hidden="true"><path d="M6 19a26 26 0 0 1 36 0M12 26a17 17 0 0 1 24 0M18 33a8 8 0 0 1 12 0" fill="none" stroke="#fff" stroke-width="4" stroke-linecap="round"/><circle cx="35" cy="35" r="10" fill="#fff"/><path d="M31 31l8 8M39 31l-8 8" stroke="#d6121f" stroke-width="3.5" stroke-linecap="round"/></svg>
            <span>Akses internet Anda saat ini dinonaktifkan</span>
        </p>
    </main>
</div>
<footer>
    <b>{{ $brand }}</b>
    @if ($tagline !== '')
        <small>{{ $tagline }}</small>
    @endif
</footer>
</body>
</html>
