<?php

namespace Tests\Feature;

use App\Http\Controllers\OltC300Controller;
use App\Models\Olt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OltC300PasswordGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_zte_provisioning_rejects_missing_password_before_device_lookup(): void
    {
        $olt = Olt::create([
            'name' => 'OLT Password Guard',
            'code' => 'OLT-PASSWORD-GUARD',
            'vendor' => 'zte_c300',
        ]);

        $payloads = [
            'activate' => [
                'olt_id' => $olt->id,
                'sn' => 'FHTTGUARD001',
                'name' => 'CUSTOMER_GUARD',
            ],
            'provision' => [
                'olt_id' => $olt->id,
                'sn' => 'FHTTGUARD002',
                'port' => '1/3/1',
                'onu_id' => 1,
                'name' => 'CUSTOMER_GUARD',
            ],
            'reprovision' => [
                'olt_id' => $olt->id,
                'sn' => 'FHTTGUARD003',
                'port' => '1/3/1',
                'onu_id' => 1,
                'name' => 'CUSTOMER_GUARD',
            ],
            'reconfigure' => [
                'olt_id' => $olt->id,
                'sn' => 'FHTTGUARD004',
                'port' => '1/3/1',
                'onu_id' => 1,
                'name' => 'CUSTOMER_GUARD',
            ],
        ];

        foreach ($payloads as $method => $payload) {
            try {
                app(OltC300Controller::class)->{$method}(
                    Request::create("/api/olt-c300/{$method}", 'POST', $payload),
                );

                $this->fail("{$method} accepted a missing PPPoE password");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('password', $exception->errors());
                // Locale-independent (X1 switched messages to Indonesian): check the failed rule.
                $this->assertArrayHasKey('Required', $exception->validator->failed()['password']);
            }
        }

        $this->assertDatabaseCount('olts', 1);
    }
}
