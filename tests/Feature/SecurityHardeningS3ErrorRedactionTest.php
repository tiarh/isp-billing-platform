<?php

namespace Tests\Feature;

use App\Http\Controllers\RouterController;
use App\Models\CustomerMigration;
use App\Models\User;
use App\Services\WireGuardProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

/**
 * Salvaged from closed PR #150 (62d60de5 "redact router operation errors",
 * a53f1a7d "redact migration error projections"): raw exception text and
 * stored device errors must not reach the browser, CSV exports or flash
 * messages with credential-shaped tokens intact.
 */
#[Group('blocking-core')]
class SecurityHardeningS3ErrorRedactionTest extends TestCase
{
    use RefreshDatabase;

    private const LEAKY_ERROR = 'login failed password=hunter2-secret token=s3cr3t-tk';

    public function test_wireguard_endpoint_errors_never_echo_exception_text(): void
    {
        $wireGuard = Mockery::mock(WireGuardProvisioningService::class);
        $wireGuard->shouldReceive('defaults')
            ->andThrow(new RuntimeException('wg set wg0 peer X preshared-key /tmp/wg-psk-abc password=hunter2-secret'));

        $response = app(RouterController::class)->wgServerConfig(Request::create('/'), $wireGuard);

        $this->assertSame(500, $response->getStatusCode());
        $body = (string) $response->getContent();
        $this->assertStringContainsString('WireGuard server configuration unavailable.', $body);
        $this->assertStringNotContainsString('hunter2', $body);
        $this->assertStringNotContainsString('wg-psk', $body);
    }

    public function test_router_controller_never_returns_unredacted_exception_text(): void
    {
        $lines = file(app_path('Http/Controllers/RouterController.php')) ?: [];

        foreach ($lines as $number => $line) {
            if (! str_contains($line, 'getMessage()')) {
                continue;
            }
            $userFacing = str_contains($line, '->with(')
                || str_contains($line, 'response()->json(')
                || str_contains($line, "'message' =>")
                || str_contains($line, "'error' =>")
                || preg_match("/^\\s*[?:]\\s*'/", $line) === 1;

            if ($userFacing) {
                $this->assertStringContainsString(
                    'SensitiveDataRedactor::redact($e->getMessage())',
                    $line,
                    'RouterController line '.($number + 1).' returns raw exception text',
                );
            }
        }
    }

    public function test_migration_index_redacts_last_error(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        CustomerMigration::create([
            'status' => CustomerMigration::STATUS_FAILED,
            'full_name' => 'REDACT CHECK',
            'last_error' => self::LEAKY_ERROR,
        ]);

        $this->actingAs($admin)
            ->get(route('customer-migrations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('CustomerMigrations/Index')
                ->where('migrations.0.full_name', 'REDACT CHECK')
                ->where('migrations.0.last_error', function ($value): bool {
                    $value = (string) $value;

                    return str_contains($value, 'login failed')
                        && str_contains($value, '[REDACTED]')
                        && ! str_contains($value, 'hunter2')
                        && ! str_contains($value, 's3cr3t-tk');
                })
            );
    }

    public function test_migration_csv_export_redacts_last_error(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        CustomerMigration::create([
            'status' => CustomerMigration::STATUS_FAILED,
            'full_name' => 'REDACT CSV',
            'last_error' => self::LEAKY_ERROR,
        ]);

        $csv = $this->actingAs($admin)
            ->get(route('customer-migrations.export', ['status' => 'all']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('REDACT CSV', $csv);
        $this->assertStringContainsString('[REDACTED]', $csv);
        $this->assertStringNotContainsString('hunter2', $csv);
        $this->assertStringNotContainsString('s3cr3t-tk', $csv);
    }
}
