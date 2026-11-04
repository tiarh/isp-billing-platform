<?php

namespace Tests\Unit\Olt;

use App\Services\HuaweiOltOnlyProvisioningService;
use App\Services\MikrotikService;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[Group('blocking-psb')]
class HuaweiAutofindParserTest extends TestCase
{
    public static function fixture(string $name): string
    {
        $text = (string) file_get_contents(__DIR__.'/../../Fixtures/huawei/'.$name);

        return str_replace('{ESC}', "\e", $text);
    }

    private function service(): HuaweiOltOnlyProvisioningService
    {
        return new HuaweiOltOnlyProvisioningService(Mockery::mock(MikrotikService::class));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_both_empty_answers_give_no_entries(): void
    {
        foreach (['autofind-empty-1.txt', 'autofind-empty-2.txt'] as $file) {
            $result = $this->service()->parseAutofindEntries(self::fixture($file));
            $this->assertSame([], $result['entries'], $file);
            $this->assertFalse($result['partial'], $file);
        }
    }

    public function test_one_entry_has_all_fields_and_canonical_sn(): void
    {
        $result = $this->service()->parseAutofindEntries(self::fixture('autofind-one.txt'));

        $this->assertCount(1, $result['entries']);
        $this->assertSame([
            'sn' => 'HWTC0000A005',
            'sn_hex' => '485754430000A005',
            'pon_port' => '0/0/3',
            'vendor_id' => 'HWTC',
            'model' => 'EG8145V5',
            'software' => 'V5R019C10S125',
            'seen_at' => '2026-10-22 09:14:03+07:00',
        ], $result['entries'][0]);
        $this->assertFalse($result['partial']);
        $this->assertSame(1, $result['total_reported']);
    }

    public function test_many_entries_are_not_truncated_sorted_and_deduped(): void
    {
        $raw = self::fixture('autofind-many.txt');
        $this->assertGreaterThan(5000, strlen($raw), 'fixture must be longer than the old 5000-char cut');

        $result = $this->service()->parseAutofindEntries($raw);

        $this->assertCount(14, $result['entries']);
        $this->assertFalse($result['partial']);
        $ports = array_map(fn (array $e): string => $e['pon_port'], $result['entries']);
        $expected = [];
        for ($i = 0; $i <= 13; $i++) {
            $expected[] = "0/0/{$i}";
        }
        $this->assertSame($expected, $ports);
        $sns = array_column($result['entries'], 'sn');
        $this->assertContains('HWTCA000000D', $sns);
        $this->assertContains('FHTTA0000002', $sns);
        $this->assertContains('ALCLA0000003', $sns);
        $this->assertContains('ZTEGA0000004', $sns);
        $this->assertSame(1, count(array_keys($sns, 'ALCLA0000003', true)));
    }

    public function test_password_loid_and_checkcode_are_never_kept(): void
    {
        foreach (['autofind-one.txt', 'autofind-many.txt'] as $file) {
            $json = json_encode($this->service()->parseAutofindEntries(self::fixture($file)));
            $this->assertStringNotContainsString('canary-pw', (string) $json);
            $this->assertStringNotContainsString('loid-canary', (string) $json);
            $this->assertStringNotContainsStringIgnoringCase('password', (string) $json);
            $this->assertStringNotContainsStringIgnoringCase('checkcode', (string) $json);
        }
    }

    public function test_partial_flag_when_footer_count_differs(): void
    {
        $result = $this->service()->parseAutofindEntries(self::fixture('autofind-partial.txt'));

        $this->assertCount(2, $result['entries']);
        $this->assertTrue($result['partial']);
        $this->assertSame(3, $result['total_reported']);
    }

    public function test_bad_output_throws_plain_message(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OLT menolak perintah daftar ONT.');

        $this->service()->parseAutofindEntries(self::fixture('autofind-bad.txt'));
    }

    /** @return array<string, array{string, string}> */
    public static function serials(): array
    {
        return [
            'huawei hex' => ['485754430000A005', 'HWTC0000A005'],
            'fiberhome hex' => ['464854540000A052', 'FHTT0000A052'],
            'alcl hex' => ['414C434CA1B2C3D4', 'ALCLA1B2C3D4'],
            'zteg hex' => ['5A5445470000A002', 'ZTEG0000A002'],
            'dashed' => ['FHTT-0000A052', 'FHTT0000A052'],
            'already canonical' => ['HWTC0000A005', 'HWTC0000A005'],
            'lowercase' => ['fhtt0000a052', 'FHTT0000A052'],
            'lowercase hex' => ['464854540000a052', 'FHTT0000A052'],
            '16 hex not letters stays' => ['0123456789ABCDEF', '0123456789ABCDEF'],
            'empty' => ['', ''],
        ];
    }

    #[DataProvider('serials')]
    public function test_canonical_serial(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->service()->canonicalSerial($input));
    }

    public function test_port_empty_answer_is_only_accepted_when_allowed(): void
    {
        $service = $this->service();
        $raw = self::fixture('port-empty.txt');

        $this->assertSame(
            ['pon_port' => '0/0/5', 'onus' => [], 'occupied_ids' => []],
            $service->parsePortOnusOutput($raw, '0/0/5', true),
        );

        $this->expectException(RuntimeException::class);
        $service->parsePortOnusOutput($raw, '0/0/5', false);
    }

    public function test_port_help_text_is_never_empty(): void
    {
        $this->expectException(RuntimeException::class);

        $this->service()->parsePortOnusOutput(self::fixture('port-help.txt'), '0/0/5', true);
    }
}
