<?php

namespace Tests\Unit;

use App\Exceptions\Olt\OltProvisioningBlockedException;
use App\Models\Olt;
use App\Services\Olt\OltProvisioningGuard;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-olt-mysql')]
class OltProvisioningDryRunGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cache.default', 'array');
        Config::set('app.key', 'phase-e-test-key');
        Config::set('olt-provisioning.dry_run', true);
        Config::set('olt-provisioning.kill_switch', false);
        Config::set('olt-provisioning.approval_window_seconds', 900);
        Config::set('olt-provisioning.approval_lock_seconds', 900);
        Cache::store('array')->flush();
    }

    public function test_dry_run_returns_plan_and_logs_without_socket_dependency(): void
    {
        Log::spy();

        $result = $this->guard()->provision(new Olt(['id' => 7, 'vendor' => 'zte']), [
            'commands' => ['show version'],
        ]);

        $this->assertTrue($result['dry_run']);
        $this->assertSame(['show version'], $result['commands']);
        Log::shouldHaveReceived('notice')->once();
    }

    public function test_valid_live_approval_returns_plan(): void
    {
        Config::set('olt-provisioning.dry_run', false);
        $request = $this->approvedRequest();

        $result = $this->guard()->provision(new Olt(['id' => 8]), $request + ['commands' => ['show onu']]);

        $this->assertFalse($result['dry_run']);
        $this->assertSame(['show onu'], $result['commands']);
    }

    public function test_internal_approval_is_server_signed_and_one_time(): void
    {
        Config::set('olt-provisioning.dry_run', false);
        $olt = new Olt;
        $olt->id = 9;
        $olt->vendor = 'zte-c300';
        $approval = $this->guard()->issueInternalApproval($olt, 'psb_wa', 'draft-77');

        $this->assertStringStartsWith('internal:psb_wa:olt-9:draft-77:', $approval['approval']);
        $result = $this->guard()->provision($olt, $approval + ['commands' => ['provision-fiberhome']]);

        $this->assertFalse($result['dry_run']);

        $this->expectException(OltProvisioningBlockedException::class);
        $this->guard()->provision($olt, $approval + ['commands' => ['provision-fiberhome']]);
    }

    public function test_stale_approval_is_blocked(): void
    {
        Config::set('olt-provisioning.dry_run', false);
        $request = $this->approvedRequest(now()->timestamp - 901);

        $this->expectException(OltProvisioningBlockedException::class);
        $this->guard()->provision(new Olt, $request + ['commands' => ['show onu']]);
    }

    public function test_future_approval_is_blocked(): void
    {
        Config::set('olt-provisioning.dry_run', false);
        $request = $this->approvedRequest(now()->timestamp + 300);

        $this->expectException(OltProvisioningBlockedException::class);
        $this->guard()->provision(new Olt, $request + ['commands' => ['show onu']]);
    }

    public function test_tampered_signature_is_blocked(): void
    {
        Config::set('olt-provisioning.dry_run', false);
        $request = $this->approvedRequest();
        $request['signature'] = str_repeat('0', 64);

        $this->expectException(OltProvisioningBlockedException::class);
        $this->guard()->provision(new Olt, $request + ['commands' => ['show onu']]);
    }

    public function test_missing_app_key_is_blocked(): void
    {
        Config::set('olt-provisioning.dry_run', false);
        Config::set('app.key', null);

        $this->expectException(OltProvisioningBlockedException::class);
        $this->guard()->provision(new Olt, $this->approvedRequest() + ['commands' => ['show onu']]);
    }

    public function test_kill_switch_blocks_even_dry_run(): void
    {
        Config::set('olt-provisioning.kill_switch', true);

        $this->expectException(OltProvisioningBlockedException::class);
        $this->guard()->provision(new Olt, ['commands' => ['show onu']]);
    }

    public function test_same_approval_cannot_be_used_twice(): void
    {
        Config::set('olt-provisioning.dry_run', false);
        $request = $this->approvedRequest();

        $this->guard()->provision(new Olt, $request + ['commands' => ['show onu']]);

        $this->expectException(OltProvisioningBlockedException::class);
        $this->guard()->provision(new Olt, $request + ['commands' => ['show onu']]);
    }

    public function test_invalid_command_plan_is_blocked_before_gate(): void
    {
        $this->expectException(OltProvisioningBlockedException::class);
        $this->guard()->provision(new Olt, ['commands' => ['']]);
    }

    private function guard(): OltProvisioningGuard
    {
        return app(OltProvisioningGuard::class);
    }

    private function approvedRequest(?int $timestamp = null): array
    {
        $approval = 'phase-e-approval-'.($timestamp ?? now()->timestamp);
        $timestamp ??= now()->timestamp;
        $payload = "OLT_PROVISIONING_APPROVED={$approval}\nOLT_PROVISIONING_APPROVED_AT={$timestamp}";

        return [
            'approval' => $approval,
            'approved_at' => $timestamp,
            'signature' => hash_hmac('sha256', $payload, (string) config('app.key')),
        ];
    }
}
