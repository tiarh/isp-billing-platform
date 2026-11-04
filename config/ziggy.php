<?php

/**
 * Slim Ziggy: only frontend-used named routes.
 * Without this, @routes dumps ~200 routes (~40KB) into every HTML body.
 */
return [
    'only' => [
        // auth
        'login',
        'logout',
        'register',
        'password.confirm',
        'password.email',
        'password.request',
        'password.store',
        'password.update',
        'verification.send',
        'profile.edit',
        'profile.update',
        'profile.destroy',
        // TOTP enrollment / challenge / step-up pages call route('totp.*').
        'totp.*',

        // FAQ / Panduan (sidebar + tombol "?" di header)
        'faq.*',

        // core
        'dashboard',
        'api.global-search',
        'settings.index',
        // Settings > Payment Gateway (superadmin) + its JSON endpoints
        'settings.payment-gateway*',
        // Settings > Grup WhatsApp
        'settings.wa-groups',
        'settings.wa-groups.*',
        'api.settings.wa-groups.*',
        'api.settings.payment-gateway.*',
        'api.payment-gateway.*',
        // Online payment reconciliation (admin)
        'payment-gateway.*',
        // Kelebihan bayar (customer credits)
        'customer-credits.*',
        'users.*',
        // Control Plane (superadmin) — sidebar item would be hidden without this.
        'production-control-plane.*',

        // customers / billing
        'customers.*',
        'invoices.*',
        'payments.*',
        'packages.*',
        'areas.*',
        'finance.per-area.*',
        'broadcasts.*',
        'public.invoice.*',

        // network / ops
        'routers.*',
        'olts.*',
        'oltc300.*',
        'api.olt-c300.*',
        'api.olts.*',
        'monitoring.*',
        'api.monitoring.*',
        'attendances.*',
        'api.attendances.*',
        'wa-gateway.*',
        'api.wa-gateway.*',
        'psb-imports.*',
        // PSB WA (panel, STARLITE dashboard, export) — wajib di allowlist Ziggy
        'psb-drafts.*',
        // Saleskit (calon pelanggan) — wajib di allowlist Ziggy, kalau tidak
        // menu tidak muncul dan halaman gagal dibuka di browser.
        'saleskit.*',
        'customer-migrations.*',
        'inventaris.*',
        'device-recovery.*',
        'modules.*',
        'tickets.*',

        // maps / analytics if present
        'analytics.*',
        'peta.*',
        'maps.*',
    ],
];
