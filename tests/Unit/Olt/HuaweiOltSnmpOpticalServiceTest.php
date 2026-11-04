<?php

namespace Tests\Unit\Olt;

use App\Jobs\SyncOltOpticalJob;
use App\Services\HuaweiOltSnmpOpticalService;
use PHPUnit\Framework\TestCase;

class HuaweiOltSnmpOpticalServiceTest extends TestCase
{
    private const IF_NAME_OID = '1.3.6.1.2.1.31.1.1.1.1';

    private const SERIAL_OID = '1.3.6.1.4.1.2011.6.128.1.1.2.43.1.3';

    private const RX_OID = '1.3.6.1.4.1.2011.6.128.1.1.2.51.1.4';

    private const DISTANCE_OID = '1.3.6.1.4.1.2011.6.128.1.1.2.46.1.20';

    public function test_it_maps_huawei_port_index_ont_id_and_decodes_optics(): void
    {
        $service = new HuaweiOltSnmpOpticalService;
        $ifNames = $service->parseIfNameWalk(
            '.'.self::IF_NAME_OID.'.4194304000 = STRING: "GPON 0/0/0"'.PHP_EOL
                .'.'.self::IF_NAME_OID.'.4194304256 = STRING: "GPON 0/0/1"',
            self::IF_NAME_OID,
        );
        $serials = $service->parseSerialWalk(
            '.'.self::SERIAL_OID.'.4194304000.2 = Hex-STRING: 46 48 54 54 00 00 A0 46'.PHP_EOL,
            self::SERIAL_OID,
        );
        $rx = $service->parseRxWalk(
            '.'.self::RX_OID.'.4194304000.2 = INTEGER: -2137',
            self::RX_OID,
        );
        $distance = $service->parseDistanceWalk(
            '.'.self::DISTANCE_OID.'.4194304000.2 = INTEGER: 1912',
            self::DISTANCE_OID,
        );

        $rows = $service->buildOpticalRows($ifNames, $serials, $rx, $distance);

        self::assertCount(1, $rows);
        self::assertSame('0/0/0:2', $rows[0]['onu_ref']);
        self::assertSame('4194304000.2', $rows[0]['snmp_index']);
        self::assertSame('FHTT0000A046', $rows[0]['serial_number']);
        self::assertSame(-21.37, $rows[0]['rx_power_dbm']);
        self::assertSame(1912, $rows[0]['distance_m']);
    }

    public function test_it_rejects_rx_sentinels_invalid_distance_and_unmapped_ports(): void
    {
        $service = new HuaweiOltSnmpOpticalService;
        $ifNames = $service->parseIfNameWalk(
            '.'.self::IF_NAME_OID.'.4194304000 = STRING: "GPON 0/0/0"',
            self::IF_NAME_OID,
        );
        $serials = $service->parseSerialWalk(
            implode(PHP_EOL, [
                '.'.self::SERIAL_OID.'.4194304000.2 = Hex-STRING: 46 48 54 54 00 00 A0 46',
                '.'.self::SERIAL_OID.'.9999999999.3 = Hex-STRING: 46 48 54 54 00 00 A0 47',
            ]),
            self::SERIAL_OID,
        );
        $rx = $service->parseRxWalk(
            '.'.self::RX_OID.'.4194304000.2 = INTEGER: 2147483647',
            self::RX_OID,
        );
        $distance = $service->parseDistanceWalk(
            '.'.self::DISTANCE_OID.'.4194304000.2 = INTEGER: -1',
            self::DISTANCE_OID,
        );

        $rows = $service->buildOpticalRows($ifNames, $serials, $rx, $distance);

        self::assertCount(1, $rows);
        self::assertSame('0/0/0:2', $rows[0]['onu_ref']);
        self::assertNull($rows[0]['rx_power_dbm']);
        self::assertNull($rows[0]['distance_m']);
    }

    public function test_it_does_not_treat_the_live_status_oid_as_distance_by_default(): void
    {
        $service = new HuaweiOltSnmpOpticalService;
        $distance = $service->parseDistanceWalk(
            '.1.3.6.1.4.1.2011.6.128.1.1.2.43.1.10.4194304000.2 = INTEGER: 1',
            '1.3.6.1.4.1.2011.6.128.1.1.2.46.1.20',
        );

        self::assertSame([], $distance);
    }

    public function test_it_accepts_a_gpon_if_name_without_a_space(): void
    {
        $service = new HuaweiOltSnmpOpticalService;
        $ifNames = $service->parseIfNameWalk(
            '.'.self::IF_NAME_OID.'.4194304256 = STRING: "GPON0/0/1"',
            self::IF_NAME_OID,
        );

        self::assertSame(['4194304256' => '0/0/1'], $ifNames);
    }

    public function test_snmp_child_process_timeout_is_bounded_but_longer_than_a_walk_timeout(): void
    {
        $service = new class extends HuaweiOltSnmpOpticalService
        {
            public function timeoutForTest(int $walkTimeout, int $configuredTimeout): int
            {
                return $this->boundedProcessTimeout($walkTimeout, $configuredTimeout);
            }
        };

        self::assertSame(30, $service->timeoutForTest(2, 30));
        self::assertSame(15, $service->timeoutForTest(10, 2));
        self::assertSame(60, $service->timeoutForTest(2, 90));
    }

    public function test_optical_job_is_unique_on_the_olt_sync_queue_and_shares_the_huawei_read_lock(): void
    {
        $job = new SyncOltOpticalJob(11);

        self::assertSame('olt-sync', $job->queue);
        self::assertSame('huawei-olt-optical:11', $job->uniqueId());
        self::assertCount(1, $job->middleware());
    }

    public function test_optical_job_retries_once_after_a_killed_worker_and_fails_loudly_on_timeout(): void
    {
        $job = new SyncOltOpticalJob(11);

        self::assertSame(2, $job->tries);
        self::assertTrue($job->failOnTimeout);
        self::assertSame(120, $job->timeout);

        $lock = $job->middleware()[0];
        self::assertNull($lock->releaseAfter);
    }
}
