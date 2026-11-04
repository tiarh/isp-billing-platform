<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\Package;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Audit 2026-10-08 (Gadang): remap customers to the OLT that holds their ONU
 * serial. Dry-run by default; only --apply writes.
 */
#[Group('blocking-core')]
class RemapCustomersOltBySerialTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    public function test_dry_run_is_the_default_and_writes_nothing(): void
    {
        [$huawei, $hioso] = $this->fixture();
        $before = Customer::query()->orderBy('id')->get(['id', 'olt_id', 'olt_port_label'])->toArray();

        $this->artisan('customers:remap-olt-by-serial', ['--olt_id' => $huawei->id])
            ->expectsOutputToContain('remap=2')
            ->expectsOutputToContain('no rows written')
            ->assertExitCode(0);

        $this->assertSame($before, Customer::query()->orderBy('id')->get(['id', 'olt_id', 'olt_port_label'])->toArray());

        $this->artisan('customers:remap-olt-by-serial', ['--olt_id' => $huawei->id, '--apply' => true, '--dry-run' => true])
            ->assertExitCode(0);
        $this->assertSame($before, Customer::query()->orderBy('id')->get(['id', 'olt_id', 'olt_port_label'])->toArray());
    }

    public function test_apply_remaps_only_unambiguous_serials(): void
    {
        [$huawei, $hioso, $customers] = $this->fixture();

        $this->artisan('customers:remap-olt-by-serial', ['--olt_id' => $huawei->id, '--apply' => true])
            ->expectsOutputToContain('Applied: 2')
            ->assertExitCode(0);

        $this->assertSame($huawei->id, (int) $customers['unmapped']->fresh()->olt_id);
        $this->assertSame('0/0/7:1', $customers['unmapped']->fresh()->olt_port_label);
        $this->assertSame($huawei->id, (int) $customers['old_hioso']->fresh()->olt_id);
        $this->assertSame('0/0/7:2', $customers['old_hioso']->fresh()->olt_port_label);
        // Same serial on two customers: never guessed.
        $this->assertSame($hioso->id, (int) $customers['dup_a']->fresh()->olt_id);
        $this->assertNull($customers['dup_b']->fresh()->olt_id);
        // Serial not on the target OLT: untouched.
        $this->assertSame($hioso->id, (int) $customers['elsewhere']->fresh()->olt_id);
    }

    public function test_production_apply_requires_confirmation_token(): void
    {
        [$huawei] = $this->fixture();
        $this->app['env'] = 'production';

        $this->artisan('customers:remap-olt-by-serial', ['--olt_id' => $huawei->id, '--apply' => true])
            ->assertExitCode(1);
        $this->assertSame(0, Customer::query()->where('olt_id', $huawei->id)->count());
    }

    /** @return array{0: Olt, 1: Olt, 2: array<string, Customer>} */
    private function fixture(): array
    {
        $huawei = Olt::create(['name' => 'OLT GADANG 3 (test)', 'code' => 'GADANG-3-T', 'vendor' => 'huawei_ma5608t']);
        $hioso = Olt::create(['name' => 'OLT GADANG (test)', 'code' => 'GADANG-T', 'vendor' => 'hioso']);
        foreach ([1 => 'HWTC0000AA01', 2 => 'FHTT0000AA02', 3 => 'HWTC0000AA03'] as $id => $serial) {
            OltOnu::create(['olt_id' => $huawei->id, 'onu_ref' => '0/0/7:'.$id, 'pon_port' => '0/0/7', 'serial_number' => $serial]);
        }

        $customers = [
            'unmapped' => $this->customer(['olt_id' => null, 'onu_serial' => 'hwtc-0000aa01']),
            'old_hioso' => $this->customer(['olt_id' => $hioso->id, 'olt_port_label' => '0/1/1:5', 'onu_serial' => 'FHTT0000AA02']),
            'dup_a' => $this->customer(['olt_id' => $hioso->id, 'onu_serial' => 'HWTC0000AA03']),
            'dup_b' => $this->customer(['olt_id' => null, 'onu_serial' => 'HWTC0000AA03']),
            'elsewhere' => $this->customer(['olt_id' => $hioso->id, 'onu_serial' => 'ZTEG00000099']),
        ];

        return [$huawei, $hioso, $customers];
    }

    /** @param array<string, mixed> $attributes */
    private function customer(array $attributes): Customer
    {
        $this->seq++;
        $package = Package::firstOrCreate(['code' => 'REMAP-PKG'], ['name' => 'Remap', 'price' => 0]);

        return Customer::create(array_merge([
            'code' => 'REMAP-'.$this->seq,
            'name' => 'Remap '.$this->seq,
            'phone' => '0814000'.$this->seq,
            'address' => 'Gadang',
            'package_id' => $package->id,
            'status' => 'active',
            'pppoe_user' => 'remap-'.$this->seq,
        ], $attributes));
    }
}
