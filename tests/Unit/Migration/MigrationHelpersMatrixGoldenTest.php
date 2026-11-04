<?php

namespace Tests\Unit\Migration;

use App\Models\CustomerMigration;
use App\Models\Setting;
use App\Services\CustomerMigrationMessageParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\MigrationGolden\MigrationGoldenHarness;
use Tests\Support\MigrationGolden\MigrationGoldenWorld;
use Tests\Support\MigrationGolden\MigrationHelperProbe;
use Tests\TestCase;

/**
 * One golden of every text builder and pure helper of the migration service (migration
 * refactor M0, tests only). The extraction steps move these helpers to new classes; the golden
 * proves their output did not change. Calls go through MigrationHelperProbe, whose receiver table
 * is the only support file a later step edits.
 *
 * Golden: tests/Fixtures/migration-flow/helpers-matrix.golden.json, written ONLY with the env
 * MIGRATION_FLOW_GOLDEN_WRITE=1. A change needs owner OK.
 */
#[Group('blocking-psb')]
class MigrationHelpersMatrixGoldenTest extends TestCase
{
    use MigrationGoldenHarness;
    use MigrationGoldenWorld;
    use RefreshDatabase;

    private MigrationHelperProbe $probe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMigrationGolden();
        $this->probe = new MigrationHelperProbe;
    }

    protected function tearDown(): void
    {
        $this->shutdownMigrationGolden();
        parent::tearDown();
    }

    public function test_helpers_match_golden(): void
    {
        $this->seedWorld();
        $out = [
            'texts' => $this->texts(),
            'normalizers' => $this->normalizers(),
            'routing' => $this->routing(),
            'plans' => $this->plans(),
        ];
        $this->indexRows();
        $this->compareToGolden('helpers-matrix', $this->encode($this->scrub($out)));
    }

    /** @param array<string, mixed> $attrs @param array<string, mixed> $meta */
    private function row(int $id, array $attrs = [], array $meta = []): CustomerMigration
    {
        $row = new CustomerMigration;
        $row->forceFill(array_merge(['status' => 'needs_review', 'pppoe_user' => 'USERBUDI_RT01_RW02', 'full_name' => 'DRAFTE SANTOSO'], $attrs, ['meta' => $meta]));
        // Fixed ids far above every saved row: the saved ids are random, so a scrubbed text never collides with them.
        $row->id = 9000 + $id;

        return $row;
    }

    /** @return array<string, mixed> */
    private function texts(): array
    {
        $p = $this->probe;
        $candidates = [
            ['number' => 1, 'name' => 'DRAFTE SANTOSO', 'pppoe_user' => 'USERBUDI_RT01_RW02', 'score' => 100, 'old_sn' => 'ZTEGA0000011', 'old_onu_ref' => '1/1/1:5', 'conflicts' => []],
            ['number' => 2, 'name' => 'USERSI AMINAH', 'pppoe_user' => 'USERSITI_RT01_RW02', 'score' => 90, 'conflicts' => ['pppoe_mismatch', 'old_sn_mismatch', 'pppoe_mismatch']],
            ['number' => 3, 'name' => 'DRAFTG LESTARI', 'pppoe_user' => 'USERDEWI_RT01_RW02', 'score' => 70, 'conflicts' => ['new_sn_already_linked_to_other_customer']],
        ];
        $out = ['conflict_text' => [], 'preview' => [], 'status' => [], 'review' => [], 'success' => [], 'partial' => []];
        foreach (['new_sn_already_linked_to_other_customer', 'new_sn_exists_in_other_psb_history', 'excel_new_sn_conflict', 'pppoe_mismatch', 'old_sn_mismatch', 'old_sn_not_current_customer_sn', 'something_else'] as $code) {
            $out['conflict_text'][$code] = $p->call('guidedConflictText', [$code]);
        }
        $out['conflicts_text'] = $p->call('guidedConflictsText', [['pppoe_mismatch', 'old_sn_mismatch', 'pppoe_mismatch']]);
        $out['candidate_lines'] = $p->call('guidedCandidateLines', [$candidates]);
        $out['list_message'] = [$p->call('guidedListMessage', [9007, $candidates]), $p->call('guidedListMessage', [9007, []])];
        $out['pick_first_message'] = [$p->call('guidedPickFirstMessage', [9007, $candidates]), $p->call('guidedPickFirstMessage', [9007, []])];
        $out['dropped_message'] = $p->call('guidedSelectionDroppedMessage', [9007, 'SN baru sudah tercatat di pelanggan lain']);
        $out['blocked_message'] = [$p->call('guidedPreviewBlockedMessage', ['lanjut']), $p->call('guidedPreviewBlockedMessage', ['batal'])];
        $out['help'] = $p->call('helpMessage');

        $draft = CustomerMigration::create(['status' => 'needs_review', 'source' => 'guided_lookup_wa', 'customer_id' => $this->people['dewi'], 'full_name' => 'DRAFTG LESTARI', 'pppoe_user' => 'USERDEWI_RT01_RW02', 'old_sn' => 'ZTEGA0000013', 'new_sn' => 'FHTTC0FFEE90', 'meta' => ['device_action_approval' => ['status' => 'dry_run_ready']]]);
        $this->person('dewi')->forceFill(['ktp_photo_url' => null, 'phone' => ''])->save();
        $plain = CustomerMigration::create(['status' => 'needs_review', 'source' => 'guided_lookup_wa', 'customer_id' => $this->people['dewi'], 'full_name' => 'DRAFTG LESTARI', 'pppoe_user' => 'USERDEWI_RT01_RW02', 'old_sn' => 'ZTEGA0000013', 'new_sn' => 'FHTTC0FFEE91', 'meta' => ['guided_wa_selection' => ['acknowledged_conflicts' => ['old_sn_mismatch']]]]);
        $previews = [
            'incomplete' => ['status' => 'input_incomplete', 'missing' => ['name_required', 'new_sn_required'], 'candidates' => []],
            'ready' => ['status' => 'ready_for_selection', 'candidates' => $candidates, 'next_action' => '#migrasi pilih #ID <nomor kandidat>'],
        ];
        $out['preview'] = [
            'incomplete' => $p->call('guidedPreviewMessage', [$previews['incomplete']]),
            'ready_no_draft' => $p->call('guidedPreviewMessage', [$previews['ready']]),
            'ready_draft_dry_run_ready' => $p->call('guidedPreviewMessage', [$previews['ready'], $draft]),
            'ready_draft_with_error' => $p->call('guidedPreviewMessage', [$previews['ready'], $plain, 'SN belum muncul di uncfg']),
            'selection_confirmation_missing_data' => $p->call('guidedSelectionConfirmationMessage', [$plain, 1]),
            'selection_confirmation_three' => $p->call('guidedSelectionConfirmationMessage', [$draft, 3]),
            'candidate_selection_preview_none' => $p->call('candidateSelectionPreviewMessage', [null, 2]),
            'candidate_selection_preview_row' => $p->call('candidateSelectionPreviewMessage', [$draft, 1]),
        ];

        $out['today_summary_empty'] = $p->call('todaySummary');
        foreach ([
            ['2026-10-19 16:59:59', 'synced', 'guided_lookup_wa'], ['2026-10-19 17:00:00', 'synced', 'guided_lookup_wa'], ['2026-10-20 03:00:00', 'needs_review', 'guided_lookup_wa'],
            ['2026-10-20 03:00:00', 'failed', 'manual_fiberhome'], ['2026-10-20 03:00:00', 'cancelled', 'guided_lookup_wa'], ['2026-10-20 03:00:00', 'olt_ok', 'guided_lookup_wa'],
            ['2026-10-20 03:00:00', 'received', 'wa'], ['2026-10-20 16:59:59', 'needs_review', 'guided_lookup_wa'], ['2026-10-20 17:00:00', 'synced', 'guided_lookup_wa'],
        ] as $i => [$at, $status, $source]) {
            $row = CustomerMigration::create(['status' => $status, 'source' => $source, 'pppoe_user' => 'X'.$i, 'full_name' => 'Row '.$i, 'new_sn' => $i === 7 ? null : 'FHTTC0FFEE9'.$i, 'meta' => $i === 2 ? ['device_action_approval' => ['status' => 'dry_run_ready']] : []]);
            $row->forceFill(['created_at' => $at])->saveQuietly();
        }
        $out['today_summary_rows'] = $p->call('todaySummary');

        $state = fn (array $meta, array $attrs = []) => $this->row(201, $attrs + ['new_sn' => 'FHTTC0FFEE92', 'new_olt_id' => self::OLT_KRIAN], $meta);
        $mikrotik = ['ok' => true, 'cross_router' => true, 'old_username' => 'USERBUDI_RT01_RW02', 'new_username' => 'USERBUDI_RT01_RW02', 'source_router' => ['id' => 15, 'name' => 'Krian'], 'target_router' => ['id' => 514, 'name' => 'Skynet-Krian-OLT']];
        $shapes = [
            'mikrotik_done' => $state(['mikrotik_migration' => $mikrotik, 'partial_execution' => ['sn_hints' => [['message' => 'Maksud Anda FHTTC0FFEE93 (port 1/2/2:3)?']]]]),
            'same_router_done' => $state(['mikrotik_migration' => ['ok' => true, 'cross_router' => false, 'old_username' => 'OLDUSER_RT1', 'new_username' => 'NEWUSER_RT1', 'target_router' => ['id' => 514, 'name' => 'Skynet-Krian-OLT']]]),
            'target_prepared_only' => $state(['mikrotik_migration' => ['target_prepared' => true, 'cross_router' => true, 'target_router' => ['id' => 514, 'name' => 'Skynet-Krian-OLT'], 'new_username' => 'USERBUDI_RT01_RW02']]),
            'olt_and_acs_done' => $state(['olt_provisioned_at' => '2026-10-20T03:00:00+00:00', 'genieacs_tag' => ['success' => true], 'mikrotik_migration' => $mikrotik], ['new_onu_ref' => '1/2/2:3']),
            'cancelled_force' => $state(['mikrotik_migration' => $mikrotik], ['status' => 'cancelled']),
            'continuation_ready' => $state(['mikrotik_migration' => $mikrotik, 'device_action_approval' => ['status' => 'dry_run_ready', 'ledger_id' => 9055]]),
        ];
        foreach ($shapes as $label => $row) {
            $partial = $p->call('partialExecutionState', [$row]);
            $out['partial'][$label] = [
                'status_message' => $p->call('statusMessage', [$row]),
                'short' => $p->call('partialExecutionShortMessage', [$row, $partial]),
                'rollback_hint' => $p->call('partialRollbackHint', [$row, $partial]),
                'wa_message' => $p->call('partialExecutionWaMessage', [$row, $partial, ['Catatan tambahan']]),
            ];
        }
        $out['status']['plain'] = $p->call('statusMessage', [$this->row(202, ['old_sn' => 'ZTEGA0000011', 'old_onu_ref' => '1/1/1:5', 'new_sn' => 'FHTTC0FFEE94', 'last_error' => 'Dry-run siap.'], ['migration_review' => ['match_status' => 'confirmed_pending_approval', 'reasons' => ['guided_wa_candidate_confirmed']]])]);
        $out['status']['with_router_value'] = $p->call('statusMessage', [$this->row(203, ['status' => 'synced']), $this->canaryValue()]);
        $out['waiting_new_sn'] = $p->call('waitingNewSnMessage', [$this->row(204, ['new_sn' => 'fhttc0ffee95', 'new_olt_id' => self::OLT_KRIAN, 'last_error' => ''])]);
        $out['review']['plain'] = $p->call('reviewMessage', [$this->row(205, ['old_sn' => 'ZTEGA0000011', 'new_sn' => 'FHTTC0FFEE96'], ['migration_review' => ['reasons' => ['operator_approval_required'], 'confirmed_evidence' => [['id' => 1]]], 'migration_operation' => ['operation_type' => 'MIGRATION', 'reason_code' => 'TARGET_ROUTER_CHANGED', 'operation_reference' => 'MIG-000000000000']])]);
        $out['review']['ktp_new_photo'] = $p->call('ktpReviewLine', [$this->row(206, ['ktp_photo_path' => 'migrasi/2026/10/ktp_x.jpg'])]);
        $out['review']['ktp_customer_empty'] = $p->call('ktpReviewLine', [$this->row(207, ['customer_id' => $this->people['dewi']])]);
        $out['review']['ktp_nothing'] = $p->call('ktpReviewLine', [$this->row(208, ['customer_id' => $this->people['budi']])]);

        $success = fn (array $meta, array $attrs = []) => $p->call('successMessage', [$this->row(209, $attrs + ['new_onu_ref' => '1/2/2:3', 'new_sn' => 'FHTTC0FFEE97', 'old_onu_ref' => '1/1/1:5', 'old_sn' => 'ZTEGA0000011', 'vlan' => 200], $meta), $meta['credential'] ?? null]);
        $out['success'] = [
            'without_router_value' => $success([]),
            'with_router_value_acs_verified' => $success(['genieacs_tag' => ['success' => true], 'credential' => $this->canaryValue()], ['old_onu_deleted_at' => '2026-10-20 03:00:00', 'tr069_vlan' => 69]),
            'name_mismatch' => $success(['olt_existing_onu' => ['name_mismatch' => true, 'onu_name_on_olt' => 'DRAFTESANTOSO', 'expected_onu_name' => 'USERBUDI_RT01_RW02']]),
        ];
        $huawei = $this->row(210, ['router_id' => self::GADANG_ROUTER, 'new_onu_ref' => '0/0/1:0', 'vlan' => 102, 'tr069_vlan' => 69]);
        $out['success']['huawei'] = [
            $p->call('huaweiOltOnlySuccessMessage', [$huawei, ['pushed' => true], $this->canaryValue()]),
            $p->call('huaweiOltOnlySuccessMessage', [$huawei, ['pushed' => false], null]),
        ];
        $out['short_wa_error'] = [$p->call('shortWaError', [str_repeat('Kesalahan perangkat. ', 20)]), $p->call('shortWaError', ['']), $p->call('shortWaError', ["a  b\n c"])];
        $mention = [];
        foreach ([['6281200000101@s.whatsapp.net', 'Teknisi Satu'], ['6281200000101@c.us', ''], ['123456789@lid', 'Teknisi Dua'], ['', 'Teknisi Tiga'], ['', '']] as [$jid, $name]) {
            $mention[] = $p->call('mentionPrefixForMigration', [$this->row(211, ['sender_jid' => $jid, 'sender_name' => $name])]);
        }
        $out['mention_prefix'] = $mention;
        $out['rollback_hint'] = [
            $p->call('migrationRollbackHint', [$this->row(212, ['old_sn' => 'ZTEGA0000011', 'old_onu_ref' => '1/1/1:5']), ['customer_before' => ['onu_serial' => 'ZTEGA0000012', 'olt_port_label' => '1/1/1:6']]]),
            $p->call('migrationRollbackHint', [$this->row(213), []]),
        ];

        return $out;
    }

    /** @return array<string, mixed> */
    private function normalizers(): array
    {
        $p = $this->probe;
        $each = function (string $method, array $inputs) use ($p): array {
            $out = [];
            foreach ($inputs as $input) {
                $out[(string) $input] = $p->call($method, [$input]);
            }

            return $out;
        };
        foreach (['m0_flag_one' => '1', 'm0_flag_zero' => '0', 'm0_flag_true' => 'true', 'm0_flag_off' => 'off', 'm0_flag_word' => 'banana'] as $key => $value) {
            Setting::set($key, $value);
        }
        $bool = [];
        foreach (['m0_flag_one', 'm0_flag_zero', 'm0_flag_true', 'm0_flag_off', 'm0_flag_word', 'm0_flag_missing'] as $key) {
            $bool[$key] = [$p->call('boolSetting', [$key, false]), $p->call('boolSetting', [$key, true])];
        }
        $bounded = [];
        foreach (['plain error', "multi\n line   error, SQL: select * from customers where id = 1", str_repeat('x', 2100)] as $i => $message) {
            $bounded['string_'.$i] = $p->call('boundedError', [$message]);
        }
        $bounded['exception'] = $p->call('boundedError', [new \RuntimeException('Router tidak terhubung')]);

        return [
            'serial' => $each('normalizeSerial', ['fhtt-c000 0a7f', ' tidak terbaca ', 'TIDAKTERBACA', 'FHTTC0000A7F', '']),
            'username' => $each('normalizeUsername', ['DRAFTE SANTOSO_RT01', 'a b/c.d-e', '  x  ', '']),
            'onu_ref' => $each('normalizeOnuRef', ['gpon-onu_1/2/2:3', '1/2/2:3', '1/2/2', 'junk', '']),
            'pon_port' => $each('normalizePonPort', ['gpon-olt_1/2/2', '1/2/2:5', '1/2/2', 'x', '']),
            'rw_number' => $each('rwNumber', ['RW 4', '12', 'abc', '0', '1234']),
            'text_compare' => $each('normalizeTextForCompare', ['ODP-KRN 01.2', ' A b ', '']),
            'site_token' => $each('normalizeSiteToken', ['OLT GADANG 3', 'Gadang', '']),
            'olt_token' => $each('normalizeOltToken', ['OLT-KRIAN', 'kriAN', '']),
            'odp_suffix' => $each('odpSuffix', ['ODP-KRN-01', 'ODP-L3.1', 'ODP-A12', 'ODP', '']),
            'onu_name_token' => $each('onuNameToken', ['USERX7', 'USERX27_RT1_RW2', 'a-b c', '']),
            'bounded_error' => $bounded,
            'bool_setting' => $bool,
        ];
    }

    /** @return array<string, mixed> */
    private function routing(): array
    {
        $p = $this->probe;
        $parser = new CustomerMigrationMessageParser;
        $texts = [];
        foreach ((array) require dirname(__DIR__, 2).'/Fixtures/migration_chat_corpus.php' as $entry) {
            $texts[(string) $entry['name']] = (string) $entry['message'];
        }
        $texts += [
            'short_krian' => "#migrasi\nPPPoE: USERBUDI_RT01_RW02\nSN baru: FHTTC0FFEE01\nOLT: KRIAN",
            'short_ra' => "#migrasi\nPPPoE: USERBUDI_RT01_RW02\nSN baru: FHTTC0FFEE02\nRouter baru: SKYNET-RA-OLT",
            'short_gadang_olt' => "#migrasi\nPPPoE: USERBUDI_USERGDG_RT01_RW02\nSN baru: HWTCC0FFEE03\nOLT: OLT GADANG 3",
            'short_gadang_router' => "#migrasi\nPPPoE: USERBUDI_USERGDG_RT01_RW02\nSN baru: HWTCC0FFEE04\nRouter baru: Gadang",
            'short_no_site' => "#migrasi\nPPPoE: USERBUDI_RT01_RW02\nSN baru: FHTTC0FFEE05",
            'huawei_mode_line' => "#migrasi\nMode: OLT_ONLY_HUAWEI\nPPPoE: USERBUDI_USERGDG_RT01_RW02\nSN baru: HWTCC0FFEE06",
        ];
        $out = [];
        foreach ($texts as $label => $text) {
            $parsed = $parser->parse($text);
            $site = $p->call('resolveMigrationTargetSite', [$parsed]);
            $out[$label] = [
                'is_olt_short_form' => $p->call('isOltShortForm', [$parsed]),
                'is_huawei_olt_only' => $p->call('isHuaweiOltOnlyParsed', [$parsed]),
                'use_c300_uncfg_path' => $p->call('shouldUseC300UncfgPath', [$parsed]),
                'site' => is_array($site) ? ($site['key'] ?? '?') : null,
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function plans(): array
    {
        $p = $this->probe;
        $customerBefore = fn (string $odp) => ['customer_before' => ['pppoe_user' => 'USERBUDI_RT01_RW02', 'odp_name' => $odp]];
        $plan = [];
        foreach (['same_odp' => ['ODP-KRN-01', 'ODP-KRN-01'], 'new_odp_suffix' => ['ODP-KRN-01', 'ODP-KRN-02'], 'new_odp_no_suffix' => ['ODP-KRN-01', 'ODP-BARU'], 'no_odp' => ['', '']] as $label => [$old, $new]) {
            $plan[$label] = $p->call('pppoeMigrationPlan', [$this->row(301, ['odp' => $new]), $customerBefore($old)]);
        }
        $comments = [
            'cross_router' => $this->row(302, ['raw_text' => '#migrasi'], ['cross_router' => true, 'router_mapping' => ['source_input' => 'Krian', 'target_input' => 'Skynet-Krian-OLT'], 'keterangan' => 'pindah router']),
            'same_router' => $this->row(303, ['raw_text' => '#migrasi'], []),
            'keterangan_in_text' => $this->row(304, ['raw_text' => "#migrasi\nKeterangan: ganti perangkat"], []),
        ];
        $secretComment = [];
        foreach ($comments as $label => $row) {
            $secretComment[$label] = $p->call('migrationSecretComment', [$row]);
        }
        $kept = [];
        foreach (['live' => [['found' => true, 'profile' => '20MB'], '10MB'], 'fallback' => [['found' => null], '10MB'], 'none' => [['found' => false], '']] as $label => [$secret, $profile]) {
            $kept[$label] = $p->call('keptPackageFallbackProfile', [$secret, $profile]);
        }
        $hint = [];
        foreach ([[], ['pppoe_missing'], ['package_profile_missing:package_none']] as $reasons) {
            $hint[implode(',', $reasons) ?: 'none'] = $p->call('migrationReasonHint', [$reasons]);
        }
        $profile = [];
        foreach (['SD NEGERI 1', 'MI AL HIDAYAH', 'DRAFTE SANTOSO', 'PONDOK PESANTREN X'] as $name) {
            $profile[$name] = [$p->call('targetProfileForMigration', [$this->row(305, ['full_name' => $name]), $this->person('budi')]), $p->call('isSchoolOrInstitutionName', [$name])];
        }
        $photo = [];
        foreach ([['#migrasi ktp #1', 'sn'], ['foto stiker fiberhome', 'ktp'], ['', 'ktp'], ['', 'unknown'], ['caption bebas', 'sn']] as [$hintText, $detected]) {
            $photo[$hintText.'|'.$detected] = $p->call('photoLabelFromHint', [$hintText, $detected]);
        }
        $preserve = [];
        foreach ([[15, 'Krian'], [514, 'Skynet-Krian-OLT'], [513, 'SKYNET-RA-OLT'], [0, ''], [511, '']] as [$routerId, $name]) {
            $preserve[$routerId.'|'.$name] = $p->call('migrationPreservesCustomerPackage', [$routerId, $name]);
        }

        return ['pppoe_plan' => $plan, 'secret_comment' => $secretComment, 'kept_package_profile' => $kept, 'reason_hint' => $hint, 'target_profile' => $profile, 'photo_label' => $photo, 'preserves_package' => $preserve];
    }
}
