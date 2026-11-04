<?php

namespace Tests\Unit;

use App\Services\CustomerMigrationMessageParser;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('blocking-psb')]
class CustomerMigrationMessageParserTest extends TestCase
{
    public function test_it_parses_labeled_migration_message(): void
    {
        $parsed = (new CustomerMigrationMessageParser)->parse(
            "#migrasi\npppoe: USERAJ_RT03_RW04_2\nsn: FHTT0000A030\nonu lama: 1/2/3:4\nodp: KANTOR_DAPUR\nrt: 03\nrw: 04\nrouter: ra"
        );

        $this->assertTrue($parsed['is_migrasi']);
        $this->assertSame('USERAJ_RT03_RW04_2', $parsed['pppoe_user']);
        $this->assertSame('FHTT0000A030', $parsed['new_sn']);
        $this->assertSame('1/2/3:4', $parsed['old_onu_ref']);
        $this->assertSame('03', $parsed['rt']);
        $this->assertSame('04', $parsed['rw']);
        $this->assertSame('ra', $parsed['router_field']);
    }

    public function test_it_parses_inline_pppoe_and_sn(): void
    {
        $parsed = (new CustomerMigrationMessageParser)->parse(
            '#migrasi USERAJ_RT03_RW04_2 sn FHTT0000A030 daerah suko'
        );

        $this->assertTrue($parsed['is_migrasi']);
        $this->assertSame('USERAJ_RT03_RW04_2', $parsed['pppoe_user']);
        $this->assertSame('FHTT0000A030', $parsed['new_sn']);
    }

    public function test_it_parses_source_and_target_router_without_collapsing_them(): void
    {
        $parsed = (new CustomerMigrationMessageParser)->parse(
            "#migrasi\nPPPoE: USERSHOP_RANDUTELU\nRouter lama: Randuagung\nRouter baru: Skynet-RA-OLT\nOLT: RA"
        );

        $this->assertSame('Randuagung', $parsed['old_router_field']);
        $this->assertSame('Skynet-RA-OLT', $parsed['new_router_field']);
        $this->assertSame('Skynet-RA-OLT', $parsed['router_field']);
    }

    public function test_it_parses_source_and_target_router_without_colon(): void
    {
        $parsed = (new CustomerMigrationMessageParser)->parse(
            "#migrasi\nPPPoE USERSHOP_RANDUTELU\nRouter lama Randuagung\nRouter baru skynet-ra-olt\nOLT RA"
        );

        $this->assertSame('Randuagung', $parsed['old_router_field']);
        $this->assertSame('skynet-ra-olt', $parsed['new_router_field']);
        $this->assertSame('skynet-ra-olt', $parsed['router_field']);
    }

    public function test_it_keeps_target_router_when_sn_and_keterangan_follow_it(): void
    {
        $parsed = (new CustomerMigrationMessageParser)->parse(
            "#migrasi\nNama: USER YA\nPPPOE: USERYA_RANDUTELU\nOLT: RA\nODP BARU: 034_L6\nSN BARU: FHTT0000A053\nRouter lama: Randuagung\nRouter Baru: Skynet-RA-OLT\nKeterangan: migrasi htb ke FiberHome"
        );

        $this->assertSame('Randuagung', $parsed['old_router_field']);
        $this->assertSame('Skynet-RA-OLT', $parsed['new_router_field']);
        $this->assertSame('Skynet-RA-OLT', $parsed['router_field']);
    }

    public function test_it_parses_unlabeled_pppoe_and_olt_lines(): void
    {
        $parsed = (new CustomerMigrationMessageParser)->parse(
            "#migrasi\nPPPoE USERSHOP_RANDUTELU\nRouter lama Randuagung\nRouter baru skynet-ra-olt\nOLT RA"
        );

        $this->assertSame('USERSHOP_RANDUTELU', $parsed['pppoe_user']);
        $this->assertSame('RA', $parsed['olt_field']);
    }

    public function test_labeled_multiline_message_does_not_treat_nama_as_pppoe(): void
    {
        $parsed = (new CustomerMigrationMessageParser)->parse(
            "#migrasi\nNama: USER RA\nSN Baru: FHTT0000A04E\nSn lama : ZXIC00000A0E8\nOLT: RA\nDaerah: SUKO\nRT: 05\nRW: 04"
        );

        $this->assertNull($parsed['pppoe_user']);
        $this->assertSame('ZXIC00000A0E8', $parsed['old_sn']);
        $this->assertSame('FHTT0000A04E', $parsed['new_sn']);
    }

    public function test_it_parses_onu_name_preview_format(): void
    {
        $parsed = (new CustomerMigrationMessageParser)->parse(
            "#migrasi\nNama ONU: KANTOR_DAPUR\nSN Baru: FHTT0000A032\nOLT: RA\nKeterangan: test swap ONT kantor"
        );

        $this->assertTrue($parsed['is_migrasi']);
        $this->assertSame('KANTOR_DAPUR', $parsed['onu_name']);
        $this->assertSame('FHTT0000A032', $parsed['new_sn']);
        $this->assertSame('RA', $parsed['olt_field']);
        $this->assertNull($parsed['full_name']);
    }

    public function test_it_parses_new_odp_label_with_numeric_suffix(): void
    {
        $parsed = (new CustomerMigrationMessageParser)->parse(
            "#migrasi\nNama: USER AN\nPPPoE: USERAN_RT7_RW3_03\nODP baru: 05\nDaerah: SLB\nSN Baru: FHTT0000A050"
        );

        $this->assertSame('05', $parsed['odp']);
        $this->assertSame('SLB', $parsed['daerah']);
        $this->assertSame('USERAN_RT7_RW3_03', $parsed['pppoe_user']);
    }

