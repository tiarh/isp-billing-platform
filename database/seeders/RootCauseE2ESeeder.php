<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\OutageDiagnosis;
use App\Models\SupportTicket;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Sandbox-only fixture for the "Penyebab sebenarnya" browser spec (ticket-root-cause.spec.ts): open tickets with
 * fixed ids, each closed through another path (admin dialog, technician form on a phone, Edit page). No assignee,
 * no WA (the sandbox has no WA provider configured).
 */
class RootCauseE2ESeeder extends Seeder
{
    public const ADMIN_TICKET_ID = 9100001;

    public const TECH_TICKET_ID = 9100002;

    /** Open incident that already has a cause: the Edit form changes it (spec: ticket edit changes the cause). */
    public const EDIT_TICKET_ID = 9100003;

    /** Open incident with a stored diagnosis (wlan_down): the dialog offers it as a suggestion. */
    public const SUGGEST_TICKET_ID = 9100004;

    /** Open incident that was really a new installation: closed with "Bukan gangguan". */
    public const NOT_FAULT_TICKET_ID = 9100005;

    /** Open installation ticket: closes without any cause picker. */
    public const INSTALL_TICKET_ID = 9100006;

    /** Open incident that is re-typed and closed in one Edit save. */
    public const RETYPE_TICKET_ID = 9100007;

    public function run(): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('RootCauseE2ESeeder hanya boleh dijalankan pada APP_ENV=testing.');
        }

        $open = [
            self::ADMIN_TICKET_ID => ['ADMIN', 'incident'],
            self::TECH_TICKET_ID => ['TECH', 'incident'],
            self::SUGGEST_TICKET_ID => ['SUGGEST', 'incident'],
            self::NOT_FAULT_TICKET_ID => ['NOTFAULT', 'incident'],
            self::INSTALL_TICKET_ID => ['INSTALL', 'installation'],
            self::RETYPE_TICKET_ID => ['RETYPE', 'incident'],
        ];
        foreach ($open as $id => [$label, $type]) {
            $ticket = SupportTicket::query()->find($id) ?? new SupportTicket;
            $ticket->forceFill([
                'id' => $id,
                'code' => 'TCK-E2E-RC-'.$label,
                'report_id' => 'RPT-E2E-RC-'.$label,
                'type' => $type,
                'priority' => 'normal',
                'status' => 'open',
                'gangguan' => 'Internet mati (fixture E2E penyebab '.$label.')',
                'subject' => 'Internet mati (fixture E2E penyebab '.$label.')',
                'root_cause' => null,
                'resolved_at' => null,
            ] + $this->emptyDetail())->save();
        }

        $edit = SupportTicket::query()->find(self::EDIT_TICKET_ID) ?? new SupportTicket;
        $edit->forceFill([
            'id' => self::EDIT_TICKET_ID,
            'code' => 'TCK-E2E-RC-EDIT',
            'report_id' => 'RPT-E2E-RC-EDIT',
            'type' => 'incident',
            'priority' => 'normal',
            'status' => 'open',
            'gangguan' => 'Internet mati (fixture E2E penyebab EDIT)',
            'subject' => 'Internet mati (fixture E2E penyebab EDIT)',
            'root_cause' => 'drop_los',
            'resolved_at' => null,
        ] + $this->emptyDetail())->save();

        // The suggestion on the SUGGEST ticket: a finished diagnosis linked to it (any customer id; the ticket has none).
        OutageDiagnosis::query()->updateOrCreate(
            ['ticket_id' => self::SUGGEST_TICKET_ID, 'trigger' => OutageDiagnosis::TRIGGER_TICKET_OPEN],
            [
                'customer_id' => (int) (Customer::query()->value('id') ?? 0),
                'state' => OutageDiagnosis::STATE_DONE,
                'cause_code' => 'wlan_down',
                'confidence' => 'sedang',
                'layer' => 'cpe',
                'secondary' => [],
                'rule_id' => 'wlan_down',
                'rules_version' => 'e2e',
                'suggested_action' => 'bantu_wifi',
                'duration_ms' => 10,
                'probes' => [],
                'evidence' => [],
            ],
        );
    }

    /** @return array<string, null> detail columns exist only after migration 2026_10_22_000001 */
    private function emptyDetail(): array
    {
        return [
            'root_cause_note' => null,
            'root_cause_suggested' => null,
            'root_cause_source' => null,
        ];
    }
}
