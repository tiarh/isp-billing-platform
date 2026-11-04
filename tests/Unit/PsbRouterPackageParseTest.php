<?php

namespace Tests\Unit;

use App\Services\PsbMessageParser;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('blocking-psb')]
class PsbRouterPackageParseTest extends TestCase
{
    public function test_parses_router_and_package_required_fields(): void
    {
        $text = <<<'TXT'
#psb
Nama: GATOT SUBROTO
ODP: ODP-01
RT: 01
RW: 02
Tikor: -6.98, 111.63
Daerah: Krian
Router: krian
Paket: 200M
SN: FHTT1234567890
TXT;

        $p = (new PsbMessageParser)->parse($text);

        $this->assertTrue($p['is_psb']);
        $this->assertSame('GATOT SUBROTO', $p['full_name']);
        $this->assertSame('Krian', $p['daerah']);
        $this->assertSame('krian', $p['router_field']);
        $this->assertSame('krian', $p['router_alias']);
        $this->assertSame('200M', $p['package_field']);
        $this->assertSame('FHTT1234567890', $p['sn']);
    }

    public function test_package_aliases_and_startlite(): void
    {
        $p = (new PsbMessageParser)->parse("#psb\nNama: A\nODP: 1\nRT: 1\nRW: 1\nTikor: 0,0\nDaerah: X\nRouter: ra\nPaket: STARTLITE\nSN: FHTTAAAABBBBCCCC");

        $this->assertSame('ra', $p['router_field']);
        $this->assertSame('STARTLITE', $p['package_field']);
    }

    public function test_preserves_decimal_odp_label(): void
    {
        $p = (new PsbMessageParser)->parse("#psb\nNama: A\nODP: 6.5\nRT: 1\nRW: 1\nTikor: 0,0\nDaerah: Gadang\nRouter: Gadang\nPaket: 100M\nSN: FHTTAAAABBBBCCCC");

        $this->assertSame('6.5', $p['odp']);
    }

    public function test_strips_whatsapp_invisible_chars_before_labels(): void
    {
        $text = "#psb\n‎Nama: RUSITA\n‎ODP: ODP-RANDUTELU-L4\n‎RT: 02\n‎RW: 10\n‎Tikor: -6.872131,111.677182\n‎Daerah: RANDUTELU\n‎Router: RA\n‎Paket: STARTLITE\n‎SN: FHTT0000A024\n‎HP:‪‪‪ 0812000000049";

        $p = (new PsbMessageParser)->parse($text);

        $this->assertSame('RUSITA', $p['full_name']);
        $this->assertSame('ODP-RANDUTELU-L4', $p['odp']);
        $this->assertSame('02', $p['rt']);
        $this->assertSame('10', $p['rw']);
        $this->assertSame('-6.872131,111.677182', $p['tikor']);
        $this->assertSame('RANDUTELU', $p['daerah']);
        $this->assertSame('ra', $p['router_field']);
        $this->assertSame('STARTLITE', $p['package_field']);
        $this->assertSame('FHTT0000A024', $p['sn']);
        $this->assertSame('0812000000049', $p['phone']);
    }
}
