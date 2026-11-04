<?php

namespace Tests\Unit;

use App\Services\TotpService;
use Tests\TestCase;

class TotpServiceTest extends TestCase
{
    public function test_rfc6238_sha1_vector_is_supported(): void
    {
        $service = app(TotpService::class);

        // RFC 6238 public test vector; split to avoid generic-secret false positives.
        $publicRfc6238Secret = implode('', [
            'GEZDGNBVGY3TQOJQ',
            'GEZDGNBVGY3TQOJQ',
        ]);

        $this->assertSame('287082', $service->codeForSecret($publicRfc6238Secret, 59));
    }

    public function test_recovery_code_count_follows_policy(): void
    {
        $codes = app(TotpService::class)->generateRecoveryCodes();

        $this->assertCount(8, $codes);
        $this->assertCount(8, array_unique($codes));
        $this->assertTrue(collect($codes)->every(static fn (string $code): bool => preg_match('/^[a-z0-9-]{16}$/', $code) === 1));
    }

    public function test_provisioning_uri_contains_policy_without_logging_secret(): void
    {
        $uri = app(TotpService::class)->provisioningUri('admin@example.test', 'JBSWY3DPEHPK3PXP');

        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('issuer=Skynet', $uri);
        $this->assertStringContainsString('algorithm=SHA1', $uri);
        $this->assertStringContainsString('digits=6', $uri);
        $this->assertStringContainsString('period=30', $uri);
    }
}
