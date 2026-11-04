<?php

namespace Tests\Feature;

use App\Models\CustomerMigration;
use App\Models\PsbDraft;
use App\Services\PsbKpiService;
use App\Services\PsbSiteRegistry;
use App\Services\PsbStarliteReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PsbKpiAreaCanonicalTest extends TestCase
{
    use RefreshDatabase;

    public function test_area_kpi_merges_same_area_with_different_letter_case(): void
    {
        $service = new PsbKpiService(
            new class extends PsbStarliteReportService
            {
                public function dashboard(): array
                {
                    return ['ready' => true];
                }

                public function unifiedRows(): array
                {
                    return [
                        ['area_name' => 'GONDANG', 'sheet_status' => 'SUCCESS', 'work_date' => '2026-07-20', 'site_key' => 'ra'],
                        ['area_name' => 'Gondang', 'sheet_status' => 'SUCCESS', 'work_date' => '2026-07-20', 'site_key' => 'ra'],
                        ['area_name' => 'Randuagung', 'sheet_status' => 'SUCCESS', 'work_date' => '2026-07-20', 'site_key' => 'ra'],
                        ['area_name' => 'RANDUAGUNG', 'sheet_status' => 'SUCCESS', 'work_date' => '2026-07-20', 'site_key' => 'ra'],
                        ['area_name' => 'SUKO', 'sheet_status' => 'SUCCESS', 'work_date' => '2026-07-20', 'site_key' => 'ra'],
                    ];
                }

                public function buildHistoryPublic(int $days, int $target, string $tz, array $successByDate): array
                {
                    return [];
                }
            },
            new PsbSiteRegistry,
        );

        $byArea = collect($service->dashboard([
            'from' => '2026-07-01',
            'to' => '2026-07-31',
        ])['by_area']);

        $this->assertCount(3, $byArea);
        $this->assertSame(2, $byArea->firstWhere('area_name', 'Gondang')['success']);
        $this->assertSame(2, $byArea->firstWhere('area_name', 'Randuagung')['success']);
        $this->assertSame(1, $byArea->firstWhere('area_name', 'Suko')['success']);
    }

    public function test_area_kpi_uses_customer_daerah_before_site_area_name(): void
    {
        $service = new PsbKpiService(
            new class extends PsbStarliteReportService
            {
                public function dashboard(): array
                {
                    return ['ready' => true];
                }

                public function unifiedRows(): array
                {
                    return [
                        ['area_name' => 'Randuagung', 'daerah' => 'Kalirejo', 'sheet_status' => 'SUCCESS', 'work_date' => '2026-07-20', 'site_key' => 'ra'],
                        ['area_name' => 'Randuagung', 'daerah' => 'Gondang', 'sheet_status' => 'SUCCESS', 'work_date' => '2026-07-20', 'site_key' => 'ra'],
                        ['area_name' => 'Randuagung', 'daerah' => 'SUKO', 'sheet_status' => 'SUCCESS', 'work_date' => '2026-07-20', 'site_key' => 'ra'],
                    ];
                }

                public function buildHistoryPublic(int $days, int $target, string $tz, array $successByDate): array
                {
                    return [];
                }
            },
            new PsbSiteRegistry,
        );

        $byArea = collect($service->dashboard([
            'from' => '2026-07-01',
            'to' => '2026-07-31',
        ])['by_area']);

        $this->assertCount(3, $byArea);
        $this->assertSame(1, $byArea->firstWhere('area_name', 'Kalirejo')['success']);
        $this->assertSame(1, $byArea->firstWhere('area_name', 'Gondang')['success']);
        $this->assertSame(1, $byArea->firstWhere('area_name', 'Suko')['success']);
    }

    public function test_dashboard_rebuilds_dynamic_rw_rt_summary_from_filtered_rows(): void
    {
        $service = new PsbKpiService(
            new class extends PsbStarliteReportService
            {
                public function dashboard(): array
                {
                    return [
                        'ready' => true,
                        'by_rw' => [
                            ['rw' => '12', 'total' => 0, 'success' => 0, 'rts' => []],
                            ['rw' => '13', 'total' => 0, 'success' => 0, 'rts' => []],
                        ],
                    ];
                }

                public function unifiedRows(): array
                {
                    return [
                        ['rw' => '4', 'rt' => '1', 'sheet_status' => 'SUCCESS', 'work_date' => '2026-07-20', 'site_key' => 'ra'],
                        ['rw' => '04', 'rt' => '2', 'sheet_status' => 'PENDING', 'work_date' => '2026-07-20', 'site_key' => 'ra'],
                        ['rw' => '7', 'rt' => '10', 'sheet_status' => 'SUCCESS', 'work_date' => '2026-07-20', 'site_key' => 'ra'],
                        ['rw' => '9', 'rt' => '1', 'sheet_status' => 'SUCCESS', 'work_date' => '2026-07-20', 'site_key' => 'krian'],
                    ];
                }

                public function buildHistoryPublic(int $days, int $target, string $tz, array $successByDate): array
                {
                    return [];
                }
            },
            new PsbSiteRegistry,
        );

        $byRw = collect($service->dashboard([
            'site' => 'ra',
            'from' => '2026-07-01',
            'to' => '2026-07-31',
        ])['by_rw'])->keyBy('rw');

        $this->assertSame(['04', '07'], $byRw->keys()->all());
        $this->assertSame(2, $byRw['04']['total']);
        $this->assertSame(1, $byRw['04']['success']);
        $this->assertSame(1, $byRw['04']['rts']['01']['total']);
        $this->assertSame(1, $byRw['04']['rts']['02']['total']);
        $this->assertSame(1, $byRw['07']['rts']['10']['success']);
    }

    public function test_kpi_derives_unambiguous_rt_rw_from_pppoe_without_writing_source_data(): void
    {
        $service = new PsbKpiService(
            new class extends PsbStarliteReportService
            {
                public function dashboard(): array
                {
                    return ['ready' => true];
                }

                public function unifiedRows(): array
                {
                    return [
                        $this->normalizeForTest([
                            'pppoe_user' => 'USERBH_RT05_RW04_039L6',
                            'rw' => null,
                            'rt' => null,
                            'sheet_status' => 'SUCCESS',
                            'work_date' => '2026-08-03',
                            'site_key' => 'ra',
                        ]),
                        $this->normalizeForTest([
                            'pppoe_user' => 'DAPUR',
                            'rw' => null,
                            'rt' => null,
                            'sheet_status' => 'SUCCESS',
                            'work_date' => '2026-08-03',
                            'site_key' => 'ra',
                        ]),
                    ];
                }

                public function normalizeForTest(array $row): array
                {
                    return $this->normalizeRow($row);
                }

                public function buildHistoryPublic(int $days, int $target, string $tz, array $successByDate): array
                {
                    return [];
                }
            },
            new PsbSiteRegistry,
        );

        $payload = $service->dashboard([
            'from' => '2026-08-03',
            'to' => '2026-08-03',
        ]);
        $byRw = collect($payload['by_rw'])->keyBy('rw');

        $this->assertSame(1, $byRw['04']['total']);
        $this->assertSame(1, $byRw['04']['rts']['05']['total']);
        $this->assertSame(1, $byRw['(kosong)']['total']);
        $this->assertSame(2, $payload['total_input']);
    }

    public function test_csr_is_reported_separately_from_regular_rw_kpi(): void
    {
        $today = now('Asia/Jakarta')->toDateString();

        $service = new PsbKpiService(
            new class($today) extends PsbStarliteReportService
            {
                public function __construct(private string $today) {}

                public function dashboard(): array
                {
                    return ['ready' => true];
                }

                public function unifiedRows(): array
                {
                    return [
                        [
                            'pppoe_user' => 'MI_TEST_RT01_RW04_01',
                            'rw' => '04',
                            'rt' => '01',
                            'service_classification' => 'CSR',
                            'sheet_status' => 'SUCCESS',
                            'work_date' => $this->today,
                            'site_key' => 'ra',
                        ],
                        [
                            'pppoe_user' => 'REGULER_RT01_RW04_02',
                            'rw' => '04',
                            'rt' => '01',
                            'sheet_status' => 'SUCCESS',
                            'work_date' => $this->today,
                            'site_key' => 'ra',
                        ],
                    ];
                }

                public function buildHistoryPublic(int $days, int $target, string $tz, array $successByDate): array
                {
                    return [];
                }
            },
            new PsbSiteRegistry,
        );

        $payload = $service->dashboard([
            'from' => $today,
            'to' => $today,
        ]);

        $this->assertSame(['input' => 1, 'success' => 1, 'success_today' => 1, 'pending' => 0, 'failed' => 0], $payload['csr']);
        $this->assertSame(1, $payload['total_input']);
        $this->assertSame(1, $payload['success_total']);
        $this->assertSame(1, $payload['by_rw'][0]['total']);
    }

    public function test_manual_fiberhome_migration_pair_is_excluded_from_psb_unified_rows(): void
    {
        CustomerMigration::create([
            'status' => CustomerMigration::STATUS_SYNCED,
            'source' => 'manual_fiberhome',
            'message_id' => 'migration-test-1',
            'full_name' => 'Migrated Customer',
            'pppoe_user' => 'MIGRATED_RT01_RW04_01',
            'old_sn' => 'CIOT-OLD-01',
            'new_sn' => 'FHTT-NEW-01',
        ]);
        PsbDraft::create([
            'status' => PsbDraft::STATUS_OLT_OK,
            'activation_mode' => PsbDraft::MODE_FULL_OLT,
            'site_profile_key' => 'ra',
            'group_jid' => 'manual@ebilling',
            'message_id' => 'manual-fiberhome-test',
            'full_name' => 'Migrated Customer',
            'pppoe_user' => 'MIGRATED_RT01_RW04_01',
            'sn' => 'FHTT-NEW-01',
            'meta' => ['source' => 'manual', 'manual_path' => 'fiberhome'],
        ]);
        PsbDraft::create([
            'status' => PsbDraft::STATUS_OLT_OK,
            'activation_mode' => PsbDraft::MODE_FULL_OLT,
            'site_profile_key' => 'ra',
            'group_jid' => 'manual@ebilling',
            'message_id' => 'manual-psb-test',
            'full_name' => 'New PSB',
            'pppoe_user' => 'NEWPSB_RT01_RW04_02',
            'sn' => 'FHTT-PSB-01',
            'meta' => ['source' => 'manual'],
        ]);

        Cache::flush();
        $rows = app(PsbStarliteReportService::class)->unifiedRows();

        $this->assertFalse(collect($rows)->contains(fn (array $row) => ($row['sn'] ?? '') === 'FHTT-NEW-01'));
        $this->assertTrue(collect($rows)->contains(fn (array $row) => ($row['sn'] ?? '') === 'FHTT-PSB-01'));
    }
}
