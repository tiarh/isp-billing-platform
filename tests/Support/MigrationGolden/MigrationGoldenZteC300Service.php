<?php

namespace Tests\Support\MigrationGolden;

use App\Models\Olt;
use App\Services\OltZteC300Service;
use Tests\Support\PsbGolden\PsbGoldenRecorder;

/** Migration golden-test recording fake: the old-ONU delete and the live ONU rename. */
final class MigrationGoldenZteC300Service extends OltZteC300Service
{
    /** @var array<string, mixed> answer of deleteOnu */
    public array $deleteAnswer = ['success' => true, 'message' => 'deleted'];

    public function __construct(private PsbGoldenRecorder $rec) {}

    /** @return array<string, mixed> */
    public function deleteOnu(Olt $olt, string $onuRef): array
    {
        $this->rec->add('olt.deleteOnu', ['olt' => (string) $olt->code, 'onu_ref' => $onuRef]);

        return $this->deleteAnswer;
    }

    public function renameOnu(Olt $olt, string $onuRef, string $name): array
    {
        $this->rec->add('olt.renameOnu', ['olt' => (string) $olt->code, 'onu_ref' => $onuRef, 'name' => $name]);

        return ['success' => true];
    }
}
