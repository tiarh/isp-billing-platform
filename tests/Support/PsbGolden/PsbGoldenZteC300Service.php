<?php

namespace Tests\Support\PsbGolden;

use App\Models\Olt;
use App\Services\OltZteC300Service;

/** Golden-test recording fake: the live ONU rename after a PSB correction. */
final class PsbGoldenZteC300Service extends OltZteC300Service
{
    public function __construct(private PsbGoldenRecorder $rec) {}

    public function renameOnu(Olt $olt, string $onuRef, string $name): array
    {
        $this->rec->add('olt.renameOnu', ['olt' => (string) $olt->code, 'onu_ref' => $onuRef, 'name' => $name]);

        return ['success' => true];
    }
}