    public function test_it_parses_new_odp_without_colon(): void
    {
        $parsed = (new CustomerMigrationMessageParser)->parse(
            "#migrasi\nODP baru 05\nDaerah: SLB\nSN Baru: FHTT0000A051"
        );

        $this->assertSame('05', $parsed['odp']);
    }

    public function test_status_and_retry_commands_are_detected(): void
    {
        $parser = new CustomerMigrationMessageParser;

        $this->assertTrue($parser->parse('#migrasi cek #12')['is_cek']);
        $this->assertSame(12, $parser->parse('#migrasi lanjut #12 sn: FHTT12345678')['migration_id']);
        $picked = $parser->parse('#migrasi pilih #12 2');
        $this->assertTrue($picked['is_pilih']);
        $this->assertSame(12, $picked['migration_id']);
        $this->assertSame(2, $picked['candidate_number']);
        $execute = $parser->parse('#migrasi eksekusi #12');
        $this->assertTrue($execute['is_eksekusi']);
        $this->assertSame(12, $execute['migration_id']);
        $confirm = $parser->parse('#migrasi konfirmasi #12');
        $this->assertTrue($confirm['is_konfirmasi']);
        $this->assertSame(12, $confirm['migration_id']);
        $this->assertNull($confirm['pppoe_user']);
        $ktp = $parser->parse('#migrasi ktp #12');
        $this->assertTrue($ktp['is_ktp']);
        $this->assertSame(12, $ktp['migration_id']);
        $this->assertNull($ktp['pppoe_user']);
        $phone = $parser->parse('#migrasi no hp #12 081200000011');
        $this->assertTrue($phone['is_phone_update']);
        $this->assertSame(12, $phone['migration_id']);
        $this->assertSame('081200000011', $phone['phone']);
        $this->assertTrue($parser->isBotTrigger('/migrasi status'));
    }

    public function test_it_parses_explicit_huawei_olt_only_mode_without_a_password_field(): void
    {
        $parsed = (new CustomerMigrationMessageParser)->parse(
            "#migrasi\nMode: OLT_ONLY_HUAWEI\nPPPoE: USERG\nSN Baru: FHTT0000A045\nOLT: GADANG\nRouter: Gadang\nVLAN: 101\nTR-069 VLAN: 69"
        );

        self::assertSame('OLT_ONLY_HUAWEI', $parsed['mode_field']);
        self::assertSame('101', $parsed['vlan_field']);
        self::assertSame('69', $parsed['tr069_vlan_field']);
        self::assertArrayNotHasKey('password', $parsed);
    }

    public function test_huawei_migration_does_not_require_ont_placement_input(): void
    {
        $parsed = (new CustomerMigrationMessageParser)->parse(
            "#migrasi\nPPPoE: USERAI_RT9_RW3_6.8\nSN: FHTT0000A03A"
        );

        self::assertSame('USERAI_RT9_RW3_6.8', $parsed['pppoe_user']);
        self::assertSame('FHTT0000A03A', $parsed['new_sn']);
        self::assertNull($parsed['pon_port']);
        self::assertNull($parsed['new_onu_ref_hint']);
    }

    public function test_legacy_new_onu_reference_is_kept_as_a_validated_hint(): void
    {
        $parsed = (new CustomerMigrationMessageParser)->parse(
            "#migrasi\nPPPoE: USERAI_RT9_RW3_6.8\nONU baru: 0/0/7:26\nSN: FHTT0000A03A"
        );

        self::assertSame('0/0/7', $parsed['pon_port']);
        self::assertSame('0/0/7:26', $parsed['new_onu_ref_hint']);
        self::assertNull($parsed['old_onu_ref']);
    }

    public function test_inline_new_onu_reference_with_sn_is_parsed_without_stale_pon_or_ontid_fields(): void
    {
        $parsed = (new CustomerMigrationMessageParser)->parse(
            "#migrasi\nONU baru: 0/0/7:27 / FHTT0000A03A\nPON: 0/0/0\nONTID: 0\nPPPoE: USERAI_RT9_RW3_6.8\nSN: FHTT0000A03A",
        );

        self::assertSame('0/0/7:27', $parsed['new_onu_ref_hint']);
        self::assertSame('0/0/7', $parsed['pon_port']);
    }

    public function test_it_parses_the_gadang_router_replacement_form(): void
    {
        $parsed = (new CustomerMigrationMessageParser)->parse(
            "#migrasi\nNama: Pelanggan Gadang\nPPPoE: GADANG_RT1_RW1_1.1\nSN Baru: FHTTGADANG01\nSN Lama: FHTTOLDLAMA1\nOLT: OLT GADANG 3\nDaerah: Gadang\nRT: 01\nRW: 02\nRouter: Gadang\nKeterangan: router fiberhome rusak, wlan mati",
        );

        self::assertSame('Pelanggan Gadang', $parsed['full_name']);
        self::assertSame('GADANG_RT1_RW1_1.1', $parsed['pppoe_user']);
        self::assertSame('FHTTGADANG01', $parsed['new_sn']);
        self::assertSame('FHTTOLDLAMA1', $parsed['old_sn']);
        self::assertSame('OLT GADANG 3', $parsed['olt_field']);
        self::assertSame('Gadang', $parsed['daerah']);
        self::assertSame('01', $parsed['rt']);
        self::assertSame('02', $parsed['rw']);
        self::assertSame('Gadang', $parsed['router_field']);
        self::assertStringContainsString('fiberhome rusak', (string) $parsed['keterangan']);
    }
}
