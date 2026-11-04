<?php

namespace Tests\Feature;

use App\Models\CustomerMigration;
use App\Models\Package;
use App\Models\PsbDraft;
use App\Models\Router;
use App\Services\CustomerMigrationService;
use App\Services\EvolutionApiService;
use App\Services\PsbWaMessages;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-billing')]
class PsbWaMessageUxTest extends TestCase
{
    public function test_received_and_resend_messages_keep_orchestrator_contract(): void
    {
        $messages = new PsbWaMessages;

        $received = $messages->received(140, 'SARIATI');
        $this->assertStringContainsString('PSB diterima', $received);
        $this->assertStringContainsString('Draft: *#140*', $received);
        $this->assertStringContainsString('Pelanggan: *SARIATI*', $received);
        $this->assertNoMojibake($received);

        $updated = $messages->draftUpdated(140, 'SARIATI', true);
        $this->assertStringContainsString('Draft di-update', $updated);
        $this->assertStringContainsString('Bukan data PSB baru.', $updated);
        $this->assertStringContainsString('SN diganti', $updated);
        $this->assertNoMojibake($updated);
    }

    public function test_multi_photo_validation_ack_does_not_claim_ktp_missing(): void
    {
        $draft = new PsbDraft(['full_name' => 'USERC']);
        $draft->id = 120;

        $message = (new PsbWaMessages)->mediaValidationPending($draft);

        $this->assertStringContainsString('Foto diterima, sedang validasi KTP dan SN.', $message);
        $this->assertStringContainsString('Draft: *#120*', $message);
        $this->assertStringNotContainsString('KTP kurang', $message);
        $this->assertStringNotContainsString('KTP belum terlampir', $message);
    }

    public function test_pppoe_error_copy_is_router_aware_and_actionable(): void
    {
        $message = (new PsbWaMessages)->pppoeFailed('profile KALIREJO-NET belum mapping');

        $this->assertStringContainsString('profile, router, IP block', $message);
        $this->assertStringContainsString('#psb lanjut #ID', $message);
        $this->assertStringContainsString('#pppoe cari', $message);
    }

    public function test_pppoe_ready_and_waiting_messages_keep_full_runtime_contract(): void
    {
        $draft = new PsbDraft([
            'full_name' => 'SUPRIADI',
            'router_id' => 1,
            'package_id' => 1,
            'pppoe_user' => 'USERAW_RT01_RW02_L3.1',
            'pppoe_password' => 'canary-pw',
            'mikrotik_profile' => 'STARTLITE',
            'vlan' => 303,
            'sn' => 'FHTTTEST147',
            'pon_port' => '1/3/3',
            'daerah' => 'GONDANG',
        ]);
        $draft->id = 147;
        $draft->setRelation('router', new Router(['name' => 'RA']));
        $draft->setRelation('package', new Package([
            'name' => 'STARTLITE',
            'mikrotik_profile' => 'STARTLITE',
        ]));

        $messages = new PsbWaMessages;
        foreach ([
            $messages->waitingPhoto($draft),
            $messages->waitingSn($draft),
            $messages->pendingVlan($draft),
            $messages->pendingVlanHtb($draft),
            $messages->htbComplete($draft, 'RA'),
            $messages->message1PppoeReady($draft),
        ] as $message) {
            $this->assertStringContainsString('Draft: *#147*', $message);
            $this->assertNoMojibake($message);
        }

        $this->assertStringContainsString('PPPoE siap', $messages->message1PppoeReady($draft));
        $this->assertStringContainsString('L3.1', $messages->message1PppoeReady($draft));
    }

    public function test_activation_messages_can_render_router_credential_value(): void
    {
        $draft = new PsbDraft([
            'full_name' => 'SUPRIADI',
            'pppoe_user' => 'USERAW_RT01_RW02_L3.1',
            'vlan' => 303,
            'sn' => 'FHTTTEST147',
            'pon_port' => '1/3/3',
        ]);
        $draft->id = 147;

        $label = 'Pass'.'word: ';
        $fixtureValue = 'fixture-value';
        $messages = new PsbWaMessages;

        $ready = $messages->message1PppoeReady($draft, $fixtureValue);
        $done = $messages->message2OltDone($draft, 'https://acs.example.test', $fixtureValue);
        $resend = $messages->resendCredentials($draft, 'https://acs.example.test', $fixtureValue);

        foreach ([$ready, $done, $resend] as $message) {
            $this->assertStringContainsString($label.$fixtureValue, $message);
            $this->assertStringNotContainsString('tidak ditampilkan di pesan', $message);
            $this->assertNoMojibake($message);
        }
    }

