<?php

namespace Tests\Feature;

use App\Logging\RedactSensitiveData;
use Tests\TestCase;

class LoggingChannelRedactionContractTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function externallyVisibleChannels(): array
    {
        return ['stack', 'single', 'daily', 'slack', 'papertrail', 'stderr', 'syslog', 'errorlog'];
    }

    public function test_every_externally_visible_logging_channel_uses_sensitive_data_redaction(): void
    {
        foreach ($this->externallyVisibleChannels() as $channel) {
            $tap = config("logging.channels.{$channel}.tap", []);

            $this->assertContains(
                RedactSensitiveData::class,
                $tap,
                "Logging channel [{$channel}] must redact message and context before delivery."
            );
        }
    }
}
