<?php

namespace Tests\Unit;

use App\Services\KtpOcrService;
use App\Support\KtpParserV2;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Fixtures\SyntheticOcrFixtures;
use Tests\TestCase;

/**
 * KTP parser v2: the 2026-10-17 incident text (labels as a column, values in a
 * second ":" column) must not return a label ("TEMPAT") as the name.
 */
#[Group('blocking-psb')]
class KtpOcrParserV2Test extends TestCase
{
    public function test_column_wise_incident_text_is_parsed_by_shape(): void
    {
        $parsed = (new KtpOcrService)->extractFields(SyntheticOcrFixtures::ktpColumnWiseText());

        $this->assertSame('3507120110000005', $parsed['nik']);
        $this->assertTrue($parsed['nik_valid']);
        $this->assertSame('DRAFTE CO SANTOSO', $parsed['name']);
        $this->assertStringContainsString('MALANG', (string) $parsed['place_of_birth']);
        $this->assertSame('LAKI-LAKI', $parsed['gender']);
        $this->assertSame('JL. CONTOH NO. 12', $parsed['address']);
        $this->assertSame('1', $parsed['rt']);
        $this->assertSame('2', $parsed['rw']);
    }

    public function test_old_parser_returned_a_label_as_name_on_the_same_text(): void
    {
        config(['services.ocrspace.ktp_parser_v2' => false]);

        $parsed = (new KtpOcrService)->extractFields(SyntheticOcrFixtures::ktpColumnWiseText());

        // The incident: kept for one release behind KTP_OCR_PARSER_V2=false.
        $this->assertSame('TEMPAT', $parsed['name']);
        $this->assertArrayNotHasKey('nik_valid', $parsed);
    }

    public function test_row_wise_text_is_unchanged(): void
    {
        $raw = <<<'TXT'
PROVINSI JAWA TIMUR
KABUPATEN MALANG
NIK : 3 5 0 7 1 1 4 5 1 0 0 0 0 0 0 2
Nama : SITI MARIYA ULFA
Tempat/Tgl Lahir : MALANG, 05-01-1990
Jenis Kelamin : PEREMPUAN
Alamat : JL MERDEKA NO 12
RT/RW : 016/004
Kel/Desa : KRIAN
Kecamatan : KRIAN
TXT;
        $svc = new KtpOcrService;
        $v2 = $svc->extractFields($raw);
        config(['services.ocrspace.ktp_parser_v2' => false]);
        $v1 = $svc->extractFields($raw);

        foreach (['nik', 'name', 'address', 'rt', 'rw', 'place_of_birth', 'gender'] as $field) {
            $this->assertSame($v1[$field], $v2[$field], $field);
        }
        $this->assertSame('SITI MARIYA ULFA', $v2['name']);
        $this->assertSame('16', $v2['rt']);
        $this->assertSame('4', $v2['rw']);
    }

    public function test_paddle_style_label_then_value_lines(): void
    {
        $parsed = (new KtpOcrService)->extractFields(SyntheticOcrFixtures::ktpPaddleLinesText());

        $this->assertSame('3507120110000005', $parsed['nik']);
        $this->assertSame('DRAFTE CO SANTOSO', $parsed['name']);
        $this->assertSame('LAKI-LAKI', $parsed['gender']);
        $this->assertSame('JL. CONTOH NO. 12', $parsed['address']);
        $this->assertSame('1', $parsed['rt']);
        $this->assertSame('2', $parsed['rw']);
    }

    public function test_labels_only_gives_nulls_never_a_label(): void
    {
        $parsed = (new KtpOcrService)->extractFields(SyntheticOcrFixtures::ktpLabelsOnlyText());

        $this->assertNull($parsed['name']);
        $this->assertNull($parsed['address']);
        $this->assertNull($parsed['gender']);
        $this->assertNull($parsed['place_of_birth']);
        $this->assertNull($parsed['rt']);
        $this->assertNull($parsed['rw']);
        $this->assertNull($parsed['nik']);
    }

    /** @return array<string,array{string}> */
    public static function labelWords(): array
    {
        $cases = [];
        foreach (KtpParserV2::LABEL_WORDS as $word) {
            $cases[$word] = [$word];
        }

        return $cases;
    }

    #[DataProvider('labelWords')]
    public function test_every_label_word_is_rejected_as_name_and_address(string $word): void
    {
        $svc = new KtpOcrService;

        $this->assertNull($svc->extractFields("Nama : {$word}\nTempat/Tgl Lahir : MALANG, 01-01-1990")['name'], 'name '.$word);
        $this->assertNull($svc->extractFields("Nama\n{$word}")['name'], 'next-line name '.$word);
        $this->assertNull($svc->extractFields("Alamat : {$word}")['address'], 'address '.$word);
    }