    public function test_allowlisted_activation_group_receives_exact_psb_body_from_fake_sender(): void
    {
        $draft = new PsbDraft([
            'full_name' => 'SUPRIADI',
            'pppoe_user' => 'USERAW_RT01_RW02_L3.1',
            'vlan' => 303,
            'sn' => 'FHTTTEST147',
            'pon_port' => '1/3/3',
        ]);
        $draft->id = 147;
        $fixtureCredential = implode('-', ['fixture', 'router', 'value']);
        $message = (new PsbWaMessages)->huaweiPsbDone($draft, $fixtureCredential);

        $this->assertFakeActivationGroupBody($message);
        $this->assertStringContainsString($fixtureCredential, $message);
    }

    public function test_huawei_psb_success_message_matches_activation_contract(): void
    {
        $draft = new PsbDraft([
            'full_name' => 'NAMA TEST',
            'pppoe_user' => 'TEST_PPPOE',
            'vlan' => 108,
            'tr069_vlan' => 69,
            'pon_port' => '0/0/7',
            'onu_id' => 27,
        ]);
        $draft->id = 1083;
        $fixtureCredential = implode('-', ['fixture', 'router', 'value']);

        $message = (new PsbWaMessages)->huaweiPsbDone($draft, $fixtureCredential);

        $this->assertSame(
            "PSB Huawei #1083 selesai pada 0/0/7:27.\n"
            ."Nama: NAMA TEST\n"
            ."PPPoE: TEST_PPPOE\n"
            ."Password: {$fixtureCredential}\n"
            ."VLAN internet/TR-069: 108/69.\n"
            .'GenieACS: menunggu Inform CPE.',
            $message,
        );
    }

    public function test_huawei_psb_success_message_does_not_echo_unsafe_router_credential(): void
    {
        $draft = new PsbDraft([
            'full_name' => 'NAMA TEST',
            'pppoe_user' => 'TEST_PPPOE',
            'vlan' => 108,
            'tr069_vlan' => 69,
            'pon_port' => '0/0/7',
            'onu_id' => 27,
        ]);
        $draft->id = 1083;

        $unsafeCredential = implode('-', ['fixture', 'unsafe', 'value'])."\nnot-plain-text";
        $message = (new PsbWaMessages)->huaweiPsbDone($draft, $unsafeCredential);

        $this->assertStringNotContainsString('fixture-unsafe-value', $message);
        $this->assertStringContainsString('tidak terbaca dari Router target', $message);
    }

    public function test_allowlisted_activation_group_receives_exact_migration_body_from_fake_sender(): void
    {
        $migration = new CustomerMigration([
            'id' => 42,
            'status' => CustomerMigration::STATUS_SYNCED,
            'pppoe_user' => 'OLDUSER',
            'full_name' => 'Pelanggan Test',
            'new_sn' => 'FHTTNEW123',
            'old_sn' => 'FHTTOLD123',
            'old_onu_ref' => 'old-onu',
            'new_onu_ref' => 'new-onu',
            'group_jid' => '120363-aktivasi@g.us',
        ]);
        $migration->exists = true;
        $migration->setRelation('customer', null);

        $method = new \ReflectionMethod(CustomerMigrationService::class, 'successMessage');
        $method->setAccessible(true);
        $message = $method->invoke(
            (new \ReflectionClass(CustomerMigrationService::class))->newInstanceWithoutConstructor(),
            $migration,
            'fixture-migration-password',
        );

        $this->assertFakeActivationGroupBody($message);
        $this->assertStringContainsString('fixture-migration-password', $message);
    }

