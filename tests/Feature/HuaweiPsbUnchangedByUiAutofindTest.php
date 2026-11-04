<?php

namespace Tests\Feature;

use App\Models\Olt;
use App\Models\PsbDraft;
use App\Services\HuaweiOltOnlyProvisioningService;
use App\Services\HuaweiPsbProvisioningService;
use App\Services\MikrotikService;
use Mockery;
use phpseclib3\Net\SSH2;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;
use Tests\Unit\Olt\HuaweiAutofindParserTest;

/**
 * Pins the deliberate scope of the "Lihat ONT baru" PR: the empty-PON and 16-hex
 * fixes exist ONLY on the UI path. PSB and migration keep their fail-closed
 * behaviour (collectPortOnus throws on an empty PON, collectAutofind result
 * shape and its raw-text cut are unchanged). Flip these when the follow-up PR
 * extends the fixes to PSB/migration.
 */
#[Group('blocking-psb')]
class HuaweiPsbUnchangedByUiAutofindTest extends TestCase
{
    public function test_collect_port_onus_still_throws_on_an_empty_pon(): void
    {
        $service = $this->service(HuaweiAutofindParserTest::fixture('autofind-one.txt'), [
            'display ont info 0 0 5 all' => HuaweiAutofindParserTest::fixture('port-empty.txt'),
        ]);

        try {
            $service->collectPortOnus($this->olt(), '0/0/5');
            $this->fail('collectPortOnus must stay fail-closed on an empty PON');
        } catch (RuntimeException $e) {
            $this->assertSame('Huawei menolak command display ont info 0 0 5 all.', $e->getMessage());
        }
    }

    public function test_collect_autofind_shape_and_raw_cut_are_unchanged(): void
    {
        $many = HuaweiAutofindParserTest::fixture('autofind-many.txt');
        $service = $this->service($many, []);

        $result = $service->collectAutofind($this->olt());

        $this->assertSame(['matches', 'raw', 'command'], array_keys($result));
        $this->assertSame('display ont autofind all', $result['command']);
        $this->assertSame(mb_substr($many, 0, 5000), $result['raw']);
        $this->assertSame($service->parseAutofindOutput(mb_substr($many, 0, 5000)), $result['matches']);
        foreach ($result['matches'] as $row) {
            $this->assertSame(['pon_port', 'serial_number', 'vendor_id'], array_keys($row));
        }
    }

    public function test_psb_preflight_still_fails_closed_on_an_empty_pon(): void
    {
        $service = $this->service(HuaweiAutofindParserTest::fixture('autofind-one.txt'), [
            'display ont info 0 0 3 all' => "display ont info 0 0 3 all\n  Failure: There is no ONT available\nMA5608T#\n",
        ]);

        $result = (new HuaweiPsbProvisioningService($service))->preflight(
            new PsbDraft(['sn' => 'HWTC0000A005']),
            new Olt(['id' => 11, 'name' => 'OLT GADANG 3', 'vendor' => 'huawei_ma5608t']),
        );

        $this->assertFalse($result['eligible']);
        $this->assertSame('huawei_preflight_rejected', $result['reason']);
    }

    /** @param array<string, string> $portOutputs */
    private function service(string $autofind, array $portOutputs): HuaweiOltOnlyProvisioningService
    {
        return new class(Mockery::mock(MikrotikService::class), $autofind, $portOutputs) extends HuaweiOltOnlyProvisioningService
        {
            /** @param array<string, string> $outputs */
            public function __construct(MikrotikService $mikrotik, private string $autofindOutput, private array $outputs)
            {
                parent::__construct($mikrotik);
            }

            protected function connect(Olt $olt): SSH2
            {
                return Mockery::mock(SSH2::class, ['disconnect' => null]);
            }

            protected function run(SSH2 $ssh, string $command): string
            {
                if ($command === 'display ont autofind all') {
                    return $this->autofindOutput;
                }

                return $this->outputs[$command] ?? "Failure: Parameters error\n";
            }

            public function collectSnapshot(Olt $olt): array
            {
                return ['onus' => []];
            }
        };
    }

    private function olt(): Olt
    {
        return new Olt(['id' => 11, 'name' => 'OLT GADANG 3', 'vendor' => 'huawei_ma5608t', 'management_ip' => '198.18.4.1']);
    }
}
