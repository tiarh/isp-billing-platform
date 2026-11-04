<?php

namespace Tests\Support\PsbGolden;

use App\Services\GenieAcsService;

/** Golden-test recording fake: the ACS tag push after a PSB correction. */
final class PsbGoldenGenieAcsService extends GenieAcsService
{
    /** @var bool the tag is only queued (the CPE did not inform yet); the default keeps every older golden unchanged */
    public bool $tagPending = false;

    public function __construct(private PsbGoldenRecorder $rec) {}

    public function ensureTagAfterProvision(string $serial, string $name): array
    {
        $this->rec->add('acs.ensureTagAfterProvision', ['serial' => $serial, 'name' => $name]);

        return $this->tagPending ? ['pushed' => false, 'pending' => true, 'queued' => true] : ['pushed' => true];
    }
}
