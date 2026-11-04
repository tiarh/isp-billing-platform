<?php

namespace Tests\Unit\Olt;

use App\Services\OltZteC300Service;
use PHPUnit\Framework\TestCase;

class ZteOpticalParseTest extends TestCase
{
    public function test_it_converts_zte_distance_km_and_metres(): void
    {
        self::assertSame(1250, OltZteC300Service::parseDistanceMeters("DISTANCE(km): 1.25\n"));
        self::assertSame(1234, OltZteC300Service::parseDistanceMeters('ONU Distance: 1234m'));
        self::assertSame(1234, OltZteC300Service::parseDistanceMeters('Distance(m): 1234'));
        self::assertNull(OltZteC300Service::parseDistanceMeters('Distance: 0m'));
    }

    public function test_it_reads_serials_with_or_without_a_hyphen(): void
    {
        self::assertSame('ZTEGFAKE0001', OltZteC300Service::extractCliSerial('SerialNumber: ZTEG-FAKE0001'));
        self::assertSame('ZTEGFAKE0001', OltZteC300Service::extractCliSerial('Serial Number: ZTEG-FAKE0001'));
        self::assertSame('FHTT0000A046', OltZteC300Service::extractCliSerial('SN: FHTT0000A046'));
        self::assertNull(OltZteC300Service::extractCliSerial('Serial Number: ZTEG'));
    }

    public function test_it_rejects_snmp_identity_values_outside_the_safe_window(): void
    {
        self::assertSame('FHTT0000A046', OltZteC300Service::normalizeSnmpSerial('fhtt-0000a046'));
        self::assertNull(OltZteC300Service::normalizeSnmpSerial('ONLINE'));
        self::assertSame(1912, OltZteC300Service::normalizeSnmpDistance(1912));
        self::assertNull(OltZteC300Service::normalizeSnmpDistance(0));
        self::assertNull(OltZteC300Service::normalizeSnmpDistance(65535));
    }

    public function test_it_strips_the_snmp_walk_leading_digit_from_vendor_serials(): void
    {
        self::assertSame('FHTT0000A019', OltZteC300Service::normalizeSnmpSerial('1FHTT0000A019'));
        self::assertSame('FHTT0000A019', OltZteC300Service::normalizeSnmpSerial('1fhtt-0000A019'));
        self::assertSame('ZTEGFAKE0001', OltZteC300Service::normalizeSnmpSerial('1ZTEGFAKE0001'));
        self::assertSame('FHTT0000A019', OltZteC300Service::extractCliSerial('SN: 1FHTT0000A019'));
    }

    public function test_it_never_strips_genuine_serials_starting_with_one(): void
    {
        // No vendor prefix after the leading digit: keep as-is.
        self::assertSame('100012345678', OltZteC300Service::normalizeSnmpSerial('100012345678'));
        self::assertNull(OltZteC300Service::normalizeSnmpSerial('1ONLINE'));
    }
}