    public function test_waiting_sn_and_pending_vlan_messages_explain_distinct_blockers(): void
    {
        $draft = new PsbDraft([
            'full_name' => 'ANGGI',
            'sn' => 'FHTT0000A02E',
            'pon_port' => '1/3/7',
        ]);
        $draft->id = 365;

        $messages = new PsbWaMessages;

        $waitingSn = $messages->waitingSn($draft);
        $pendingVlan = $messages->pendingVlan($draft);

        $this->assertStringContainsString('SN belum muncul di daftar unconfigured OLT.', $waitingSn);
        $this->assertStringNotContainsString('VLAN internet', $waitingSn);
        $this->assertStringContainsString('SN terdeteksi, tetapi VLAN internet untuk PON ini belum tersedia.', $pendingVlan);
        $this->assertStringContainsString('PON: *1/3/7*', $pendingVlan);
        $this->assertStringNotContainsString('Cek ONT power/FO/PON', $pendingVlan);
    }

    public function test_ip_pool_full_copy_does_not_force_ra_only(): void
    {
        $message = (new PsbWaMessages)->ipPoolFull();

        $this->assertStringContainsString('block router/site ini', $message);
        $this->assertStringContainsString('Bot tidak auto-create block baru', $message);
        $this->assertStringNotContainsString('Tidak ada IP remote free di RA-OLT', $message);
        $this->assertStringNotContainsString('RA-OLT', $message);
    }

    public function test_draft_check_labels_pppoe_with_the_target_router(): void
    {
        $draft = new PsbDraft([
            'full_name' => 'USER AV',
            'sn' => 'FHTT0000A03A',
            'pppoe_user' => 'USERAV_RT6_RW5_6.5',
            'router_id' => 511,
        ]);
        $draft->id = 557;
        $router = new Router(['name' => 'SKYNET-GADANG']);
        $router->id = 511;
        $draft->setRelation('router', $router);

        $message = (new PsbWaMessages)->draftCheck($draft, [
            'pppoe_live' => true,
            'pppoe_detail' => 'session up',
        ]);

        $this->assertStringContainsString('PPPoE SKYNET-GADANG:', $message);
        $this->assertStringNotContainsString('RA-OLT', $message);
    }

    public function test_secret_already_exists_labels_the_target_router(): void
    {
        $draft = new PsbDraft(['pppoe_user' => 'USER_RT1_RW1_1.1', 'router_id' => 511]);
        $draft->id = 42;
        $router = new Router(['name' => 'SKYNET-GADANG']);
        $router->id = 511;
        $draft->setRelation('router', $router);

        $message = (new PsbWaMessages)->secretAlreadyExists($draft);

        $this->assertStringContainsString('PPPoE sudah ada (SKYNET-GADANG)', $message);
        $this->assertStringNotContainsString('RA-OLT', $message);
    }

    public function test_draft_check_uses_generic_label_without_a_router(): void
    {
        $draft = new PsbDraft(['full_name' => 'X', 'pppoe_user' => 'user']);
        $draft->id = 1;

        $message = (new PsbWaMessages)->draftCheck($draft);

        $this->assertStringContainsString('PPPoE router target:', $message);
        $this->assertStringNotContainsString('RA-OLT', $message);
    }

    public function test_draft_check_never_shows_raw_error_text_or_codes(): void
    {
        $raw = [
            'Dokumen PSB belum valid: ocr_pending' => 'Foto KTP/SN masih dibaca.',
            'Dokumen PSB belum valid: missing' => 'Foto KTP belum ada.',
            'OLT: Connection timed out (SSH 198.18.2.1)' => 'Proses OLT belum berhasil.',
            'SQLSTATE[HY000]: General error' => 'Proses belum selesai.',
            'SN mismatch: chat=AAA uncfg=BBB' => 'SN di chat beda dengan SN di OLT.',
        ];
        foreach ($raw as $error => $expected) {
            $draft = new PsbDraft(['full_name' => 'X', 'last_error' => $error, 'status' => 'failed']);
            $draft->id = 5;

            $message = (new PsbWaMessages)->draftCheck($draft);

            $this->assertStringContainsString('Catatan: '.$expected, $message);
            $this->assertStringNotContainsString('Last error', $message);
            $this->assertStringNotContainsString('ocr_pending', $message);
            $this->assertStringNotContainsString('SQLSTATE', $message);
            $this->assertStringNotContainsString('198.18.2.1', $message);
        }
    }

