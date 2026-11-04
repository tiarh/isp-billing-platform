<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\DeviceRecoveryCase;
use App\Models\Package;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\DeviceRecoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Summary tiles on /device-recovery are server-side counts over every matching case,
 * independent of pagination and of the status filter.
 */
#[Group('blocking-core')]
class DeviceRecoveryIndexSummaryTest extends TestCase
{
    use RefreshDatabase;

    private Area $areaA;

    private Area $areaB;

    private Package $package;

    private User $admin;

    private User $techOne;

    private User $techTwo;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->areaA = Area::create(['name' => 'Summary A', 'code' => 'SMA']);
        $this->areaB = Area::create(['name' => 'Summary B', 'code' => 'SMB']);
        $this->package = Package::create(['code' => 'SUM-PKG', 'name' => 'Summary package', 'price' => 100000]);
        $this->admin = User::factory()->create(['role' => 'superadmin']);
        $this->techOne = User::factory()->create(['role' => 'teknisi']);
        $this->techTwo = User::factory()->create(['role' => 'teknisi']);
    }

    private function makeCase(string $status, Area $area, ?User $technician = null): DeviceRecoveryCase
    {
        $this->seq++;
        $customer = Customer::create([
            'code' => 'SUM-C'.$this->seq,
            'name' => 'Pelanggan Ringkasan '.$this->seq,
            'phone' => '0812000'.str_pad((string) $this->seq, 4, '0', STR_PAD_LEFT),
            'address' => 'Jl. Ringkasan',
            'area_id' => $area->id,
            'package_id' => $this->package->id,
            'pppoe_user' => 'sum-'.$this->seq,
            'status' => 'active',
        ]);
        $ticket = SupportTicket::create([
            'code' => 'TCK-SUM-'.$this->seq,
            'customer_id' => $customer->id,
            'subject' => 'Dismantle',
            'status' => 'open',
            'type' => 'dismantle',
            'priority' => 'normal',
        ]);

        return DeviceRecoveryCase::create([
            'ticket_id' => $ticket->id,
            'customer_id' => $customer->id,
            'area_id' => $area->id,
            'status' => $status,
            'assigned_to' => $technician?->id,
            'created_by' => $this->admin->id,
            'version' => 0,
        ]);
    }

    private function summaryFor(User $user, array $query = []): array
    {
        $response = $this->actingAs($user)->get(route('device-recovery.index', $query))->assertOk();

        return $response->viewData('page')['props']['summary'];
    }

    public function test_counts_cover_all_pages_not_just_the_current_page(): void
    {
        for ($i = 0; $i < 22; $i++) {
            $this->makeCase(DeviceRecoveryService::STATUS_CARRIED, $this->areaA, $this->techOne);
        }
        for ($i = 0; $i < 3; $i++) {
            $this->makeCase(DeviceRecoveryService::STATUS_EXCEPTION, $this->areaA, $this->techOne);
        }
        $this->makeCase(DeviceRecoveryService::STATUS_EXCEPTION_ACCEPTED, $this->areaA, $this->techOne);
        $this->makeCase(DeviceRecoveryService::STATUS_RECEIVED, $this->areaA, $this->techOne);
        $this->makeCase(DeviceRecoveryService::STATUS_WAITING_ASSIGNMENT, $this->areaA);

        $response = $this->actingAs($this->admin)->get(route('device-recovery.index'))->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertCount(20, $props['cases']['data']);
        $this->assertSame(['total' => 28, 'in_progress' => 22, 'needs_review' => 4], $props['summary']);
    }

    public function test_counts_ignore_the_status_filter_but_the_list_does_not(): void
    {
        $this->makeCase(DeviceRecoveryService::STATUS_CARRIED, $this->areaA, $this->techOne);
        $this->makeCase(DeviceRecoveryService::STATUS_ASSIGNED, $this->areaA, $this->techOne);
        $this->makeCase(DeviceRecoveryService::STATUS_EXCEPTION, $this->areaA, $this->techOne);

        $response = $this->actingAs($this->admin)->get(route('device-recovery.index', ['status' => 'carried']))->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertSame(1, $props['cases']['total']);
        $this->assertSame(['total' => 3, 'in_progress' => 2, 'needs_review' => 1], $props['summary']);
    }

    public function test_group_status_filter_matches_summary_counts_and_chips_are_one_per_label(): void
    {
        $this->makeCase(DeviceRecoveryService::STATUS_ASSIGNED, $this->areaA, $this->techOne);
        $this->makeCase(DeviceRecoveryService::STATUS_SCHEDULED, $this->areaA, $this->techOne);
        $this->makeCase(DeviceRecoveryService::STATUS_CARRIED, $this->areaA, $this->techOne);
        $this->makeCase(DeviceRecoveryService::STATUS_EXCEPTION, $this->areaA, $this->techOne);
        $this->makeCase(DeviceRecoveryService::STATUS_EXCEPTION_ACCEPTED, $this->areaA, $this->techOne);
        $this->makeCase(DeviceRecoveryService::STATUS_RECEIVED, $this->areaA, $this->techOne);

        $running = $this->actingAs($this->admin)->get(route('device-recovery.index', ['status' => 'in_progress']))->assertOk()->viewData('page')['props'];
        $review = $this->actingAs($this->admin)->get(route('device-recovery.index', ['status' => 'needs_review']))->assertOk()->viewData('page')['props'];

        $this->assertSame($running['summary']['in_progress'], $running['cases']['total']);
        $this->assertSame(3, $running['cases']['total']);
        $this->assertSame($review['summary']['needs_review'], $review['cases']['total']);
        $this->assertSame(2, $review['cases']['total']);

        $chips = $running['statusChips'];
        $labels = array_column($chips, 'label');
        $this->assertSame($labels, array_values(array_unique($labels)));
        $this->assertContains(['value' => 'in_progress', 'label' => 'Perangkat sedang diambil'], $chips);
        $this->assertContains(['value' => 'needs_review', 'label' => 'Perlu tinjauan'], $chips);
        $this->assertSame('in_progress', $running['filters']['status']);

        $legacy = $this->actingAs($this->admin)->get(route('device-recovery.index', ['status' => 'assigned']))->assertOk()->viewData('page')['props'];
        $this->assertSame(1, $legacy['cases']['total']);
        $this->assertSame('in_progress', $legacy['filters']['status_chip']);

        $this->actingAs($this->admin)->get(route('device-recovery.index', ['status' => 'bogus']))->assertSessionHasErrors('status');
    }

    public function test_counts_apply_area_technician_and_search_filters(): void
    {
        $this->makeCase(DeviceRecoveryService::STATUS_CARRIED, $this->areaA, $this->techOne);
        $this->makeCase(DeviceRecoveryService::STATUS_CARRIED, $this->areaB, $this->techOne);
        $this->makeCase(DeviceRecoveryService::STATUS_EXCEPTION, $this->areaA, $this->techTwo);

        $this->assertSame(
            ['total' => 2, 'in_progress' => 1, 'needs_review' => 1],
            $this->summaryFor($this->admin, ['area_id' => $this->areaA->id]),
        );
        $this->assertSame(
            ['total' => 2, 'in_progress' => 2, 'needs_review' => 0],
            $this->summaryFor($this->admin, ['assigned_to' => $this->techOne->id]),
        );
        $this->assertSame(
            ['total' => 1, 'in_progress' => 1, 'needs_review' => 0],
            $this->summaryFor($this->admin, ['search' => 'TCK-SUM-1']),
        );
    }

    public function test_technician_summary_counts_only_own_cases(): void
    {
        $this->makeCase(DeviceRecoveryService::STATUS_CARRIED, $this->areaA, $this->techOne);
        $this->makeCase(DeviceRecoveryService::STATUS_EXCEPTION, $this->areaA, $this->techOne);
        $this->makeCase(DeviceRecoveryService::STATUS_CARRIED, $this->areaA, $this->techTwo);
        $this->makeCase(DeviceRecoveryService::STATUS_CARRIED, $this->areaA);

        $this->assertSame(
            ['total' => 2, 'in_progress' => 1, 'needs_review' => 1],
            $this->summaryFor($this->techOne),
        );
    }

    public function test_scoped_admin_summary_counts_only_assigned_areas(): void
    {
        $scoped = User::factory()->create(['role' => 'admin']);
        $scoped->areas()->attach($this->areaA);

        $this->makeCase(DeviceRecoveryService::STATUS_CARRIED, $this->areaA, $this->techOne);
        $this->makeCase(DeviceRecoveryService::STATUS_EXCEPTION, $this->areaA, $this->techTwo);
        $this->makeCase(DeviceRecoveryService::STATUS_CARRIED, $this->areaB, $this->techOne);
        $this->makeCase(DeviceRecoveryService::STATUS_CARRIED, $this->areaB, $this->techTwo);

        $this->assertSame(
            ['total' => 2, 'in_progress' => 1, 'needs_review' => 1],
            $this->summaryFor($scoped),
        );
    }
}
