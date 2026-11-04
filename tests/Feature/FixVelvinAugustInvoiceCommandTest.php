<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-billing')]
class FixVelvinAugustInvoiceCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_then_execute_updates_only_target_and_is_idempotent(): void
    {
        [$invoice, $transaction] = $this->fixture();
        $auditDescription = "fixed invoice #{$invoice->id} 111000→75000";

        $this->artisan('velvin:fix-august', ['--dry-run' => true])
            ->expectsOutputToContain('DRY-RUN invoice #'.$invoice->id)
            ->assertSuccessful();

        $this->assertSame('111000.00', $invoice->fresh()->amount);
        $this->assertSame('111000.00', $transaction->fresh()->amount);
        $this->assertSame(0, DB::table('activity_log')->where('description', $auditDescription)->count());

        $this->artisan('velvin:fix-august', ['--execute' => true])
            ->expectsOutputToContain($auditDescription)
            ->expectsOutputToContain('settled: true')
            ->assertSuccessful();

        $this->assertSame('75000.00', $invoice->fresh()->amount);
        $this->assertSame('75000.00', $transaction->fresh()->amount);
        $this->assertTrue($invoice->fresh()->isSettled());
        $this->assertSame(1, DB::table('activity_log')->where('description', $auditDescription)->count());

        $this->artisan('velvin:fix-august', ['--execute' => true])
            ->expectsOutputToContain('tidak ada invoice 111000 di 2026-08')
            ->assertSuccessful();

        $this->assertSame('75000.00', $invoice->fresh()->amount);
        $this->assertSame('75000.00', $transaction->fresh()->amount);
        $this->assertSame(1, DB::table('activity_log')->where('description', $auditDescription)->count());
    }

    public function test_requires_an_explicit_single_mode_and_reports_missing_target(): void
    {
        $this->fixture();

        $this->artisan('velvin:fix-august')->assertExitCode(1);
        $this->artisan('velvin:fix-august', ['--dry-run' => true, '--execute' => true])->assertExitCode(1);

        Invoice::query()->update(['amount' => 75000]);

        $this->artisan('velvin:fix-august', ['--dry-run' => true])
            ->expectsOutputToContain('tidak ada invoice 111000 di 2026-08')
            ->assertSuccessful();
    }

    /**
     * @return array{0: Invoice, 1: Transaction}
     */
    private function fixture(): array
    {
        $package = Package::create([
            'code' => 'VELVIN-TEST',
            'name' => 'Velvin test package',
            'price' => 111000,
        ]);
        $customer = Customer::create([
            'code' => 'VELVIN-TEST',
            'name' => 'Velvin Test',
            'phone' => '081234567890',
            'address' => 'Test address',
            'package_id' => $package->id,
            'pppoe_user' => 'velvin.test',
            'status' => 'active',
        ]);
        DB::table('customers')->where('id', $customer->id)->update(['id' => 1937]);
        $customer = Customer::findOrFail(1937);

        $invoice = Invoice::create([
            'code' => 'INV-202608-RDG272-OF3Q',
            'customer_id' => $customer->id,
            'period' => '2026-08-01',
            'amount' => 111000,
            'due_date' => '2026-08-20',
            'status' => 'unpaid',
            'generated_at' => now(),
        ]);
        DB::table('invoices')->where('id', $invoice->id)->update(['id' => 2823305]);
        $invoice = Invoice::findOrFail(2823305);

        $transaction = Transaction::create([
            'invoice_id' => $invoice->id,
            'reference' => 'VELVIN-TEST-001',
            'amount' => 111000,
            'channel' => 'manual',
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        return [$invoice, $transaction];
    }
}