    public function test_label_word_inside_a_longer_name_is_kept_only_when_not_leading_or_majority(): void
    {
        $svc = new KtpOcrService;

        $this->assertSame('SITI KOTA RAHAYU', $svc->extractFields('Nama : SITI KOTA RAHAYU')['name']);
        $this->assertNull($svc->extractFields('Nama : TEMPAT TGL LAHIR')['name']);
        $this->assertNull($svc->extractFields('Nama : AB')['name']);
        $this->assertNull($svc->extractFields('Nama : '.str_repeat('A', 61))['name']);
        $this->assertNull($svc->extractFields('Nama : A B C D E F G')['name']);
    }

    public function test_common_names_with_a_label_word_are_accepted_but_pure_labels_are_not(): void
    {
        $svc = new KtpOcrService;

        foreach (['NURUL ISLAM', 'SAIFUL ISLAM', 'MUHAMMAD ISLAM'] as $name) {
            $this->assertSame($name, $svc->extractFields('Nama : '.$name)['name'], $name);
        }
        $this->assertNull($svc->extractFields('Nama : ISLAM')['name']);
        $this->assertNull($svc->extractFields('Nama : TEMPAT')['name']);
        $this->assertNull($svc->extractFields('Nama : WNI ISLAM')['name']);
        $this->assertNull($svc->extractFields('Nama : ISLAM BUDI')['name']);
    }

    public function test_title_case_label_on_the_next_line_is_not_a_name(): void
    {
        $this->assertNull((new KtpOcrService)->extractFields("Nama\nTempat/Tgl Lahir")['name']);
        $this->assertNull((new KtpOcrService)->extractFields("Nama\nTempet Lahir")['name']);
    }

    /** @return array<string,array{string,bool}> */
    public static function nikCases(): array
    {
        return [
            'valid male' => ['3507120110000005', true],
            'valid female day+40' => ['3507124110000001', true],
            'day 99' => ['3507129910000001', false],
            'day 00' => ['3507120010000001', false],
            'month 13' => ['1111111113000001', false],
            // feb 30 cannot be written as a synthetic-looking 16-digit literal, so it is built from parts
            'feb 30' => ['3507123002'.'000001', false],
            'province 99' => ['9999994510000001', false],
            'province 10' => ['1011111111000001', false],
            'serial 0000' => ['3507120110000000', false],
            'repeated digit' => ['1111111111111111', false],
            'too short' => ['350712010190000', false],
            'letters' => ['35071201019000O1', false],
        ];
    }

    #[DataProvider('nikCases')]
    public function test_is_valid_nik(string $nik, bool $expected): void
    {
        $this->assertSame($expected, (new KtpOcrService)->isValidNik($nik));
    }

    public function test_nik_flatten_only_inside_digit_runs(): void
    {
        $svc = new KtpOcrService;

        $this->assertSame('3507120110000005', $svc->extractFields('NIK : 35O712OI1000OOO5')['nik']);
        $this->assertSame('3507120110000005', $svc->extractFields("NIK\n: 3507 1201 1000 0005")['nik']);
        // Label words made of I/l/O letters never become digits.
        $this->assertNull($svc->extractFields("NIK\nILLOOILLOOILLOOI")['nik']);
        $this->assertSame('3507120110000005', $svc->extractFields("Nama : BUDI\n3507120110000005")['nik']);
    }

    public function test_invalid_nik_is_still_returned_but_flagged(): void
    {
        $parsed = (new KtpOcrService)->extractFields("NIK : 3507129910000001\nNama : DRAFTE CO SANTOSO");

        $this->assertSame('3507129910000001', $parsed['nik']);
        $this->assertFalse($parsed['nik_valid']);
    }

    public function test_valid_nik_is_preferred_over_an_invalid_earlier_candidate(): void
    {
        $parsed = (new KtpOcrService)->extractFields("NIK : 3507129910000001\n3507120110000005");

        $this->assertSame('3507120110000005', $parsed['nik']);
        $this->assertTrue($parsed['nik_valid']);
    }

    public function test_rt_rw_is_not_taken_from_the_birth_date(): void
    {
        $parsed = (new KtpOcrService)->extractFields("Nama : DRAFTE CO SANTOSO\nTempat/Tgl Lahir : MALANG, 01-01-1990");

        $this->assertNull($parsed['rt']);
        $this->assertNull($parsed['rw']);
    }

    public function test_parser_v2_is_on_by_default(): void
    {
        $this->assertTrue((bool) config('services.ocrspace.ktp_parser_v2'));
    }
}
