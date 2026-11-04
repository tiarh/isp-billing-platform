<?php

namespace Tests\Unit;

use App\Enums\CustomerStatus;
use App\Jobs\ProcessWaCampaign;
use App\Services\CustomerOnu\CustomerOnuFieldSyncService;
use App\Services\DeviceRecoveryService;
use App\Services\NetworkEnforcementReconciler;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('blocking-core')]
class CustomerStatusEnumTest extends TestCase
{
    public function test_enum_values_match_db_enum_column(): void
    {
        $this->assertEqualsCanonicalizing(
            ['active', 'suspended', 'inactive', 'isolated', 'terminated', 'pending_installation'],
            array_map(fn (CustomerStatus $s) => $s->value, CustomerStatus::cases())
        );
    }

    public function test_phantom_values_are_not_cases(): void
    {
        foreach (['isolir', 'dismantled', 'offboarding', 'cuti'] as $phantom) {
            $this->assertNull(CustomerStatus::tryFrom($phantom), $phantom);
        }
    }

    public function test_indonesian_labels(): void
    {
        $this->assertSame('Menunggu Pasang', CustomerStatus::PendingInstallation->label());
        $this->assertSame('Aktif', CustomerStatus::Active->label());
        $this->assertSame('Isolir', CustomerStatus::Isolated->label());
        $this->assertSame('Cuti', CustomerStatus::Suspended->label());
        $this->assertSame('Berhenti', CustomerStatus::Terminated->label());
        $this->assertSame('Berhenti (lama)', CustomerStatus::Inactive->label());
        $this->assertSame('Aktif', CustomerStatus::labelFor('active'));
        $this->assertSame('whatever', CustomerStatus::labelFor('whatever'));
        $this->assertSame('', CustomerStatus::labelFor(null));
    }

    public function test_writable_and_ui_editable_sets(): void
    {
        $this->assertSame(['pending_installation', 'active', 'isolated', 'suspended', 'terminated'], CustomerStatus::writable());
        $this->assertNotContains('inactive', CustomerStatus::writable());
        // Same four values the customer form accepted before the enum.
        $this->assertSame(['pending_installation', 'active', 'isolated', 'terminated'], CustomerStatus::uiEditable());
    }

    public function test_only_inactive_is_legacy(): void
    {
        foreach (CustomerStatus::cases() as $case) {
            $this->assertSame($case === CustomerStatus::Inactive, $case->isLegacy());
        }
    }

    /**
     * Parity: each helper equals the old inline list minus values the DB enum can never hold.
     */
    public function test_helpers_equal_old_lists_minus_phantom_values(): void
    {
        // ['active','isolated','isolir'] (CheckOverdueInvoices, Customer::updated, ONU sync)
        $this->assertSame(['active', 'isolated'], CustomerStatus::billable());
        // ['isolated','isolir']
        $this->assertSame(['isolated'], CustomerStatus::isolatedValues());
        // ['terminated','dismantled']
        $this->assertSame(['terminated'], CustomerStatus::closed());
        // ['active','isolated','isolir','dismantled','terminated']
        $this->assertSame(['active', 'isolated', 'terminated'], CustomerStatus::monitored());
        // ['isolated','isolir','dismantled','terminated']
        $this->assertSame(['isolated', 'terminated'], CustomerStatus::isolatedOrClosed());
        // PSB: ['active','isolated','isolir','suspended']
        $this->assertSame(['active', 'isolated', 'suspended'], CustomerStatus::provisionedLive());
    }

    public function test_class_constants_pinned(): void
    {
        $this->assertSame(['active', 'isolated'], CustomerOnuFieldSyncService::ALLOWED_STATUSES);
        $this->assertSame(['terminated'], DeviceRecoveryService::RELEASED_CUSTOMER_STATUSES);
        $this->assertSame(['isolated'], NetworkEnforcementReconciler::ISOLATED_STATUSES);
        $this->assertSame(['terminated'], ProcessWaCampaign::EXCLUDED_CUSTOMER_STATUSES);
    }
}
