<?php

namespace Tests\Unit;

use App\Support\KtpParserV2;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\Canary;
use Tests\Support\SyntheticData;

/**
 * The fake-value helpers every test uses (public export plan). Their shapes are a contract: they must stay
 * obviously fake, and the values that feed app logic (NIK, serial twin, area box) must stay valid.
 */
#[Group('blocking-core')]
class SyntheticDataTest extends TestCase
{
    public function test_onu_serial_has_the_fake_marker_and_a_non_numeric_hex_twin(): void
    {
        $this->assertSame('FHTT0000A001', SyntheticData::onuSerial('FHTT', 1));
        $this->assertSame('HWTC0000AFFF', SyntheticData::onuSerial('HWTC', 4095));
        $this->assertSame('ZTEG0000A010', SyntheticData::onuSerial('ZTEG', 16));

        $twin = SyntheticData::onuSerialHex('FHTT0000A001');
        $this->assertSame('464854540000A001', $twin);
        $this->assertSame(16, strlen($twin));
        $this->assertSame(0, preg_match('/^\d{16}$/', $twin), 'the twin must not look like a NIK or KK');
    }

    public function test_onu_serial_refuses_other_vendors_and_out_of_range_numbers(): void
    {
        foreach ([['FHTT', 0], ['FHTT', 4096], ['ABCD', 1]] as [$vendor, $n]) {
            try {
                SyntheticData::onuSerial($vendor, $n);
                $this->fail("{$vendor} {$n} was accepted");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_addresses_stay_in_the_reserved_ranges(): void
    {
        $this->assertSame('198.18.0.1', SyntheticData::routerIp());
        $this->assertSame('198.18.1.7', SyntheticData::oltIp(7));
        $this->assertSame('198.18.20.2', SyntheticData::poolAIp());
        $this->assertSame('198.19.1.2', SyntheticData::poolBIp());
        $this->assertSame('203.0.113.10', SyntheticData::publicIp());
        $this->assertSame('192.0.2.1', SyntheticData::gatewayIp());

        foreach ([SyntheticData::routerIp(255), SyntheticData::oltIp(0), SyntheticData::publicIp(1)] as $ip) {
            $this->assertNotFalse(filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4), $ip);
        }

        $this->expectException(InvalidArgumentException::class);
        SyntheticData::routerIp(256);
    }

    public function test_pppoe_name_uses_the_user_prefix_and_refuses_bad_words(): void
    {
        $this->assertSame('USERBUDI_RT01_RW02', SyntheticData::pppoe('budi', 1, 2));
        $this->assertSame('USERBUDI_RT03_RW04_5', SyntheticData::pppoe('BUDI', 3, 4, 5));

        foreach (['', 'TOOLONGWORD123', 'BU DI', 'BUDI_X'] as $word) {
            try {
                SyntheticData::pppoe($word);
                $this->fail("'{$word}' was accepted");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_phones_and_jids_carry_a_run_of_zeros(): void
    {
        $this->assertSame('081200000007', SyntheticData::phone(7));
        $this->assertSame('6281200000042', SyntheticData::phoneIntl(42));
        $this->assertSame('6281200000042@s.whatsapp.net', SyntheticData::personalJid(42));
        $this->assertSame('120363000000000012@g.us', SyntheticData::groupJid(12));

        $this->assertSame(1, preg_match('/0{5,}/', SyntheticData::phone(99)));
        $this->assertSame(1, preg_match('/0{5,}/', SyntheticData::groupJid(999)));
    }

    public function test_nik_is_valid_for_the_ktp_parser_in_both_genders(): void
    {
        foreach ([[1, 1], [31, 9], [41, 1], [71, 5]] as [$day, $n]) {
            $nik = SyntheticData::nik($day, $n);
            $this->assertSame(16, strlen($nik));
            $this->assertTrue(KtpParserV2::isValidNik($nik), $nik);
            $this->assertSame(1, preg_match('/0{6,}/', $nik));
        }

        $this->assertSame('3507120110000001', SyntheticData::nik());
    }

    public function test_coordinates_stay_inside_the_area_box(): void
    {
        foreach ([0, 1, 500, 999] as $i) {
            $point = SyntheticData::coordinate($i);
            $this->assertGreaterThanOrEqual(-9.0, $point['lat']);
            $this->assertLessThanOrEqual(-6.0, $point['lat']);
            $this->assertGreaterThanOrEqual(110.0, $point['lng']);
            $this->assertLessThanOrEqual(115.0, $point['lng']);
        }
        $this->assertSame(['lat' => -6.5, 'lng' => 111.5], SyntheticData::coordinate());
    }

    public function test_canary_accepts_lowercase_slugs_only(): void
    {
        $this->assertSame('canary-a', Canary::of('a'));
        $this->assertSame('canary-'.str_repeat('a', 40), Canary::of(str_repeat('a', 40)));

        // real-shaped values are built from pieces so this file holds none
        $bad = ['', 'Qv7z'.'Rk9mXp', 'has space', 'under_score', 'dot.ted', "pw\n", str_repeat('a', 41), "'quoted'", 'canary-x/../y'];
        foreach ($bad as $i => $value) {
            try {
                Canary::of($value);
                $this->fail("Canary::of accepted bad value {$i}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_canary_values_and_hosts_are_reserved(): void
    {
        $this->assertSame('canary-pw', Canary::of());
        $this->assertSame('canary-token', Canary::of('token'));
        $this->assertSame('canary-pppoe-pw', Canary::of('pppoe-pw'));

        $this->assertSame('example.test', SyntheticData::host());
        $this->assertSame('pay.example.test', SyntheticData::host('pay'));
    }
}
