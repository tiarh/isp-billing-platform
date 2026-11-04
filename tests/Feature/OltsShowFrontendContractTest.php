<?php

namespace Tests\Feature;

use Tests\TestCase;

class OltsShowFrontendContractTest extends TestCase
{
    public function test_olt_show_keeps_serial_visible_when_snapshot_serial_is_missing(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Olts/Show.tsx'));

        $this->assertStringContainsString('const customerSerialByRef = useMemo', $source);
        $this->assertStringContainsString(
            "const displaySerial = onu.serial_number || (matched ? customerSerialByRef.get(normalizeOnuRefKey(refKey)) : null) || '-';",
            $source,
        );
        $this->assertStringContainsString('<span>{displaySerial}</span>', $source);
    }
}