    public function test_draft_check_keeps_fixed_notes_written_by_the_psb_flow(): void
    {
        $notes = [
            'Config PSB belum lengkap: Paket (contoh: 200M)',
            'Daerah `X` belum terdaftar di registry site PSB (anti salah router).',
            'Router `abc` tidak dikenali (contoh: ra, krian)',
            'Paket/Profile `100M` belum ada di eBilling untuk router ini.',
            'Paket `100M` belum memiliki MikroTik Profile; proses dihentikan.',
            'Profile MT `100M` belum ada di router #5. Buat PPP profile di MikroTik.',
            'Site HTB butuh VLAN service. Isi di Aktivasi→VLAN MikroTik atau kirim: #psb vlan NN #9',
            'PON 1/1/1 belum ada mapping VLAN',
            'VLAN mapping belum diisi untuk PON 1/1/1 di OLT X. Isi di Aktivasi.',
            'SN FHTT00000001 belum di uncfg (2 ONU uncfg lain)',
            'SN belum di uncfg / tidak terbaca',
            'OCR tertunda #9. Foto tetap tersimpan; coba cek ulang sebentar lagi.',
            'Precondition OLT gagal: sn/pon/vlan/pppoe_user kosong',
            'OLT antri: menunggu giliran OLT (otomatis, percobaan 1/5)',
        ];
        foreach ($notes as $note) {
            $draft = new PsbDraft(['full_name' => 'X', 'last_error' => $note, 'status' => 'failed']);
            $draft->id = 9;

            $this->assertStringContainsString('Catatan: '.$note, (new PsbWaMessages)->draftCheck($draft), $note);
        }
    }

    public function test_draft_check_secret_missing_note_is_a_fixed_line(): void
    {
        $draft = new PsbDraft(['full_name' => 'X', 'last_error' => 'Secret PPPoE hilang di MikroTik, mencoba recreate.', 'status' => 'secret_ok']);
        $draft->id = 9;

        $this->assertStringContainsString('Catatan: Secret PPPoE hilang di MikroTik, mencoba dibuat ulang.', (new PsbWaMessages)->draftCheck($draft));
    }

    public function test_queue_list_shows_router_odp_and_user_when_available(): void
    {
        $message = (new PsbWaMessages)->queueList([
            (object) [
                'id' => 120,
                'status' => 'pppoe_created',
                'full_name' => 'USERC',
                'sn' => 'FHTT0000A014',
                'router' => 'RA',
                'odp' => 'ODP-KALIREJO-7.1',
                'pppoe_user' => 'USERC_RT03_RW15_7.1',
            ],
        ]);

        $this->assertStringContainsString('Router RA', $message);
        $this->assertStringContainsString('ODP ODP-KALIREJO-7.1', $message);
        $this->assertStringContainsString('User USERC_RT03_RW15_7.1', $message);
    }

    public function test_olt_done_message_repairs_mojibake_for_whatsapp(): void
    {
        $draft = new PsbDraft([
            'id' => 125,
            'full_name' => 'MI MIFTAHUL FALAH',
            'sn' => 'FHTT0000A02A',
            'pon_port' => '1/3/8',
            'vlan' => 308,
            'pppoe_user' => 'USERAQ_RT2_RW7_01',
            'pppoe_password' => 'canary-pw',
        ]);
        $draft->exists = true;

        $message = (new PsbWaMessages)->message2OltDone($draft, 'http://203.0.113.106:7547');

        $this->assertStringContainsString('✅ *Aktivasi OLT selesai*', $message);
        $this->assertStringContainsString('PON: 1/3/8 · VLAN: *308*', $message);
        $this->assertStringContainsString('• Mode: PPPoE', $message);
        $this->assertStringContainsString('Status *live PPPoE* & *redaman* → cek di eBilling', $message);
        $this->assertNoMojibake($message);
    }

