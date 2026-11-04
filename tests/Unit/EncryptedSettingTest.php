<?php

namespace Tests\Unit;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-billing')]
class EncryptedSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_cache_never_holds_the_plaintext_secret(): void
    {
        Setting::setSecret('pg_test_api_key', 'fake-api-key-value', 'payment');

        $this->assertSame('fake-api-key-value', Setting::secret('pg_test_api_key'));

        $cached = (string) Cache::get('setting.pg_test_api_key');
        $this->assertStringStartsWith(Setting::ENCRYPTED_PREFIX, $cached);
        $this->assertStringNotContainsString('fake-api-key-value', $cached);
    }

    public function test_get_returns_ciphertext_not_plaintext(): void
    {
        Setting::setSecret('pg_test_api_key', 'fake-api-key-value', 'payment');

        $this->assertStringNotContainsString('fake-api-key-value', (string) Setting::get('pg_test_api_key'));
    }
}
