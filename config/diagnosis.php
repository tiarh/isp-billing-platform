<?php

/*
 * RCA-4 "Cek sekarang". The probes talk to a router, an OLT and a CPE; the `fake` driver answers from the canned
 * values below instead (browser E2E in the sandbox). The fake driver is REFUSED in production.
 */
return [
    'probe_driver' => env('DIAGNOSIS_PROBE_DRIVER', 'real'), // real | fake

    // Hard limits per probe (seconds); the browser aborts a probe that takes longer.
    'timeouts' => ['router' => 6, 'olt' => 15, 'cpe' => 12],

    // Pause of the fake driver per probe, milliseconds (so the "mengecek..." state is visible in the E2E).
    'fake_delay_ms' => (int) env('DIAGNOSIS_FAKE_DELAY_MS', 900),

    // Local time zone of the router clock: RouterOS prints `last-logged-out` ("2026-10-20 14:03:11") in it.
    'router_clock_timezone' => env('DIAGNOSIS_ROUTER_CLOCK_TZ', 'Asia/Jakarta'),
];
