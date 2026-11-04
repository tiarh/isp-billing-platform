<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class BillingSettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Setting::set('billing_grace_period_days', 3, 'integer', 'billing', 'Grace Period (Days) — alias isolir');
        Setting::set('billing_isolir_after_days', 3, 'integer', 'billing', 'Isolir after due H+N days');
        Setting::set('billing_dismantle_after_days', 33, 'integer', 'billing', 'Dismantle after due H+N days');
        // SAFETY: dry-run ON by default — no mass MT isolir until admin turns off in Settings
        Setting::set('billing_enforcement_dry_run', '1', 'boolean', 'billing', 'Enforcement dry-run (1=log only)');
        Setting::set('wa_billing_writes_enabled', '0', 'boolean', 'whatsapp', 'WA billing live sending enabled');
        Setting::set('wa_broadcast_writes_enabled', '0', 'boolean', 'whatsapp', 'WA broadcast live sending enabled');
    }
}