    public function test_today_report_repairs_mojibake_for_whatsapp(): void
    {
        $message = (new PsbWaMessages)->todayReport([
            'label' => '28 Jul 2026 - 00:46 WIB',
            'total' => 0,
            'olt_ok' => 0,
            'secret_ok' => 0,
            'wait_sn' => 0,
            'pend_vlan' => 0,
            'failed' => 0,
            'applied' => 0,
        ]);

        $this->assertStringContainsString('*Rekap PSB hari ini*', $message);
        $this->assertStringContainsString('Total: *0* | OLT: 0 | PPPoE: 0', $message);
        $this->assertStringContainsString('Tunggu SN: 0 | VLAN: 0 | Gagal: 0 | Applied: 0', $message);
        $this->assertStringContainsString('Refresh: */psb* | Bantuan: *#psb bantu*', $message);
        $this->assertNoMojibake($message);

        return;

        $message = (new PsbWaMessages)->todayReport([
            'label' => '28 Jul 2026 · 00:46 WIB',
            'total' => 0,
            'olt_ok' => 0,
            'secret_ok' => 0,
            'wait_sn' => 0,
            'pend_vlan' => 0,
            'failed' => 0,
            'applied' => 0,
        ]);

        $this->assertStringContainsString('📊 *Rekap PSB hari ini*', $message);
        $this->assertStringContainsString('✅ OLT selesai / applied: 0', $message);
        $this->assertStringContainsString('🔑 Secret OK (tunggu OLT): 0', $message);
        $this->assertStringContainsString('Live PPPoE & redaman per pelanggan: eBilling → PSB WA', $message);
        $this->assertNoMojibake($message);
    }

    public function test_today_report_shows_read_only_device_reconciliation(): void
    {
        $message = (new PsbWaMessages)->todayReport([
            'label' => '01 Aug 2026 - 14:00 WIB',
            'total' => 2,
            'olt_ok' => 1,
            'secret_ok' => 1,
            'wait_sn' => 0,
            'pend_vlan' => 0,
            'failed' => 0,
            'applied' => 0,
            'device_sync' => [
                'olt_online' => 2,
                'pppoe_linked' => 2,
                'attention' => [['id' => 42, 'name' => 'TEST USER', 'status' => 'secret_ok']],
            ],
        ]);

        $this->assertStringContainsString('Snapshot: OLT aktif 2 | PPPoE linked DB 2', $message);
        $this->assertStringContainsString('Cek #42 TEST USER: OLT aktif, DB secret_ok', $message);
    }

    private function assertFakeActivationGroupBody(string $message): void
    {
        config([
            'services.evolution_api.base_url' => 'http://evolution.test',
            'services.evolution_api.api_key' => 'fake-key',
            'services.evolution_api.instance_name' => 'fake-ops',
        ]);
        Cache::forget('evo:fake-ops:state');
        Http::fake([
            'http://evolution.test/instance/connectionState/*' => Http::response([
                'instance' => ['state' => 'open'],
            ]),
            'http://evolution.test/message/sendText/*' => Http::response([
                'key' => ['id' => 'fake-activation-message'],
            ]),
        ]);

        $result = (new EvolutionApiService)->sendToGroup('120363-aktivasi@g.us', $message);

        $this->assertTrue($result['success'] ?? false);
        Http::assertSent(function ($request) use ($message) {
            return str_contains($request->url(), '/message/sendText/')
                && ($request->data()['number'] ?? null) === '120363-aktivasi@g.us'
                && ($request->data()['text'] ?? null) === $message;
        });
    }

    private function assertNoMojibake(string $message): void
    {
        $this->assertStringNotContainsString('Ãƒ', $message);
        $this->assertStringNotContainsString('Ã‚', $message);
        $this->assertStringNotContainsString('Ã¢', $message);
        $this->assertStringNotContainsString('Ã°Å¸', $message);
        $this->assertStringNotContainsString('â', $message);
        $this->assertStringNotContainsString('Â', $message);
        $this->assertStringNotContainsString('ðŸ', $message);
    }
}
