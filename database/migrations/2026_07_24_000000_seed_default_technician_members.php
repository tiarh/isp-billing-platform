<?php

use App\Models\Setting;
use App\Models\TechnicianMember;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $members = [
            // Senior
            ['name' => 'BAIM', 'role' => 'senior'],
            ['name' => 'TYO', 'role' => 'senior'],
            ['name' => 'DAPPA', 'role' => 'senior'],
            ['name' => 'RAFLY', 'role' => 'senior'],
            ['name' => 'JAZULI', 'role' => 'senior'],

            // IKR & PSB
            ['name' => 'ROBI', 'role' => 'ikr_psb'],
            ['name' => 'WIDI', 'role' => 'ikr_psb'],
            ['name' => 'REHAN', 'role' => 'ikr_psb'],
            ['name' => 'RIDWAN', 'role' => 'ikr_psb'],

            // Magang
            ['name' => 'ROY', 'role' => 'magang'],
            ['name' => 'VALNO', 'role' => 'magang'],
            ['name' => 'UPIN', 'role' => 'magang'],
            ['name' => 'IPIN', 'role' => 'magang'],
            ['name' => 'FAHRI', 'role' => 'magang'],
            ['name' => 'DIMAS', 'role' => 'magang'],
            ['name' => 'FATKAH', 'role' => 'magang'],
            ['name' => 'FIKRI', 'role' => 'magang'],
        ];

        foreach ($members as $m) {
            TechnicianMember::firstOrCreate(
                ['name' => $m['name']],
                ['role' => $m['role'], 'is_active' => true]
            );
        }

        Setting::set('ticket_progress_reply_enabled', true, 'boolean', 'ticket', 'Enable reply to input group KERJAAN');
        Setting::set('ticket_progress_dry_run_enabled', false, 'boolean', 'ticket', 'Disable dry run for input group KERJAAN');
        Setting::set('ticket_progress_ai_enabled', true, 'boolean', 'ticket', 'Enable Gemini AI for KERJAAN');
        Setting::set('ticket_progress_auto_send_enabled', true, 'boolean', 'ticket', 'Enable auto send schedule at 08:00 AM');
    }

    public function down(): void
    {
        // Safe down: do not delete
    }
};
