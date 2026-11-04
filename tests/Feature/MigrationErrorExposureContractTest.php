<?php

namespace Tests\Feature;

use Tests\TestCase;

class MigrationErrorExposureContractTest extends TestCase
{
    public function test_migration_error_surfaces_use_the_sensitive_data_redactor(): void
    {
        $source = file_get_contents(base_path('app/Services/CustomerMigrationService.php'));

        $this->assertStringContainsString(
            "'warning' => 'MikroTik check dilewati: '.SensitiveDataRedactor::redact(\$e->getMessage())",
            $source,
        );
        $this->assertStringContainsString(
            "'warning' => 'Profile target belum terverifikasi: '.SensitiveDataRedactor::redact(\$e->getMessage())",
            $source,
        );
        $this->assertStringContainsString(
            "'error' => SensitiveDataRedactor::redact(\$e->getMessage())",
            $source,
        );
    }
}
