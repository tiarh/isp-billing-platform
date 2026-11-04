<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\PsbDraft;
use App\Models\Setting;
use App\Models\WaChatSignal;
use App\Services\ActivationChatReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ActivationChatReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_is_deduplicated_redacted_and_never_writes_operational_data(): void
    {
        config([
            'services.evolution_api.base_url' => 'http://evolution.test',
            'services.evolution_api.api_key' => 'api-secret',
            'services.evolution_api.instance_ops' => 'ops-test',
        ]);
        Setting::set('psb_wa_group_ids', ['120363-activation@g.us']);

        $draft = PsbDraft::query()->create([
            'status' => PsbDraft::STATUS_OLT_OK,
            'group_jid' => '120363-activation@g.us',
            'message_id' => 'MSG-PSB-1',
            'full_name' => 'MARATUS',
            'odp' => 'ODP-RANDUTELU-L4',
            'sn' => 'FHTT0000A034',
        ]);
        WaChatSignal::query()->create([
            'channel' => 'activation',
            'domain' => 'psb',
            'intent' => 'psb_command',
            'group_jid' => '120363-activation@g.us',
            'message_id' => 'MSG-PSB-1',
            'redacted_text' => '#psb [REDACTED]',
            'occurred_at' => '2026-07-28 03:00:00',
        ]);

        $record = $this->record(
            'MSG-PSB-1',
            1785207600,
            "#psb\nNama: MARATUS\nODP: ODP-RANDUTELU-L4\nSN: FHTT0000A034\n"
            .'NIK: 3501010110000001 HP: 081234567890 Tikor: -6.12345,111.54321 '
            .'apikey: api-secret Password: rahasia'
        );
        Http::fake([
            'http://evolution.test/chat/findMessages/ops-test' => Http::response([
                'messages' => [
                    'records' => [$record, $record],
                    'pages' => 1,
                ],
            ]),
        ]);

        $before = $this->operationalCounts();
        $report = app(ActivationChatReconciliationService::class)->run(
            CarbonImmutable::parse('2026-07-27', 'Asia/Jakarta')->startOfDay(),
            CarbonImmutable::parse('2026-07-28', 'Asia/Jakarta')->endOfDay(),
        );

        $this->assertSame($before, $this->operationalCounts());
        $this->assertCount(1, $report['rows']);
        $this->assertSame('duplicate', $report['rows'][0]['classification']);
        $this->assertSame($draft->id, $report['rows'][0]['entity_id']);
        $this->assertSame(2, $report['rows'][0]['duplicate_count']);

        $encoded = json_encode($report, JSON_UNESCAPED_SLASHES);
        foreach ([
            '3501010110000001',
            '081234567890',
            '-6.12345,111.54321',
            'api-secret',
            '120363-activation@g.us',
            'rahasia',
            'MSG-PSB-1',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
        }
    }

    public function test_resolved_reclassification_manual_migration_and_missing_draft_are_reported(): void
    {
        config([
            'services.evolution_api.base_url' => 'http://evolution.test',
            'services.evolution_api.api_key' => 'api-secret',
            'services.evolution_api.instance_ops' => 'ops-test',
        ]);
        Setting::set('psb_wa_group_ids', ['120363-activation@g.us']);

        PsbDraft::query()->create([
            'status' => PsbDraft::STATUS_OLT_OK,
            'full_name' => 'USER W',
            'sn' => 'FHTT0000A027',
        ]);
        CustomerMigration::query()->create([
            'status' => CustomerMigration::STATUS_CANCELLED,
            'source' => 'manual_fiberhome',
            'message_id' => 'MSG-SITI',
            'full_name' => 'USER W',
            'new_sn' => 'FHTT0000A027',
            'meta' => ['reclassified_to_psb_draft_id' => 141],
        ]);
        CustomerMigration::query()->create([
            'status' => CustomerMigration::STATUS_SYNCED,
            'source' => 'manual_fiberhome',
            'message_id' => 'MSG-USERAT',
            'full_name' => 'USERAT',
            'pppoe_user' => 'USERAT_RT5_RW4_03',
            'meta' => ['manual_fiberhome_recorded_at' => '2026-07-28T17:50:00+07:00'],
        ]);

        Http::fake([
            'http://evolution.test/chat/findMessages/ops-test' => Http::response([
                'messages' => [
                    'records' => [
                        $this->record('MSG-SITI', 1785207600, "#migrasi\nNama: USER W\nSN: FHTT0000A027"),
                        $this->record('MSG-USERAT', 1785211200, "#migrasi\nNama: USERAT\nPPPoE: USERAT_RT5_RW4_03"),
                        $this->record('MSG-MISSING', 1785214800, "#psb\nNama: BELUM ADA\nODP: ODP-GONDANG-L3.1\nSN: FHTT0000A036"),
                    ],
                    'pages' => 1,
                ],
            ]),
        ]);

        $report = app(ActivationChatReconciliationService::class)->run(
            CarbonImmutable::parse('2026-07-27', 'Asia/Jakarta')->startOfDay(),
            CarbonImmutable::parse('2026-07-28', 'Asia/Jakarta')->endOfDay(),
        );

        $classes = collect($report['rows'])->pluck('classification', 'name');
        $this->assertSame('resolved_reclassified', $classes['USER W']);
        $this->assertSame('resolved_manual', $classes['USERAT']);
        $this->assertSame('missing_draft', $classes['BELUM ADA']);
        $this->assertSame(0, DB::table('device_action_logs')->count());
    }

    public function test_bot_recap_is_ignored_and_formatter_failure_links_to_post_secret_draft(): void
    {
        config([
            'services.evolution_api.base_url' => 'http://evolution.test',
            'services.evolution_api.api_key' => 'api-secret',
            'services.evolution_api.instance_ops' => 'ops-test',
        ]);
        Setting::set('psb_wa_group_ids', ['120363-activation@g.us']);

        $draft = PsbDraft::query()->create([
            'status' => PsbDraft::STATUS_OLT_OK,
            'full_name' => 'MARATUS',
            'sn' => 'FHTT0000A034',
            'secret_at' => CarbonImmutable::createFromTimestamp(1785207600, 'UTC'),
        ]);

        $recap = $this->record('BOT-RECAP', 1785207600, "Rekap PSB hari ini\nRefresh: ketik */psb*");
        $recap['key']['fromMe'] = true;
        $error = $this->record(
            'BOT-ERROR',
            1785207660,
            'Proses #psb gagal: Call to undefined method App\Services\PsbWaMessages::received()',
        );
        $error['key']['fromMe'] = true;

        Http::fake([
            'http://evolution.test/chat/findMessages/ops-test' => Http::response([
                'messages' => ['records' => [$recap, $error], 'pages' => 1],
            ]),
        ]);

        $report = app(ActivationChatReconciliationService::class)->run(
            CarbonImmutable::parse('2026-07-27', 'Asia/Jakarta')->startOfDay(),
            CarbonImmutable::parse('2026-07-28', 'Asia/Jakarta')->endOfDay(),
        );

        $this->assertCount(1, $report['rows']);
        $this->assertSame('formatter_failed_after_secret', $report['rows'][0]['classification']);
        $this->assertSame($draft->id, $report['rows'][0]['entity_id']);
    }

    public function test_same_sn_does_not_merge_different_school_name(): void
    {
        config([
            'services.evolution_api.base_url' => 'http://evolution.test',
            'services.evolution_api.api_key' => 'api-secret',
            'services.evolution_api.instance_ops' => 'ops-test',
        ]);
        Setting::set('psb_wa_group_ids', ['120363-activation@g.us']);

        PsbDraft::query()->create([
            'status' => PsbDraft::STATUS_OLT_OK,
            'full_name' => 'MI MIFTAHUL FALAH',
            'sn' => 'FHTT0000A025',
        ]);
        $migration = CustomerMigration::query()->create([
            'status' => CustomerMigration::STATUS_FAILED,
            'full_name' => 'MTS MIFTAHUL FALAH',
            'new_sn' => 'FHTT0000A025',
        ]);

        Http::fake([
            'http://evolution.test/chat/findMessages/ops-test' => Http::response([
                'messages' => [
                    'records' => [
                        $this->record(
                            'MSG-MTS',
                            1785207600,
                            "#psb\nNama: MTS MIFTAHUL FALAH\nSN: FHTT0000A025",
                        ),
                    ],
                    'pages' => 1,
                ],
            ]),
        ]);

        $report = app(ActivationChatReconciliationService::class)->run(
            CarbonImmutable::parse('2026-07-27', 'Asia/Jakarta')->startOfDay(),
            CarbonImmutable::parse('2026-07-28', 'Asia/Jakarta')->endOfDay(),
        );

        $this->assertSame('wrong_migration', $report['rows'][0]['classification']);
        $this->assertSame($migration->id, $report['rows'][0]['entity_id']);
    }

    public function test_correction_pending_is_never_reported_as_safe_to_replay(): void
    {
        config([
            'services.evolution_api.base_url' => 'http://evolution.test',
            'services.evolution_api.api_key' => 'api-secret',
            'services.evolution_api.instance_ops' => 'ops-test',
        ]);
        Setting::set('psb_wa_group_ids', ['120363-activation@g.us']);

        PsbDraft::query()->create([
            'status' => PsbDraft::STATUS_CORRECTION_PENDING,
            'message_id' => 'MSG-CORRECTION',
            'full_name' => 'MTS MIFTAHUL F',
            'sn' => 'FHTT0000A025',
        ]);
        Http::fake([
            'http://evolution.test/chat/findMessages/ops-test' => Http::response([
                'messages' => [
                    'records' => [
                        $this->record(
                            'MSG-CORRECTION',
                            1785207600,
                            "#psb\nNama: MTS MIFTAHUL F\nSN: FHTT0000A025",
                        ),
                    ],
                    'pages' => 1,
                ],
            ]),
        ]);

        $report = app(ActivationChatReconciliationService::class)->run(
            CarbonImmutable::parse('2026-07-27', 'Asia/Jakarta')->startOfDay(),
            CarbonImmutable::parse('2026-07-28', 'Asia/Jakarta')->endOfDay(),
        );

        $this->assertSame('needs_review', $report['rows'][0]['classification']);
        $this->assertStringContainsString('Jangan jalankan #psb lanjut', $report['rows'][0]['operator_action']);
    }

    /**
     * @return array<string, mixed>
     */
    private function record(string $id, int $timestamp, string $text): array
    {
        return [
            'key' => [
                'id' => $id,
                'remoteJid' => '120363-activation@g.us',
                'participant' => '6281111111111@s.whatsapp.net',
                'fromMe' => false,
            ],
            'pushName' => 'Teknisi',
            'messageTimestamp' => $timestamp,
            'message' => ['conversation' => $text],
        ];
    }

    /**
     * @return array<string, int>
     */
    private function operationalCounts(): array
    {
        return [
            'customers' => Customer::query()->count(),
            'drafts' => PsbDraft::query()->count(),
            'migrations' => CustomerMigration::query()->count(),
            'signals' => WaChatSignal::query()->count(),
            'device_actions' => DB::table('device_action_logs')->count(),
        ];
    }
}
