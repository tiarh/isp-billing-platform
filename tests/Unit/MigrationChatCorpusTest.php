<?php

namespace Tests\Unit;

use App\Services\CustomerMigrationMessageParser;
use PHPUnit\Framework\TestCase;

class MigrationChatCorpusTest extends TestCase
{
    /** @dataProvider sanitizedMigrationMessages */
    public function test_sanitized_technician_messages_keep_identity_fields_separate(
        string $name,
        string $message,
        array $expected,
    ): void {
        $parsed = (new CustomerMigrationMessageParser)->parse($message);

        self::assertTrue($parsed['is_migrasi'], $name);
        foreach ($expected as $field => $value) {
            self::assertSame($value, $parsed[$field] ?? null, $name.' '.$field);
        }
    }

    /** @return iterable<string, array{string, string, array<string, string|null>}> */
    public static function sanitizedMigrationMessages(): iterable
    {
        foreach (require __DIR__.'/../Fixtures/migration_chat_corpus.php' as $case) {
            yield $case['name'] => [$case['name'], $case['message'], $case['expected']];
        }
    }
}
