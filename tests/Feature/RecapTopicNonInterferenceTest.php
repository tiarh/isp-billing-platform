<?php

namespace Tests\Feature;

use App\Services\CustomerMigrationMessageParser;
use App\Services\PsbMessageParser;
use App\Services\PsbRecapCommandParser;
use App\Services\Recap\OnuRecapCommandParser;
use App\Services\Recap\RecapRequest;
use App\Services\Recap\RecapTopics;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Parser collision proof for "#rekap onu": it never claims a PSB or migration command, those
 * keep claiming every message they claimed before, and the recap variants are claimed by nobody else.
 */
#[Group('blocking-psb')]
class RecapTopicNonInterferenceTest extends TestCase
{
    /** @return list<string> */
    private static function psbCommands(): array
    {
        return [
            '#psb bantu', '#PSB', '#psb ktp #12', '#psb status', '#psb lanjut', '#psb batal', '#help', '#status', '#lanjut', '#batal',
            'done', 'selesai', 'selesai #12', 'vlan ok', 'mapping ok', '#pppoe cari budi', '/psb bantu', '/pppoe x',
            "#psb\nnama: Budi\ndaerah: Klampok", 'tolong #psb bantu', '#rekap psb klampok 1-14 okt', '#rekap psb semua',
        ];
    }

    /** @return list<string> */
    private static function migrationCommands(): array
    {
        return [
            '#migrasi cek #5', '#migrasi rekap', '#migrasi rekap klampok', '#MIGRASI sinkron #1', '/migrasi status',
            "#migrasi\nnama: Budi\nuser: budi_rt1", 'tolong #migrasi cek #5', '# migrasi status #5',
        ];
    }

    /** @return list<string> */
    private static function onuRecapVariants(): array
    {
        return [
            '#rekap onu klampok', '#rekap onu semua', '#REKAP ONU Klampok', '# rekap  onu klampok', '#rekap onu klampok excel',
            '#rekap onu', '#rekap onu semua kemarin', "\xC2\xA0#rekap\xC2\xA0onu\xC2\xA0klampok", "#rekap\u{200B} onu semua",
        ];
    }

    public function test_a_no_psb_command_is_claimed_by_the_onu_recap(): void
    {
        foreach (self::psbCommands() as $text) {
            $this->assertNull(RecapTopics::matchTopic($text), 'PSB text claimed by a recap topic: '.$text);
        }
    }

    public function test_b_no_migration_command_is_claimed_by_the_onu_recap(): void
    {
        foreach (self::migrationCommands() as $text) {
            $this->assertNull(RecapTopics::matchTopic($text), 'migration text claimed by a recap topic: '.$text);
        }
    }

    public function test_c_recap_variants_are_claimed_only_by_the_onu_topic(): void
    {
        $psb = new PsbMessageParser;
        $migration = new CustomerMigrationMessageParser;
        $psbRecap = new PsbRecapCommandParser;

        foreach (self::onuRecapVariants() as $text) {
            $this->assertSame('onu', RecapTopics::matchTopic($text), 'not recognised: '.json_encode($text));
            $this->assertFalse($psb->isBotTrigger($text), 'PSB trigger: '.json_encode($text));
            $this->assertFalse($migration->isBotTrigger($text), 'migration trigger: '.json_encode($text));
            $this->assertFalse($psbRecap->matches($text), 'psb recap parser: '.json_encode($text));
        }
    }

    public function test_d_near_misses_are_not_onu_recaps(): void
    {
        foreach ([
            '#rekapmigrasi', '#rekap onus', '#rekaponu klampok',
            '/rekap onu', 'rekap onu klampok', "#rekap onu klampok\nterima kasih", "#rekap onu\n#rekap onu", '', '   ',
        ] as $text) {
            $this->assertNull(RecapTopics::matchTopic($text), 'unexpected claim: '.json_encode($text));
        }

        // "#rekap migrasi klampok #migrasi" stays a migration trigger (mid-text #migrasi).
        $this->assertTrue((new CustomerMigrationMessageParser)->isBotTrigger('#rekap migrasi klampok #migrasi'));
        // The parser alone would match "#rekap onu #psb bantu"; the webhook gate refuses it because PSB claims the text.
        $this->assertTrue((new PsbMessageParser)->isBotTrigger('#rekap onu #psb bantu'));
        $this->assertTrue((new CustomerMigrationMessageParser)->isBotTrigger('#rekap onu #migrasi cek #5'));
    }

    public function test_the_psb_recap_parser_never_claims_an_onu_command(): void
    {
        $this->assertTrue((new PsbRecapCommandParser)->matches('#rekap psb klampok'));
        $this->assertFalse((new PsbRecapCommandParser)->matches('#rekap onu klampok'));
        $this->assertFalse((new OnuRecapCommandParser)->matches('#rekap psb klampok'));
    }

    public function test_onu_parser_snapshot_and_date_ignored(): void
    {
        $parser = new OnuRecapCommandParser;
        $now = CarbonImmutable::parse('2026-10-15 09:00', 'Asia/Jakarta');

        $plain = $parser->parse('#rekap onu klampok', $now);
        $this->assertSame('klampok', $plain->areaQuery);
        $this->assertFalse($plain->allAreas);
        $this->assertFalse($plain->dateIgnored);
        $this->assertNull($plain->error);
        $this->assertSame('2026-10-15', $plain->fromDate());
        $this->assertSame('2026-10-15', $plain->toDate());

        $all = $parser->parse('#rekap onu semua', $now);
        $this->assertTrue($all->allAreas);

        $dated = $parser->parse('#rekap onu klampok 1-14 okt', $now);
        $this->assertSame('klampok', $dated->areaQuery);
        $this->assertTrue($dated->dateIgnored);
        $this->assertNull($dated->error);

        $long = $parser->parse('#rekap onu klampok 01/01/2026 14/10/2026', $now);
        $this->assertNull($long->error, 'a long range is irrelevant for a snapshot');
        $this->assertTrue($long->dateIgnored);

        $this->assertSame(RecapRequest::ERROR_MISSING_AREA, $parser->parse('#rekap onu', $now)->error);
        $this->assertSame(RecapRequest::ERROR_MISSING_AREA, $parser->parse('#rekap onu kemarin', $now)->error);
        $this->assertTrue($parser->parse('#rekap onu klampok excel', $now)->excelAlias);
        $this->assertNull($parser->parse('#rekap psb klampok', $now));
    }

    public function test_recap_request_stays_backward_compatible(): void
    {
        $d = CarbonImmutable::parse('2026-10-15', 'Asia/Jakarta');
        $r = new RecapRequest('psb', 'semua', true, $d, $d, true, false, 92);

        $this->assertFalse($r->dateIgnored);
        $this->assertNull($r->error);
    }
}
