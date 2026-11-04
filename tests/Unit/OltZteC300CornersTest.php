<?php

namespace Tests\Unit;

use App\Models\Olt;
use App\Services\OltZteC300Service;
use Mockery;
use phpseclib3\Net\SSH2;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\TestCase;

#[Group('blocking-core')]
class OltZteC300CornersTest extends TestCase
{
    public function test_collect_snapshot_returns_empty_result_when_pexpect_finds_no_onus(): void
    {
        $service = new PexpectNoOnuZteService;

        $snapshot = $service->collectSnapshot(new Olt);

        $this->assertSame([], $snapshot['onus']);
        $this->assertSame('pexpect snapshot returned no ONUs; phpseclib fallback removed', $snapshot['meta']['warning']);
    }

    public function test_prepare_shell_sends_the_same_command_sequence_as_before(): void
    {
        $calls = [];

        $ssh = Mockery::mock(SSH2::class);
        // enablePTY() takes no parameters in phpseclib 3: it must be called with none.
        $ssh->shouldReceive('enablePTY')->once()->withNoArgs()->andReturnUsing(function () use (&$calls) {
            $calls[] = 'enablePTY';
        });
        $ssh->shouldReceive('setTimeout')->andReturnUsing(function ($timeout) use (&$calls) {
            $calls[] = 'setTimeout:'.$timeout;
        });
        $ssh->shouldReceive('write')->andReturnUsing(function ($data) use (&$calls) {
            $calls[] = 'write:'.json_encode($data);

            return true;
        });
        $ssh->shouldReceive('read')->andReturnUsing(function () use (&$calls) {
            $calls[] = 'read';

            return 'FAKE-OLT# ';
        });

        $service = new OltZteC300Service;
        $method = new ReflectionMethod($service, 'prepareShell');
        $method->setAccessible(true);
        $method->invoke($service, $ssh, 'canary-pw');

        $this->assertSame([
            'enablePTY',
            'setTimeout:0.25',
            'read',
            'write:"enable\n"',
            'read',
            'write:"terminal page-break disable\n"',
            'read',
            'write:"terminal length 0\n"',
            'read',
        ], $calls);
    }
}

/** Test double: the pexpect collector finds nothing (the OLT is never contacted). */
class PexpectNoOnuZteService extends OltZteC300Service
{
    protected function collectSnapshotViaPexpect(Olt $olt, ?float $deadline = null): ?array
    {
        return null;
    }
}
