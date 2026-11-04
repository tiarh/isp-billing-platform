<?php

namespace Tests\Unit\Psb;

use App\Services\PsbMessageParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The number after `vlan` is the VLAN, never a draft #ID. Only `#12`, `draft 12`
 * and the other command words (router, lanjut, ...) give a draft id.
 */
#[Group('blocking-psb')]
class PsbParserCommandIdTest extends TestCase
{
    /** @return array<string, array{0: string, 1: ?int, 2: ?int}> */
    public static function cases(): array
    {
        return [
            '#psb vlan N is not a draft id' => ['#psb vlan 100', null, 100],
            '#psb vlan N #ID uses the #ID' => ['#psb vlan 100 #812', 812, 100],
            '/psb vlan 4094 is not a draft id' => ['/psb vlan 4094', null, 4094],
            '#psb router alias #ID' => ['#psb router krian #12', 12, null],
        ];
    }

    #[DataProvider('cases')]
    public function test_draft_id_and_vlan_value(string $text, ?int $draftId, ?int $vlan): void
    {
        $parsed = (new PsbMessageParser)->parse($text);

        $this->assertSame($draftId, $parsed['draft_id']);
        $this->assertSame($vlan, $parsed['vlan_value']);
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function fullFormCases(): array
    {
        return [
            'SN first + Nama' => ["#psb\nSN: FHTTC0FFEE09\nNama: SITI CONTOH", false],
            'SN first + HP' => ["#psb\nSN: FHTTC0FFEE09\nHP: 081200000099", false],
            'SN first + Alamat' => ["#psb\nSN: FHTTC0FFEE09\nAlamat: Jalan Mawar 1", false],
            'SN first, empty Nama, no HP: still the sn command' => ["#psb\nSN: FHTTC0FFEE09\nNama:", true],
            'SN only: still the sn command' => ["#psb\nSN: FHTTC0FFEE09", true],
            'short sn command' => ['#psb sn FHTTC0FFEE09', true],
            'short sn command with id' => ['#psb sn FHTTC0FFEE09 #12', true],
            'slash sn command' => ['/psb sn FHTTC0FFEE09', true],
            'sn with #ID and a Nama line stays a command' => ["#psb sn FHTTC0FFEE09 #12\nNama: SITI CONTOH", true],
        ];
    }

    #[DataProvider('fullFormCases')]
    public function test_sn_first_full_form_is_not_the_sn_command(string $text, bool $isSnCmd): void
    {
        $this->assertSame($isSnCmd, (new PsbMessageParser)->parse($text)['is_sn_cmd']);
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function routerFormCases(): array
    {
        return [
            'Router first + Nama' => ["#psb\nRouter: RA\nNama: SITI CONTOH\nPaket: 200M", false],
            'Router first + HP' => ["#psb\nRouter: RA\nHP: 081200000099", false],
            'short router command' => ['#psb router ra', true],
            'short router command with id' => ['#psb router ra #812', true],
        ];
    }

    #[DataProvider('routerFormCases')]
    public function test_router_first_full_form_is_not_the_router_command(string $text, bool $isRouterCmd): void
    {
        $parsed = (new PsbMessageParser)->parse($text);

        $this->assertSame($isRouterCmd, $parsed['is_router_cmd']);
        $this->assertTrue($parsed['is_psb']);
    }

    public function test_lanjut_and_router_numbers_stay_draft_ids(): void
    {
        $parser = new PsbMessageParser;

        $this->assertSame(55, $parser->parse('#psb lanjut 55')['draft_id']);
        $this->assertSame(7, $parser->parse('#psb router 7')['draft_id']);
    }
}
