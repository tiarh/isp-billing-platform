<?php

namespace Tests\Feature;

use App\Services\CustomerMigrationMessageParser;
use App\Services\PsbMessageParser;
use App\Services\PsbRecapCommandParser;
use App\Services\Recap\MigrasiRecapCommandParser;
use App\Services\Recap\RecapRequest;
use App\Services\Recap\RecapTopicMessages;
use App\Services\Recap\RecapTopics;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Parser collision proof for "#rekap migrasi": it never claims a PSB or migration command, those
 * keep claiming every message they claimed before, and the recap variants are claimed by nobody else.
 */
#[Group('blocking-psb')]
class RecapMigrasiNonInterferenceTest extends TestCase
{
    /** @return list<string> */
    private static function otherCommands(): array
    {
        return [
            // PSB
            '#psb bantu', '#PSB', '#psb ktp #12', '#psb status', '#help', '#status', 'done', 'selesai', '#pppoe cari budi', '/psb bantu',
            "#psb\nnama: Budi\ndaerah: Klampok", 'tolong #psb bantu', '#rekap psb klampok 1-14 okt', '#rekap psb semua',
            // migration
            '#migrasi cek #5', '#migrasi rekap', '#migrasi rekap klampok', '#MIGRASI sinkron #1', '/migrasi status',
            "#migrasi\nnama: Budi\nuser: budi_rt1", 'tolong #migrasi cek #5', '# migrasi status #5',
            // onu
            '#rekap onu klampok',
        ];
    }

    /** @return list<string> */
    private static function variants(): array
    {
        return [
            '#rekap migrasi klampok', '#rekap migrasi semua', '#REKAP MIGRASI Klampok', '# rekap  migrasi klampok',
            '#rekap migrasi klampok 1-14 okt', '#rekap migrasi semua kemarin', '#rekap migrasi klampok excel', '#rekap migrasi',
            "\xC2\xA0#rekap\xC2\xA0migrasi\xC2\xA0klampok", "#rekap\u{200B} migrasi semua",
        ];
    }

    public function test_no_psb_migration_or_onu_command_is_claimed_by_the_migrasi_recap(): void
    {
        $parser = new MigrasiRecapCommandParser;
        foreach (self::otherCommands() as $text) {
            $this->assertFalse($parser->matches($text), 'claimed by the migrasi recap: '.json_encode($text));
            $this->assertNotSame('migrasi', RecapTopics::matchTopic($text), 'claimed by the router: '.json_encode($text));
        }
    }

    public function test_recap_variants_are_claimed_only_by_the_migrasi_topic(): void
    {
        $psb = new PsbMessageParser;
        $migration = new CustomerMigrationMessageParser;
        $psbRecap = new PsbRecapCommandParser;

        foreach (self::variants() as $text) {
            $this->assertSame('migrasi', RecapTopics::matchTopic($text), 'not recognised: '.json_encode($text));
            $this->assertFalse($psb->isBotTrigger($text), 'PSB trigger: '.json_encode($text));
            $this->assertFalse($migration->isBotTrigger($text), 'migration trigger: '.json_encode($text));
            $this->assertFalse($psbRecap->matches($text), 'psb recap parser: '.json_encode($text));
        }
    }

    public function test_near_misses_and_texts_owned_by_other_bots(): void
    {
        foreach (['#rekapmigrasi klampok', '#rekap migrasis', '/rekap migrasi', 'rekap migrasi klampok', "#rekap migrasi klampok\nterima kasih", '#migrasi rekap'] as $text) {
            $this->assertNotSame('migrasi', RecapTopics::matchTopic($text), 'unexpected claim: '.json_encode($text));
        }

        // The parser alone would match these; the webhook gate refuses them because the other bot claims the text.
        $this->assertTrue((new CustomerMigrationMessageParser)->isBotTrigger('#rekap migrasi klampok #migrasi'));
        $this->assertTrue((new PsbMessageParser)->isBotTrigger('#rekap migrasi #psb bantu'));
        // "#migrasi rekap" stays the migration command.
        $this->assertTrue((new CustomerMigrationMessageParser)->isBotTrigger('#migrasi rekap'));
    }

    public function test_the_psb_recap_parser_never_claims_a_migrasi_command(): void
    {
        $this->assertTrue((new PsbRecapCommandParser)->matches('#rekap psb klampok'));
        $this->assertFalse((new PsbRecapCommandParser)->matches('#rekap migrasi klampok'));
        $this->assertFalse((new MigrasiRecapCommandParser)->matches('#rekap psb klampok'));
    }

    public function test_parser_dates_area_and_errors(): void
    {
        $parser = new MigrasiRecapCommandParser;
        $now = CarbonImmutable::parse('2026-10-15 09:00', 'Asia/Jakarta');

        $plain = $parser->parse('#rekap migrasi klampok', $now);
        $this->assertSame('klampok', $plain->areaQuery);
        $this->assertSame('2026-10-01', $plain->fromDate());
        $this->assertSame('2026-10-15', $plain->toDate());
        $this->assertNull($plain->error);

        $range = $parser->parse('#rekap migrasi klampok 1-14 okt', $now);
        $this->assertSame('2026-10-01', $range->fromDate());
        $this->assertSame('2026-10-14', $range->toDate());
        $this->assertSame(14, $range->days());

        $all = $parser->parse('#rekap migrasi semua kemarin', $now);
        $this->assertTrue($all->allAreas);
        $this->assertSame('2026-10-14', $all->fromDate());

        $this->assertSame(RecapRequest::ERROR_MISSING_AREA, $parser->parse('#rekap migrasi', $now)->error);
        $this->assertSame(RecapRequest::ERROR_RANGE_TOO_LONG, $parser->parse('#rekap migrasi klampok 01/01/2026 14/10/2026', $now)->error);
        $this->assertTrue($parser->parse('#rekap migrasi klampok excel', $now)->excelAlias);
        $this->assertNull($parser->parse('#rekap onu klampok', $now));
    }

    public function test_replies_never_look_like_a_bot_command(): void
    {
        $messages = new RecapTopicMessages;
        $psb = new PsbMessageParser;
        $migration = new CustomerMigrationMessageParser;

        foreach ([$messages->usage('migrasi'), $messages->invalidDate('migrasi'), $messages->rangeTooLong(92)] as $text) {
            $this->assertFalse($psb->isBotTrigger($text), $text);
            $this->assertFalse($migration->isBotTrigger($text), $text);
            foreach (preg_split('/\R/', $text) as $line) {
                $this->assertStringStartsNotWith('#', $line);
            }
        }
    }
}
