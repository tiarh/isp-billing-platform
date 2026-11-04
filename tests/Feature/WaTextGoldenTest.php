<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\Package;
use App\Models\PsbDraft;
use App\Models\Router;
use App\Models\Setting;
use App\Services\CustomerMigrationService;
use App\Services\Migration\CustomerMigrationWaMessages;
use App\Services\PsbWaMessages;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Golden snapshot of the PSB WA and migration WA text builders.
 *
 * The fixtures hold the exact bytes the live code renders today. Any change in
 * wording, spacing, emoji or encoding fails this test. The fixtures are only
 * written when they do not exist yet (first run on the unchanged code); after
 * that they are compared byte for byte. CI never creates them.
 */
#[Group('blocking-psb')]
class WaTextGoldenTest extends TestCase
{
    use RefreshDatabase;

    private const PSB_FIXTURE = 'tests/Fixtures/wa-text/psb-wa-messages.golden.json';

    private const MIGRATION_FIXTURE = 'tests/Fixtures/wa-text/migration-wa.golden.json';

    private const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-22 10:00:00', 'Asia/Jakarta'));
    }

    public function test_psb_wa_messages_match_golden_fixture(): void
    {
        $this->assertMatchesGolden(self::PSB_FIXTURE, $this->renderPsb());
    }

    public function test_migration_wa_messages_match_golden_fixture(): void
    {
        $this->assertMatchesGolden(self::MIGRATION_FIXTURE, $this->renderMigration());
    }

    /**
     * @param  array<string, string>  $rendered
     */
    private function assertMatchesGolden(string $relativePath, array $rendered): void
    {
        $this->assertNotSame([], $rendered);
        foreach ($rendered as $key => $text) {
            $this->assertIsString($text, $key);
            $this->assertStringNotContainsString('<PW>', $text, $key);
            $this->assertStringNotContainsString('<UN>', $text, $key);
            // Credential labels are stored as placeholders so the fixture files do not
            // read like login pairs to secret scanners. The swap is reversible and
            // applied on every run, so the comparison stays exact.
            $rendered[$key] = str_replace(['Username', 'Password'], ['<UN>', '<PW>'], $text);
        }

        $path = base_path($relativePath);
        $actual = json_encode($rendered, self::JSON_FLAGS)."\n";

        if (! is_file($path)) {
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }
            file_put_contents($path, $actual);
            $this->fail("Golden fixture did not exist and was created: {$relativePath}. Commit it and rerun.");
        }

        $expected = (string) file_get_contents($path);
        $this->assertSame($expected, $actual, "WA text differs from golden fixture {$relativePath}");
        $this->assertGreaterThan(40, count($rendered));
    }

    // ------------------------------------------------------------------
    // PSB
    // ------------------------------------------------------------------

    /**
     * @return array<string, string>
     */
    private function renderPsb(): array
    {
        $router = new Router;
        $router->forceFill([
            'id' => 3, 'name' => 'RA-OLT', 'ip_address' => '127.0.0.1', 'username' => 'admin',
            'password' => 'canary-pw', 'is_active' => true,
        ])->save();
        $package = new Package;
        $package->forceFill([
            'id' => 5, 'code' => 'PK5', 'name' => 'Paket 200M', 'price' => 150000, 'mikrotik_profile' => '200M',
        ])->save();

        $m = new PsbWaMessages;

        $full = $this->draft([
            'id' => 42, 'full_name' => 'DRAFTE SANTOSO', 'sn' => 'FHTT12345678', 'pon_port' => '1/3/9',
            'vlan' => '309', 'pppoe_user' => 'BUDI_RT1_RW4_01', 'mikrotik_profile' => '200M',
            'router_id' => 3, 'package_id' => 5, 'daerah' => 'Krian', 'status' => 'olt_ok',
            'activation_mode' => 'pppoe_vlan', 'onu_id' => 7, 'tr069_vlan' => 69, 'last_error' => 'timeout ke OLT',
        ]);
        $empty = $this->draft(['id' => 7]);
        $unicode = $this->draft([
            'id' => 1234, 'full_name' => 'Ni Made Rina Contoh Dewi Åñgel Ç é ü 🙂 '.str_repeat('PANJANGSEKALI', 20),
            'sn' => 'FHTTÅÑ01', 'pon_port' => '1/10/16', 'vlan' => '', 'pppoe_user' => 'ÑOMAN_RT01',
            'mikrotik_profile' => 'STARTLITE', 'daerah' => 'Sidoarjo Kôta', 'status' => 'failed',
            'meta' => ['package_hint' => 'STARTLITE-HINT'],
        ]);
        $htb = $this->draft([
            'id' => 88, 'full_name' => 'HTB USER', 'sn' => 'FHTT00000001', 'pon_port' => '1/1/1', 'vlan' => '100',
            'pppoe_user' => 'HTBUSER', 'daerah' => 'Randuagung', 'activation_mode' => 'pppoe_vlan',
            'status' => 'pending_vlan',
        ]);
        $correction = $this->draft([
            'id' => 55, 'full_name' => 'KOREKSI', 'pppoe_user' => 'KOR_01', 'status' => 'correction_pending_review',
            'meta' => ['psb_correction' => [
                'diff' => [
                    'odp' => ['old' => 'ODP-A', 'new' => 'ODP-B'],
                    'sn' => ['old' => 'FHTT0001', 'new' => 'FHTT0002'],
                    'rt' => 'bukan-array',
                    'pppoe_user' => ['new' => 'BARU'],
                ],
                'preflight' => [
                    'mikrotik' => ['old_secret' => 'ada', 'new_secret' => 'tidak', 'active' => 'no'],
                    'olt' => ['snapshot' => 'ok'],
                ],
                'steps' => ['mikrotik' => 'renamed', 'olt' => 'skipped'],
            ]],
        ]);
        $correctionEmpty = $this->draft(['id' => 56, 'meta' => ['psb_correction' => ['preflight' => ['mikrotik' => ['error' => 'router timeout']]]]]);

        $out = [];

        $out['hr'] = $m->hr();
        $out['header'] = $m->header('✅', 'Judul *uji*');
        $out['block.filters_empty_and_null'] = $m->block(['a', '', 'b', 'Ã¢â€“Â¶Ã¯Â¸Â mojibake', 'c']);

        // received / draftUpdated
        $out['received.name'] = $m->received(42, 'BUDI');
        $out['received.null'] = $m->received(42, null);
        $out['received.empty_name'] = $m->received(42, '');
        $out['received.unicode'] = $m->received(9, 'Rina Contoh Åñgel 🙂');
        foreach ([true, false] as $sn) {
            foreach (['BUDI', null, ''] as $name) {
                $out['draftUpdated.sn='.($sn ? '1' : '0').'.name='.var_export($name, true)] = $m->draftUpdated(42, $name, $sn);
            }
        }
        $out['draftUpdated.default_args'] = $m->draftUpdated(3);
        $out['nearMissNeedHash'] = $m->nearMissNeedHash();

        // photo / document
        $out['waitingPhoto.full'] = $m->waitingPhoto($full);
        $out['waitingPhoto.empty'] = $m->waitingPhoto($empty);
        $out['waitingPhoto.unicode'] = $m->waitingPhoto($unicode);
        $out['ktpUnreadable.normal'] = $m->ktpUnreadable($full);
        $out['ktpUnreadable.slow'] = $m->ktpUnreadable($full, true);
        $out['ktpUnreadable.empty'] = $m->ktpUnreadable($empty);
        $out['ktpCaptionNeedsPhoto'] = $m->ktpCaptionNeedsPhoto(42);
        $out['documentBlocked.missing'] = $m->documentBlocked($full, ['status' => 'missing', 'reason' => 'ktp_required']);
        $out['documentBlocked.no_gate'] = $m->documentBlocked($empty, []);
        $out['documentBlocked.ktp_missing'] = $m->documentBlocked($full, ['status' => 'missing', 'reason' => 'ktp_missing']);
        $out['documentBlocked.wrong_media_path'] = $m->documentBlocked($full, ['status' => 'invalid_media', 'reason' => 'wrong_media_path']);
        $out['documentBlocked.ktp_score_too_low'] = $m->documentBlocked($full, ['status' => 'invalid_media', 'reason' => 'ktp_score_too_low']);
        $out['documentBlocked.object_missing'] = $m->documentBlocked($full, ['status' => 'invalid_media', 'reason' => 'object_missing']);
        $out['documentBlocked.storage_unavailable'] = $m->documentBlocked($full, ['status' => 'invalid_media', 'reason' => 'storage_unavailable']);
        $out['documentBlocked.invalid_image'] = $m->documentBlocked($full, ['status' => 'invalid_media', 'reason' => 'invalid_image']);
        $out['documentBlocked.invalid_size'] = $m->documentBlocked($full, ['status' => 'invalid_media', 'reason' => 'invalid_size']);
        $out['documentBlocked.pending_followup'] = $m->documentBlocked($full, ['status' => 'pending_followup', 'reason' => 'reason_required']);
        $out['documentBlocked.exempt_csr'] = $m->documentBlocked($full, ['status' => 'exempt_csr', 'reason' => 'csr_not_eligible']);
        $out['documentBlocked.custom'] = $m->documentBlocked($unicode, ['status' => 'unreadable', 'reason' => 'ocr_gagal']);
        $out['ktpPhotoAck.read'] = $m->ktpPhotoAck($full, true);
        $out['ktpPhotoAck.reading'] = $m->ktpPhotoAck($full, false);
        $out['ktpPhotoAck.empty'] = $m->ktpPhotoAck($empty, false);
        $out['mediaValidationPending.full'] = $m->mediaValidationPending($full);
        $out['mediaValidationPending.empty'] = $m->mediaValidationPending($empty);

        // SN / VLAN / HTB
        $out['waitingSn.full'] = $m->waitingSn($full);
        $out['waitingSn.empty'] = $m->waitingSn($empty);
        $out['waitingSn.unicode'] = $m->waitingSn($unicode);
        $out['pendingVlan.full'] = $m->pendingVlan($full);
        $out['pendingVlan.empty'] = $m->pendingVlan($empty);
        $out['pendingVlan.unicode'] = $m->pendingVlan($unicode);
        $out['pendingVlanHtb.htb'] = $m->pendingVlanHtb($htb);
        $out['pendingVlanHtb.empty'] = $m->pendingVlanHtb($empty);
        $out['htbComplete.cred'] = $m->htbComplete($htb, 'RA-OLT', 'canary-pw');
        $out['htbComplete.nocred'] = $m->htbComplete($htb, 'RA-OLT');
        $out['htbComplete.empty'] = $m->htbComplete($empty, 'Krian', "bad\ncred");
        $out['huaweiPsbDone.cred'] = $m->huaweiPsbDone($full, 'canary-pw');
        $out['huaweiPsbDone.nocred'] = $m->huaweiPsbDone($empty);
        $out['huaweiPsbDone.ctrl_char'] = $m->huaweiPsbDone($full, "pw\x07x");

        // PPPoE ready
        $out['message1PppoeReady.full_cred'] = $m->message1PppoeReady($full, 'canary-pw');
        $out['message1PppoeReady.full_nocred'] = $m->message1PppoeReady($full);
        $out['message1PppoeReady.empty'] = $m->message1PppoeReady($empty);
        $out['message1PppoeReady.unicode_hint'] = $m->message1PppoeReady($unicode, '  ');
        $out['message1PppoeReady.newline_cred'] = $m->message1PppoeReady($full, "a\nb");
        $out['packageLabel.package'] = $m->packageLabel($full);
        $out['packageLabel.hint'] = $m->packageLabel($unicode);
        $out['packageLabel.empty'] = $m->packageLabel($empty);
        $out['message2OltDone.cred'] = $m->message2OltDone($full, 'https://acs.example.test:7547', 'canary-pw');
        $out['message2OltDone.empty'] = $m->message2OltDone($empty, 'https://acs.example.test:7547');

        // acks and empty states
        $out['doneAck'] = $m->doneAck($full);
        $out['doneAck.empty'] = $m->doneAck($empty);
        $out['lanjutAck'] = $m->lanjutAck($full);
        $out['noDraftWaiting'] = $m->noDraftWaiting();
        $out['noPendingVlan'] = $m->noPendingVlan();
        $out['draftNotFound'] = $m->draftNotFound(99);
        foreach (['received', 'waiting_sn', 'pending_vlan', 'secret_ok', 'olt_ok', 'applied', 'correction_pending_review', 'failed', 'timeout', 'cancelled', 'lain_lain'] as $status) {
            $out['statusLabel.'.$status] = $m->statusLabel($status);
            $out['draftStatusSkip.'.$status] = $m->draftStatusSkip(12, $status);
        }

        // config / incomplete / errors
        $out['configIncomplete'] = $m->configIncomplete();
        foreach ([false, true] as $ambiguous) {
            foreach ([true, false] as $noWrite) {
                $out['areaNeeded.amb='.(int) $ambiguous.'.nowrite='.(int) $noWrite] = $m->areaNeeded($full, $ambiguous, $noWrite);
            }
        }
        $out['areaNeeded.no_daerah'] = $m->areaNeeded($empty);
        $out['areaNeeded.unicode_daerah'] = $m->areaNeeded($unicode, true);
        $out['areaNeeded.long_daerah'] = $m->areaNeeded($this->draft(['id' => 3, 'daerah' => $this->longText(300)]));

        $out['incomplete.none'] = $m->incomplete([]);
        $out['incomplete.two'] = $m->incomplete(['Nama kosong', 'SN tidak valid']);
        $out['incomplete.unicode_long'] = $m->incomplete(['Daerah "Kôta Åñgel" 🙂', $this->longText(200), $this->longText(300)]);
        $out['incomplete.empty_item'] = $m->incomplete(['', '   ']);

        $errors = $this->errorTexts();
        foreach ($errors as $label => $text) {
            $out['pppoeFailed.'.$label] = $m->pppoeFailed($text);
            $out['oltFailedAfterSecret.'.$label] = $m->oltFailedAfterSecret($text);
            $out['jobError.'.$label] = $m->jobError($text);
            $out['psbCorrectionBlocked.'.$label] = $m->psbCorrectionBlocked($full, $text);
            $out['ipPoolFull.'.$label] = $m->ipPoolFull($text);
        }
        $out['ipPoolFull.default'] = $m->ipPoolFull();

        $out['oltBusyQueued'] = $m->oltBusyQueued($full);
        $out['snTimeout.full'] = $m->snTimeout($full);
        $out['snTimeout.empty'] = $m->snTimeout($empty);
        $out['snTimeout.unicode'] = $m->snTimeout($unicode);

        // draft check / credentials
        $out['draftCheck.default'] = $m->draftCheck($full);
        $out['draftCheck.active_detail'] = $m->draftCheck($full, ['pppoe_live' => true, 'pppoe_detail' => 'uptime 1d', 'rx_dbm' => -21.5], 'canary-pw');
        $out['draftCheck.inactive_rx_cukup'] = $m->draftCheck($full, ['pppoe_live' => false, 'rx_dbm' => -26.04]);
        $out['draftCheck.rx_lemah'] = $m->draftCheck($full, ['pppoe_live' => false, 'pppoe_detail' => 'x', 'rx_dbm' => -30.0]);
        $out['draftCheck.rx_note'] = $m->draftCheck($empty, ['pppoe_live' => null, 'rx_note' => 'OLT tidak terjangkau']);
        $out['draftCheck.unicode_long_error'] = $m->draftCheck($this->draft(['id' => 9, 'full_name' => 'Åñgel 🙂', 'last_error' => $this->longText(300), 'status' => 'failed']));
        foreach ([
            'gate_missing' => 'Dokumen PSB belum valid: missing',
            'gate_ocr_pending' => 'Dokumen PSB belum valid: ocr_pending',
            'gate_invalid_media' => 'Dokumen PSB belum valid: invalid_media',
            'gate_pending_followup' => 'Dokumen PSB belum valid: pending_followup',
            'gate_exempt_csr' => 'Dokumen PSB belum valid: exempt_csr',
            'gate_unknown' => 'Dokumen PSB belum valid: whatever_new_code',
            'olt_raw' => 'OLT: Connection timed out (SSH 198.19.45.1)',
            'sn_mismatch' => 'SN mismatch: chat=AAA uncfg=BBB',
            'raw_exception' => 'SQLSTATE[HY000]: General error: ocr_pending',
            'known_ktp' => 'KTP belum terbaca. Kirim ulang: #psb ktp #9',
            'known_olt_queue' => 'OLT antri: posisi 2',
            'config_psb' => 'Config PSB belum lengkap: Paket',
            'daerah' => 'Daerah `Kota X` belum terdaftar di registry site PSB (anti salah router).',
            'site_htb' => 'Site HTB butuh VLAN service. Isi di Aktivasi→VLAN MikroTik atau kirim: #psb vlan NN #9',
            'write_disabled' => 'PSB write disabled (psb_writes_enabled=0)',
            'olt_busy' => 'OLT: Antrian OLT masih sibuk setelah 5 kali coba otomatis.',
            'olt_huawei_busy' => 'OLT Huawei sibuk; belum ada perintah dikirim. Kirim #psb lanjut.',
        ] as $label => $error) {
            $out['draftCheck.note.'.$label] = $m->draftCheck($this->draft(['id' => 9, 'full_name' => 'Budi', 'last_error' => $error, 'status' => 'failed']));
        }
        $out['resendCredentials.full'] = $m->resendCredentials($full, 'https://acs.example.test:7547', 'canary-pw');
        $out['resendCredentials.nocred'] = $m->resendCredentials($full, 'https://acs.example.test:7547');
        $out['resendCredentials.no_user'] = $m->resendCredentials($empty, 'https://acs.example.test:7547');

        // correction flow
        $out['psbCorrectionPending.full'] = $m->psbCorrectionPending($correction);
        $out['psbCorrectionPending.error'] = $m->psbCorrectionPending($correctionEmpty);
        $out['psbCorrectionPending.empty'] = $m->psbCorrectionPending($empty);
        $out['psbCorrectionCancelled'] = $m->psbCorrectionCancelled($full);
        $out['psbCorrectionCancelled.empty'] = $m->psbCorrectionCancelled($empty);
        $out['psbCorrectionApplied.steps'] = $m->psbCorrectionApplied($correction);
        $out['psbCorrectionApplied.nosteps'] = $m->psbCorrectionApplied($full);
        $out['psbCorrectionApplied.empty'] = $m->psbCorrectionApplied($empty);

        // busy / cancel / SN
        $out['draftBusy'] = $m->draftBusy(42);
        $out['draftBusyAdmin'] = $m->draftBusyAdmin(42);
        $out['secretQueueBusy'] = $m->secretQueueBusy(42, 'RA-OLT');
        $out['cancelled.full'] = $m->cancelled($full);
        $out['cancelled.empty'] = $m->cancelled($empty);
        $out['snUpdated.old'] = $m->snUpdated($full, 'FHTT00000000');
        $out['snUpdated.no_old'] = $m->snUpdated($full, '');
        $out['secretAlreadyExists.cred'] = $m->secretAlreadyExists($full, 'canary-pw');
        $out['secretAlreadyExists.empty'] = $m->secretAlreadyExists($empty);
        $out['snMismatch.both'] = $m->snMismatch($full, 'FHTT0001', 'FHTT0002', '1/3/9');
        $out['snMismatch.no_uncfg'] = $m->snMismatch($full, 'FHTT0001', '', '1/3/9');
        $out['needDraftId'] = $m->needDraftId('#psb lanjut');

        // today report
        $stats = [
            'label' => 'Kamis, 22 Okt 2026', 'total' => 12, 'olt_ok' => 5, 'secret_ok' => 3, 'wait_sn' => 2,
            'pend_vlan' => 1, 'failed' => 1, 'applied' => 4,
        ];
        $out['todayReport.minimal'] = $m->todayReport($stats);
        $out['todayReport.source_counts'] = $m->todayReport($stats + ['source_counts' => ['wa' => 9, 'manual' => 3]]);
        $out['todayReport.sync_attention'] = $m->todayReport($stats + [
            'source_counts' => ['wa' => 1],
            'device_sync' => [
                'olt_online' => 8, 'pppoe_linked' => 7,
                'attention' => [
                    ['id' => 1, 'name' => 'A', 'status' => 'failed'], ['id' => 2, 'name' => 'B', 'status' => 'timeout'],
                    ['id' => 3, 'name' => 'C', 'status' => 'x'], ['id' => 4, 'name' => 'D', 'status' => 'x'],
                    ['id' => 5, 'name' => 'E', 'status' => 'x'], ['id' => 6, 'name' => 'F', 'status' => 'x'],
                ],
            ],
        ]);
        $out['todayReport.device_sync_no_olt_online'] = $m->todayReport($stats + ['device_sync' => ['pppoe_linked' => 1]]);
        $out['todayReport.pending_rows'] = $m->todayReport($stats + [
            'source_counts' => ['wa' => 2, 'manual' => 1],
            'pending_rows' => [
                ['id' => 10, 'name' => 'ÅÑGEL 🙂', 'pon' => '1/3/9', 'sn' => 'FHTT0001', 'router' => 'RA-OLT'],
                ['id' => 11, 'name' => 'TANPA PON', 'pon' => null, 'sn' => null, 'router_name' => 'Krian'],
                ['id' => 12, 'name' => 'SITE', 'pon' => '', 'sn' => '', 'site_profile_key' => 'htb-1'],
                ['id' => 13, 'name' => 'NOROUTER', 'pon' => '1/1/1', 'sn' => 'X'],
            ],
        ]);

        // help (recap line depends on the setting)
        Setting::set('psb_recap_cmd_enabled', 0);
        $out['help.recap_off'] = $m->help();
        Setting::set('psb_recap_cmd_enabled', '1');
        $out['help.recap_on'] = $m->help();
        Setting::set('psb_recap_cmd_enabled', 0);
        $out['inputTemplateExample'] = $m->inputTemplateExample();
        $out['groupPinNotice'] = $m->groupPinNotice();

        // queue list
        $out['queueList.empty'] = $m->queueList([]);
        $out['queueList.rows'] = $m->queueList([
            ['id' => 1, 'status' => 'waiting_sn', 'full_name' => 'A', 'sn' => 'FHTT1'],
            ['id' => 2, 'status' => 'pending_vlan', 'full_name' => 'B', 'sn' => '', 'router_name' => 'RA-OLT', 'odp' => 'ODP-1', 'pppoe_user' => 'B_01'],
            ['id' => 3, 'status' => 'failed', 'full_name' => 'Åñgel 🙂', 'sn' => null, 'daerah' => 'Krian'],
            ['status' => null],
            $this->draft(['id' => 4, 'status' => 'olt_ok', 'full_name' => 'OBJ', 'sn' => 'FHTT4', 'odp' => 'ODP-4']),
        ]);

        // area recap + recap texts
        $out['areaRecap.names'] = $m->areaRecap($this->recapData(false, 3));
        $out['areaRecap.no_names_over_limit'] = $m->areaRecap($this->recapData(false, 3), 2);
        $out['areaRecap.all'] = $m->areaRecap($this->recapData(true, 0));
        $out['areaRecap.zero'] = $m->areaRecap($this->recapData(false, 0, 0));
        $out['areaRecap.truncated'] = $m->areaRecap($this->recapData(false, 400, 400), 500);
        $out['recapUsage'] = $m->recapUsage();
        $out['recapAreaAmbiguous'] = $m->recapAreaAmbiguous('klam', ['Klampok', 'Klampok Barat', 'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H']);
        $out['recapAreaUnmatched.names'] = $m->recapAreaUnmatched($this->longText(60), ['Krian', 'Randuagung']);
        $out['recapAreaUnmatched.none'] = $m->recapAreaUnmatched('xyz', []);
        $out['recapInvalidDate'] = $m->recapInvalidDate();
        $out['recapRangeTooLong'] = $m->recapRangeTooLong(92);
        $out['recapRateLimitedGroup'] = $m->recapRateLimitedGroup();
        $out['recapRateLimitedSender'] = $m->recapRateLimitedSender();
        $out['recapDocumentFailed'] = $m->recapDocumentFailed();
        $out['recapInternalError'] = $m->recapInternalError();

        // PSB feature PR (s30)
        $out['severalDraftsNeedId'] = $m->severalDraftsNeedId();
        $out['psbSameNameOtherCustomer'] = $m->psbSameNameOtherCustomer($this->draft(['id' => 42]));

        return $out;
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function draft(array $attrs): PsbDraft
    {
        $draft = new PsbDraft;
        $draft->forceFill($attrs);

        return $draft;
    }

    private function longText(int $length): string
    {
        $base = 'Gagal koneksi ke Router Sidoarjo — pelanggan "Nyoman Åñgel" tidak ditemukan 🙂 coba lagi; ';

        return mb_substr(str_repeat($base, (int) ceil($length / mb_strlen($base)) + 1), 0, $length);
    }

    /**
     * Error texts that exercise every length bucket of safeError().
     *
     * @return array<string, string>
     */
    private function errorTexts(): array
    {
        return [
            'short' => 'profile KALIREJO-NET belum mapping',
            'empty' => '',
            'spaces' => "   \n\t  ",
            'multiline' => "baris satu\n\n   baris   dua\tTab",
            'len160' => $this->longText(160),
            'len161' => $this->longText(161),
            'len200' => $this->longText(200),
            'len221' => $this->longText(221),
            'len300' => $this->longText(300),
            'len1000' => $this->longText(1000),
            'ascii300' => str_repeat('x', 300),
            'password' => 'login gagal password=canary-pw token: canary-token user admin',
            'indonesian' => 'Pelanggan Ñoman Åñgel tidak dapat dihubungi — jaringan "terputus" 🙂',
            'emoji_boundary' => str_repeat('a', 156).'🙂🙂🙂🙂',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function recapData(bool $all, int $rowCount, ?int $total = null): array
    {
        $rows = [];
        for ($i = 1; $i <= $rowCount; $i++) {
            $rows[] = [
                'join_date' => '2026-10-'.str_pad((string) (($i % 20) + 1), 2, '0', STR_PAD_LEFT),
                'name' => 'Pelanggan Åñgel '.$i.' '.str_repeat('N', 40),
                'package' => 'Paket Panjang 200M '.$i,
                'rw' => $i % 3 === 0 ? null : '0'.($i % 5),
                'customer_status' => $i % 2 === 0 ? 'aktif' : 'belum aktif',
            ];
        }

        return [
            'all' => $all,
            'total' => $total ?? $rowCount,
            'area_label' => $all ? 'SEMUA AREA' : 'Klampok',
            'from' => '2026-10-01',
            'to' => '2026-10-14',
            'days' => 14,
            'generated_at' => CarbonImmutable::parse('2026-10-22 10:00:00', 'Asia/Jakarta'),
            'by_status' => ['aktif' => 5, 'belum aktif' => 2, 'isolir' => 1, 'putus' => 0],
            'by_source' => ['WA' => 6, 'Non-WA' => 2],
            'excluded_migration' => $all ? 3 : 0,
            'by_date' => ['2026-10-01' => 2, '2026-10-02' => 1, '2026-10-03' => 1, '2026-10-04' => 1, '2026-10-05' => 1, '2026-10-06' => 2],
            'by_package' => [
                'P1' => 9, 'P2' => 8, 'P3' => 7, 'P4' => 6, 'P5' => 5, 'P6' => 4, 'P7' => 3,
                'P8' => 3, 'P9' => 2, 'P10' => 2, 'P11' => 2, 'P12' => 1, 'P13' => 1, 'P14 '.str_repeat('Z', 40) => 1,
            ],
            'by_area' => ['Klampok' => 4, 'Krian' => 3, 'Ñandi' => 1],
            'by_rw' => ['01' => 3, '02' => 2, '03' => 1],
            'rows' => $rows,
        ];
    }

    // ------------------------------------------------------------------
    // Migration
    // ------------------------------------------------------------------

    /**
     * @return array<string, string>
     */
    private function renderMigration(): array
    {
        $service = app(CustomerMigrationService::class);
        $call = function (string $method, mixed ...$args) use ($service): string {
            $ref = new \ReflectionMethod(CustomerMigrationService::class, $method);
            $ref->setAccessible(true);

            return (string) $ref->invoke($service, ...$args);
        };

        $router = new Router;
        $router->forceFill([
            'id' => 3, 'name' => 'RA-OLT', 'ip_address' => '127.0.0.1', 'username' => 'admin',
            'password' => 'canary-pw', 'is_active' => true,
        ])->save();
        $package = new Package;
        $package->forceFill(['id' => 5, 'code' => 'PK5', 'name' => 'Paket 200M', 'price' => 150000])->save();

        $bare = $this->customer(11, $router, $package, '', null);
        $withPhoneOnly = $this->customer(12, $router, $package, '081200000012', null);
        $withKtpOnly = $this->customer(13, $router, $package, '', 'https://example.test/ktp.jpg');
        $complete = $this->customer(14, $router, $package, '081200000014', 'https://example.test/ktp2.jpg');

        $mNoCustomer = $this->migration(20, null);
        $mBare = $this->migration(21, $bare->id);
        $mPhoneOnly = $this->migration(22, $withPhoneOnly->id);
        $mKtpOnly = $this->migration(23, $withKtpOnly->id);
        $mComplete = $this->migration(24, $complete->id);
        $mDraftPhone = $this->migration(25, $bare->id, ['phone' => '081200000099', 'ktp_photo_path' => 'ktp/25.jpg']);
        $mDryRun = $this->migration(26, $bare->id, [], ['device_action_approval' => ['status' => 'dry_run_ready']]);
        $mOtherApproval = $this->migration(27, $complete->id, [], ['device_action_approval' => ['status' => 'planned']]);
        $mDryRunComplete = $this->migration(28, $complete->id, [], ['device_action_approval' => ['status' => 'dry_run_ready']]);
        $mHighId = $this->migration(12345, null);

        $candidates = [
            ['number' => 1, 'name' => 'DRAFTE SANTOSO', 'score' => 95, 'pppoe_user' => 'BUDI_RT1_RW4_01', 'old_sn' => 'FHTT0001', 'old_onu_ref' => '1/3/9:4'],
            ['number' => 2, 'name' => 'Ñoman Åñgel 🙂', 'score' => 70, 'pppoe_user' => 'NOMAN', 'old_sn' => '', 'old_onu_ref' => ''],
            'bukan-array',
            ['number' => 4],
            ['number' => 5, 'name' => 'KELIMA', 'score' => 1],
            ['number' => 6, 'name' => 'KEENAM', 'score' => 1],
            ['number' => 7, 'name' => 'KETUJUH TERPOTONG', 'score' => 1],
        ];

        $previews = [
            'default' => [],
            'needs_review' => ['status' => 'needs_review'],
            'ready' => ['status' => 'ready'],
            'missing' => ['status' => 'needs_review', 'missing' => ['nama', '', 'sn baru', 'hp']],
            'candidates' => ['status' => 'needs_review', 'candidates' => $candidates],
            'candidates0' => ['status' => 'ready', 'candidates' => []],
            'missing_candidates' => ['status' => 'needs_review', 'missing' => ['sn baru'], 'candidates' => $candidates, 'next_action' => '#migrasi pilih #ID <nomor>'],
            'next_custom' => ['status' => 'ready', 'next_action' => '#migrasi konfirmasi #ID sekarang'],
            'next_no_id' => ['status' => 'ready', 'next_action' => 'Tunggu NOC'],
        ];
        $drafts = [
            'nodraft' => null,
            'draft_nocustomer' => $mNoCustomer,
            'draft_bare' => $mBare,
            'draft_phone_only' => $mPhoneOnly,
            'draft_ktp_only' => $mKtpOnly,
            'draft_complete' => $mComplete,
            'draft_phone_ktp_on_draft' => $mDraftPhone,
            'draft_dryrun_ready' => $mDryRun,
            'draft_other_approval' => $mOtherApproval,
            'draft_dryrun_complete' => $mDryRunComplete,
        ];
        $errors = [
            'noerr' => null,
            'err_short' => 'router timeout',
            'err_empty' => '',
            'err_spaces' => "  \n ",
            'err_long' => $this->longText(300),
            'err_len160' => $this->longText(160),
            'err_len161' => $this->longText(161),
            'err_pw' => 'gagal login password=canary-pw token: canary-token',
            'err_unicode' => 'Pelanggan Ñoman Åñgel tidak ketemu — "putus" 🙂',
        ];

        $out = [];
        foreach ($previews as $pName => $preview) {
            foreach ($drafts as $dName => $draft) {
                foreach (['noerr', 'err_short'] as $eName) {
                    $out["guidedPreviewMessage.{$pName}.{$dName}.{$eName}"] = $call('guidedPreviewMessage', $preview, $draft, $errors[$eName]);
                }
            }
        }
        foreach ($errors as $eName => $error) {
            foreach (['nodraft' => null, 'draft_bare' => $mBare, 'draft_dryrun_ready' => $mDryRun] as $dName => $draft) {
                $out["guidedPreviewMessage.err_matrix.{$dName}.{$eName}"] = $call('guidedPreviewMessage', $previews['missing_candidates'], $draft, $error);
            }
        }
        $out['guidedPreviewMessage.args_default'] = $call('guidedPreviewMessage', ['status' => 'ready']);

        $out['helpMessage'] = $call('helpMessage');

        $out['candidateSelectionPreviewMessage.null_0'] = $call('candidateSelectionPreviewMessage', null, 0);
        $out['candidateSelectionPreviewMessage.null_3'] = $call('candidateSelectionPreviewMessage', null, 3);
        $out['candidateSelectionPreviewMessage.mig_0'] = $call('candidateSelectionPreviewMessage', $mBare, 0);
        $out['candidateSelectionPreviewMessage.mig_neg'] = $call('candidateSelectionPreviewMessage', $mBare, -1);
        $out['candidateSelectionPreviewMessage.mig_2'] = $call('candidateSelectionPreviewMessage', $mBare, 2);
        $out['candidateSelectionPreviewMessage.mig_high'] = $call('candidateSelectionPreviewMessage', $mHighId, 15);

        // Migration safety fix A: the global read-only switch on the WA lane (additive rows).
        $texts = new CustomerMigrationWaMessages(fn (): ?array => null);
        $out['readOnlyEksekusiRefused.id_20'] = $texts->readOnlyEksekusiRefused(20);
        $out['readOnlyEksekusiRefused.id_high'] = $texts->readOnlyEksekusiRefused(12345);
        $blockedByReadOnly = $this->migration(29, null, [], ['device_action_approval' => ['status' => 'blocked_kill_switch', 'write_flags' => ['global_read_only' => true]]]);
        $blockedBySwitch = $this->migration(30, null, [], ['device_action_approval' => ['status' => 'blocked_kill_switch', 'write_flags' => ['global_read_only' => false]]]);
        foreach (['read_only' => $blockedByReadOnly, 'switch_off' => $blockedBySwitch] as $name => $blocked) {
            $stop = $texts->executionBlockedStop($blocked);
            $out["executionBlockedStop.{$name}.code"] = $stop['code'];
            $out["executionBlockedStop.{$name}.text"] = $stop['text'];
        }

        return $out;
    }

    private function customer(int $id, Router $router, Package $package, string $phone, ?string $ktpUrl): Customer
    {
        $customer = new Customer;
        $customer->forceFill([
            'id' => $id, 'code' => 'GLD-'.$id, 'name' => 'Pelanggan '.$id, 'phone' => $phone,
            'address' => 'RT 01 RW 01', 'pppoe_user' => 'GOLDEN'.$id, 'package_id' => $package->id,
            'router_id' => $router->id, 'status' => 'active', 'ktp_photo_url' => $ktpUrl,
        ])->save();

        return $customer;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @param  array<string, mixed>  $meta
     */
    private function migration(int $id, ?int $customerId, array $extra = [], array $meta = []): CustomerMigration
    {
        $migration = new CustomerMigration;
        $migration->forceFill(array_merge([
            'id' => $id,
            'status' => CustomerMigration::STATUS_NEEDS_REVIEW,
            'source' => 'guided_lookup_wa',
            'group_jid' => 'activation@g.us',
            'message_id' => 'golden-'.$id,
            'new_sn' => 'FHTTGOLD'.$id,
            'customer_id' => $customerId,
            'meta' => $meta,
        ], $extra))->save();

        return $migration;
    }
}
