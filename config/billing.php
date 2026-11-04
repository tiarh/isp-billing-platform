<?php

return [
    // Owner decision 2026-10-08: a PSB customer waiting for installation
    // (pending_installation) becomes active automatically once the ONU is
    // online on the OLT with a valid RX AND the PPPoE session is active, both
    // fresh (services.online_freshness). Activation fires the Customer model
    // hook, which creates the current-period invoice when billable.
    'auto_activate' => [
        // Kill switch: BILLING_AUTO_ACTIVATE_ONLINE=false stops activations
        // (the command still runs with --dry-run for previews).
        'enabled' => filter_var(env('BILLING_AUTO_ACTIVATE_ONLINE', true), FILTER_VALIDATE_BOOLEAN),
        // Hard cap per run; the rest is picked up by the next run.
        'max_per_run' => max(1, (int) env('BILLING_AUTO_ACTIVATE_MAX_PER_RUN', 200)),
        // Owner rule 2026-10-09: an online pending account whose ONU serial
        // belongs only to isolated/inactive/suspended accounts takes the ONU
        // over (old account -> terminated, new -> active). false = keep
        // skipping those as suspected_duplicate.
        'takeover_reused_onu' => filter_var(env('BILLING_AUTO_ACTIVATE_TAKEOVER_REUSED_ONU', true), FILTER_VALIDATE_BOOLEAN),
    ],
];
