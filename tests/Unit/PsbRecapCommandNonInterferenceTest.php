<?php

namespace Tests\Unit;

use App\Services\CustomerMigrationMessageParser;
use App\Services\PsbMessageParser;
use App\Services\PsbRecapCommandParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * "#rekap psb ..." must not change anything for the PSB / migration bots:
 * every existing command keeps its trigger result (hard-coded snapshot below)
 * and is never claimed by the recap parser; every recap variant is invisible
 * to both bots (no trigger, no near-miss).
 */
#[Group('blocking-psb')]
class PsbRecapCommandNonInterferenceTest extends TestCase
{
    /**
     * text => [PsbMessageParser::isBotTrigger, CustomerMigrationMessageParser::isBotTrigger]
     *
     * @return array<string, array{0: string, 1: bool, 2: bool}>
     */
    public static function existingCommands(): array
    {
        $psb = [
            "#psb\nNama: Budi\nODP: ODP-1\nRT: 01\nRW: 02\nDaerah: Klampok\nSN: FHTT12345678",
            '#psb',
            '/psb',
            '/psb rekap',
            '#psb rekap',
            '/psb status',
            '/psb hari ini',
            '#psb status',
            '#psb bantu',
            '/psb bantu',
            '#psb contoh',
            '#psb cek #12',
            '#psb lanjut #12',
            '#psb batal #12',
            '#psb sn FHTT12345678',
            '#psb ya #12',
            '#psb antri',
            '#psb kirim lagi #12',
            '#psb router ra #12',
            '#psb vlan 100 #12',
            '#psb ktp #12',
            '/pppoe cari budi',
            '#pppoe cari budi',
            '#help',
            '#status #12',
            '#lanjut #12',
            '#batal #12',
            'done',
            'done #12',
            'selesai #12',
            'sudah',
            'vlan done',
        ];
        $migration = [
            '#migrasi',
            '#migrasi eksekusi #5',
            '#migrasi cek #5',
            '#migrasi bantu',
            '/migrasi bantu',
            "#migrasi\nNama: Budi\nPPPoE: budi01",
        ];
        $neither = [
            'selamat pagi',
            'ok siap pak',
            'psb: budi',
            'rekap psb klampok',
            '/rekap psb klampok',
            '#rekap migrasi klampok',
            '#rekap onu klampok',
            '#rekap',
        ];

        $cases = [];
        foreach ($psb as $text) {
            $cases['psb: '.str_replace("\n", ' / ', $text)] = [$text, true, false];
        }
        foreach ($migration as $text) {
            $cases['migrasi: '.str_replace("\n", ' / ', $text)] = [$text, false, true];
        }
        foreach ($neither as $text) {
            $cases['neither: '.$text] = [$text, false, false];
        }

        return $cases;
    }

    #[DataProvider('existingCommands')]
    public function test_existing_commands_keep_their_trigger_result_and_are_not_recap(string $text, bool $psbTrigger, bool $migrationTrigger): void
    {
        $psb = new PsbMessageParser;
        $migration = new CustomerMigrationMessageParser;

        $this->assertSame($psbTrigger, $psb->isBotTrigger($text), 'PSB trigger changed for: '.$text);
        $this->assertSame($migrationTrigger, $migration->isBotTrigger($text), 'migration trigger changed for: '.$text);
        $this->assertFalse((new PsbRecapCommandParser)->matches($text), 'recap must not claim: '.$text);
    }

    public function test_psb_parse_output_for_representative_commands_is_unchanged(): void
    {
        $parser = new PsbMessageParser;

        $this->assertTrue($parser->parse('#psb rekap')['is_status']);
        $this->assertTrue($parser->parse('/psb rekap')['is_status']);
        $this->assertTrue($parser->parse('#psb bantu')['is_help']);
        $this->assertTrue($parser->parse('#psb ktp #12')['is_ktp_cmd']);
        $this->assertSame(12, $parser->parse('#psb cek #12')['draft_id']);
        $this->assertTrue($parser->parse('#psb cek #12')['is_cek']);
        $this->assertFalse($parser->parse('#psb rekap')['is_ktp_cmd']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function recapVariants(): array
    {
        $texts = [
            '#rekap psb',
            '#rekap psb klampok',
            '#rekap psb klampok 1-14 okt',
            '#rekap psb klampok 1 - 14 oktober 2026',
            '#rekap psb klampok 01/10/2026 14/10/2026',
            '#rekap psb klampok hari ini',
            '#rekap psb klampok kemarin',
            '#rekap psb klampok bulan lalu',
            '#rekap psb semua',
            '#rekap psb all bulan ini',
            '#rekap psb semua excel',
            '# rekap psb randu agung okt',
            '  #REKAP PSB Klampok  ',
        ];
        $cases = [];
        foreach ($texts as $text) {
            $cases[$text] = [$text];
        }

        return $cases;
    }

    #[DataProvider('recapVariants')]
    public function test_recap_variants_are_invisible_to_psb_and_migration_bots(string $text): void
    {
        $psb = new PsbMessageParser;
        $migration = new CustomerMigrationMessageParser;

        $this->assertTrue((new PsbRecapCommandParser)->matches($text));
        $this->assertFalse($psb->isBotTrigger($text), 'PSB must not trigger on: '.$text);
        $this->assertFalse($psb->isNearMissPsb($text), 'PSB near-miss must not fire on: '.$text);
        $this->assertFalse($migration->isBotTrigger($text), 'migration must not trigger on: '.$text);
        $this->assertFalse($psb->parse($text)['is_psb']);
    }

    public function test_recap_text_that_also_carries_a_psb_or_migration_tag_still_belongs_to_those_bots(): void
    {
        $psb = new PsbMessageParser;
        $migration = new CustomerMigrationMessageParser;

        // The recap parser may match the shape, but the webhook gate requires BOTH
        // isBotTrigger() to be false, so these two stay on their old path.
        $this->assertTrue($psb->isBotTrigger('#rekap psb #psb budi'));
        $this->assertTrue($migration->isBotTrigger('#rekap psb #migrasi budi'));
    }
}
