<?php

namespace Tests\Support\PsbGolden;

use App\Exceptions\HuaweiOltCommandException;
use App\Exceptions\HuaweiOltSessionBusyException;
use App\Models\Olt;
use App\Services\HuaweiOltOnlyProvisioningService;
use App\Services\MikrotikService;

/**
 * Golden-test recording fake of the Huawei SSH layer (read-only discovery and the
 * plan execution). Plan building, placement maths and profile lookup run for real.
 */
final class PsbGoldenHuaweiOltOnly extends HuaweiOltOnlyProvisioningService
{
    /** @var list<array<string, mixed>> */
    public array $autofind = [];

    /** @var list<array<string, mixed>> */
    public array $registered = [];

    /** Additive switches (conductor goldens s18-s22); the defaults keep s01-s17 unchanged. */

    /** @var bool the SSH session lock is busy: discovery throws before any command is sent */
    public bool $sessionBusy = false;

    /** @var bool executePlan fails on a command */
    public bool $executeThrows = false;

    /** @var bool executePlan answers "verified" but wrote nothing (write guard closed) */
    public bool $writeBlocked = false;

    /** @var bool executePlan finds the SSH session busy on its first connect: no command is sent */
    public bool $executeBusy = false;

    public function __construct(MikrotikService $mikrotik, private PsbGoldenRecorder $rec)
    {
        parent::__construct($mikrotik);
    }

    public function collectAutofind(Olt $olt): array
    {
        $this->rec->add('huawei.collectAutofind', ['olt' => (string) $olt->code]);
        if ($this->sessionBusy) {
            throw new HuaweiOltSessionBusyException;
        }

        return ['matches' => $this->autofind, 'command' => 'display ont autofind all'];
    }

    public function collectSnapshot(Olt $olt): array
    {
        $this->rec->add('huawei.collectSnapshot', ['olt' => (string) $olt->code]);

        return ['onus' => $this->registered];
    }

    public function collectPortOnus(Olt $olt, string $ponPort): array
    {
        $this->rec->add('huawei.collectPortOnus', ['olt' => (string) $olt->code, 'pon' => $ponPort]);

        return ['onus' => [], 'command' => 'display ont info '.$ponPort.' all'];
    }

    public function executePlan(Olt $olt, array $plan, array $approval = []): array
    {
        $this->rec->add('huawei.executePlan', [
            'olt' => (string) $olt->code,
            'commands' => array_values(array_map('strval', (array) ($plan['commands'] ?? []))),
            'approval_present' => $approval !== [],
        ]);
        if ($this->executeBusy) {
            throw new HuaweiOltSessionBusyException;
        }
        if ($this->executeThrows) {
            throw new HuaweiOltCommandException('command_failed', 'provision', 2, 'ont add', 'RuntimeException', 'OLT rejected a command');
        }
        if ($this->writeBlocked) {
            return ['verified' => true, 'device_write' => false];
        }

        return ['verified' => true, 'device_write' => true];
    }
}
