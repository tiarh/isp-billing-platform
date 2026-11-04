<?php

namespace Tests\Feature;

use App\Models\Olt;
use App\Models\OltOperationLog;
use App\Support\OltResponseSanitizer;
use Tests\TestCase;

class OltSecurityContractTest extends TestCase
{
    public function test_olt_snmp_community_and_operation_payloads_are_encrypted_at_rest(): void
    {
        $olt = new Olt;
        $olt->snmp_community = 'canary-community';

        $rawOlt = (string) ($olt->getAttributes()['snmp_community'] ?? '');
        $this->assertNotSame('canary-community', $rawOlt);
        $this->assertSame('canary-community', $olt->snmp_community);

        $log = new OltOperationLog;
        $log->payload = ['output' => 'device-output-test-only', 'password' => 'secret-test-only'];
        $log->result = ['commands' => ['show version']];
        $log->error = 'password=secret-test-only';

        $rawPayload = (string) ($log->getAttributes()['payload'] ?? '');
        $rawResult = (string) ($log->getAttributes()['result'] ?? '');
        $rawError = (string) ($log->getAttributes()['error'] ?? '');
        $this->assertNotSame('', $rawPayload);
        $this->assertNotSame('', $rawResult);
        $this->assertStringNotContainsString('device-output-test-only', $rawPayload);
        $this->assertStringNotContainsString('secret-test-only', $rawPayload);
        $this->assertStringNotContainsString('show version', $rawResult);
        $this->assertStringNotContainsString('secret-test-only', $rawError);
        $this->assertSame('device-output-test-only', $log->payload['output']);
        $this->assertSame(['show version'], $log->result['commands']);
        $this->assertSame('password=secret-test-only', $log->error);
    }

    public function test_olt_response_sanitizer_removes_device_output_and_redacts_credentials(): void
    {
        $sanitized = (new OltResponseSanitizer)->sanitize([
            'output' => 'raw device output',
            'raw' => ['password=secret-test-only'],
            'commands' => ['configure terminal'],
            'nested' => ['community' => 'canary-community'],
            'safe' => 'kept',
        ]);

        $this->assertSame('[REDACTED_DEVICE_OUTPUT]', $sanitized['output']);
        $this->assertSame('[REDACTED_DEVICE_OUTPUT]', $sanitized['raw']);
        $this->assertSame('[REDACTED_DEVICE_COMMANDS]', $sanitized['commands']);
        $this->assertSame('[REDACTED]', $sanitized['nested']['community']);
        $this->assertSame('kept', $sanitized['safe']);
    }

    public function test_olt_frontend_serialization_does_not_expose_snmp_secret_or_raw_result(): void
    {
        $controller = (string) file_get_contents(base_path('app/Http/Controllers/OltController.php'));
        $model = (string) file_get_contents(base_path('app/Models/Olt.php'));

        $this->assertStringContainsString("'snmp_community' => SafeEncrypted::class", $model);
        $this->assertStringContainsString("'snmp_community' => null", $controller);
        $this->assertStringContainsString('snmp_community_configured', $controller);
        $this->assertStringContainsString("unset(\$result['commands'], \$result['outputs'], \$result['raw'], \$result['detail_raw'])", $controller);
    }
}
