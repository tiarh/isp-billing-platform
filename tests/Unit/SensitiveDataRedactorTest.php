<?php

namespace Tests\Unit;

use App\Logging\RedactSensitiveDataProcessor;
use App\Services\PsbWaMessages;
use App\Support\SensitiveDataRedactor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Tests\Support\Canary;

class SensitiveDataRedactorTest extends TestCase
{
    public function test_keyed_credentials_are_redacted_from_text(): void
    {
        $rawPassword = Canary::of('pppoe-pw');
        $rawToken = 'token-value-123';
        $message = "pppoe_password={$rawPassword} Authorization: Bearer {$rawToken}";

        $redacted = SensitiveDataRedactor::redact($message);

        $this->assertStringNotContainsString($rawPassword, $redacted);
        $this->assertStringNotContainsString($rawToken, $redacted);
        $this->assertStringContainsString('[REDACTED]', $redacted);
    }

    public function test_sensitive_context_values_and_exception_messages_are_redacted(): void
    {
        $rawPassword = Canary::of('pppoe-pw');
        $context = SensitiveDataRedactor::redactContext([
            'password' => $rawPassword,
            'exception' => new \RuntimeException("password={$rawPassword}"),
            'customer_id' => 42,
        ]);

        $this->assertSame('[REDACTED]', $context['password']);
        $this->assertIsArray($context['exception']);
        $this->assertArrayNotHasKey('message', $context['exception']);
        $this->assertSame(42, $context['customer_id']);
    }

    public function test_psb_job_error_message_does_not_render_raw_exception_text(): void
    {
        $rawPassword = Canary::of('pppoe-pw');
        $message = app(PsbWaMessages::class)->jobError("Router failed: password={$rawPassword}");

        $this->assertStringNotContainsString($rawPassword, $message);
        $this->assertStringContainsString('[REDACTED]', $message);
    }

    public function test_log_processor_redacts_message_and_context_before_handler(): void
    {
        $rawPassword = Canary::of('pppoe-pw');
        $record = new LogRecord(
            new \DateTimeImmutable,
            'testing',
            Level::Error,
            "router password={$rawPassword}",
            ['password' => $rawPassword, 'customer_id' => 42],
        );

        $redacted = (new RedactSensitiveDataProcessor)($record);

        $this->assertStringNotContainsString($rawPassword, $redacted->message);
        $this->assertSame('[REDACTED]', $redacted->context['password']);
        $this->assertSame(42, $redacted->context['customer_id']);
    }
}
