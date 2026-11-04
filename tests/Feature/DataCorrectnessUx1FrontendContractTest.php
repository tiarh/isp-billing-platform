<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Attendance;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\PaymentReversal;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * PR-UX1 (Fase 2, koreksi data): L1, L3, C3, C4, T1, T7, P2, P3, X1.
 */
class DataCorrectnessUx1FrontendContractTest extends TestCase
{
    use RefreshDatabase;

    private function source(string $path): string
    {
        return (string) file_get_contents(base_path($path));
    }

    // ---- L1 + L3: Analytics -------------------------------------------------

    public function test_analytics_area_bars_are_side_by_side_not_stacked(): void
    {
        $source = $this->source('resources/js/Pages/Analytics/Index.tsx');

        $this->assertStringNotContainsString('stackId', $source, 'L1: billed + collected must never be stacked.');
        $this->assertStringContainsString('dataKey="total_billed"', $source);
        $this->assertStringContainsString('dataKey="total_collected"', $source);
    }

    public function test_analytics_charts_are_responsive_with_idr_tooltip_and_per_card_errors(): void
    {
        $source = $this->source('resources/js/Pages/Analytics/Index.tsx');

        $this->assertStringContainsString('<ResponsiveContainer', $source);
        $this->assertStringContainsString('<Tooltip', $source);
        $this->assertStringContainsString('formatIdr(value)', $source);
        $this->assertStringContainsString('Promise.allSettled', $source);
        $this->assertStringNotContainsString('Promise.all(', $source);
        $this->assertStringNotContainsString('width={760}', $source);
        $this->assertStringContainsString('emptyMessage=', $source);
        $this->assertStringContainsString('Data grafik ini gagal dimuat.', $source);
    }

    // ---- C3: payment history ------------------------------------------------

    public function test_customer_show_marks_reversed_and_pending_payments_and_limits_invoices(): void
    {
        $source = $this->source('resources/js/Pages/Customers/Show.tsx');

        $this->assertStringContainsString('paymentRowState(', $source);
        $this->assertStringContainsString("'Dibatalkan'", $source);
        $this->assertStringContainsString('line-through', $source);
        $this->assertStringContainsString('invoices_count', $source);
        $this->assertStringContainsString("route('invoices.index', { search: customer.code })", $source);
    }

    public function test_customer_show_payload_carries_status_reversals_and_last_24_invoices(): void
    {
        $user = User::factory()->create(['role' => 'superadmin']);
        $customer = $this->customer();

        $invoices = collect();
        for ($i = 0; $i < 26; $i++) {
            $invoices->push(Invoice::create([
                'uuid' => (string) Str::uuid(),
                'customer_id' => $customer->id,
                'code' => 'UX1-INV-'.$i,
                'period' => now()->startOfMonth()->subMonths($i)->toDateString(),
                'amount' => 100000,
                'due_date' => now()->startOfMonth()->subMonths($i)->addDays(10)->toDateString(),
                'status' => 'paid',
                'generated_at' => now(),
            ]));
        }

        $latest = $invoices->first();
        $transaction = Transaction::create([
            'invoice_id' => $latest->id,
            'reference' => 'UX1-TRX-1',
            'idempotency_key' => 'ux1-trx-1',
            'channel' => 'manual',
            'amount' => 100000,
            'status' => 'verified',
            'method' => 'cash',
            'paid_at' => now(),
        ]);
        PaymentReversal::create([
            'transaction_id' => $transaction->id,
            'invoice_id' => $latest->id,
            'amount' => 100000,
            'reversal_reference' => 'UX1-REV-1',
            'idempotency_key' => 'ux1-rev-1',
            'reason' => 'Salah input',
            'reversed_by' => $user->id,
            'reversed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('customers.show', $customer))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customers/Show')
                ->where('customer.invoices_count', 26)
                ->has('customer.invoices', 24)
                ->where('customer.invoices.0.id', $latest->id)
                ->where('customer.invoices.0.transactions.0.status', 'verified')
                ->where('customer.invoices.0.transactions.0.reversals.0.reason', 'Salah input')
            );
    }

    // ---- C4: Customers/Edit field errors ------------------------------------

    public function test_customer_edit_renders_every_server_field_error(): void
    {
        $source = $this->source('resources/js/Pages/Customers/Edit.tsx');

        foreach (['nik' => 'errors.nik', 'lat' => 'errors.geo_lat', 'long' => 'errors.geo_long', 'pppoe_user' => 'errors.pppoe_user'] as $id => $error) {
            $this->assertStringContainsString('<FieldError id="'.$id.'" message={'.$error.'} />', $source);
        }
        $this->assertStringContainsString('<FieldError id="pppoe_local_address"', $source);
        $this->assertStringContainsString('<FieldError id="pppoe_remote_address"', $source);
        $this->assertStringContainsString("'aria-invalid'", $source);
        $this->assertStringContainsString('aria-invalid:border-destructive', $this->source('resources/js/Components/ui/input.tsx'));
    }

    // ---- T1 + T7: Absensi ---------------------------------------------------

    public function test_attendance_page_uses_paged_datatable_with_mobile_card_and_gated_settings(): void
    {
        $source = $this->source('resources/js/Pages/Attendances/Index.tsx');

        $this->assertStringContainsString('<DataTable', $source);
        $this->assertStringContainsString('mobileCard=', $source);
        $this->assertStringContainsString('routeName="attendances.index"', $source);
        $this->assertStringContainsString('canManageSettings ? (', $source);
        $this->assertStringNotContainsString('attendances.data as Attendance[]', $source);
    }

    public function test_attendance_index_exposes_paging_and_settings_permission_per_role(): void
    {
        // Full-suite runs may share rows from non-refreshing tests: count relative to a baseline.
        $baseline = Attendance::count();
        for ($i = 0; $i < 55; $i++) {
            Attendance::create([
                'message_id' => 'ux1-msg-'.$i,
                'sender_number' => '62812000000'.$i,
                'sender_name' => 'Teknisi '.$i,
                'caption' => 'Hadir',
                'photo_url' => 'drive/absensi/'.$i.'.jpg',
            ]);
        }

        $this->actingAs(User::factory()->create(['role' => 'teknisi']))
            ->get(route('attendances.index', ['page' => 2]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendances/Index')
                ->where('canManageSettings', false)
                ->where('attendances.current_page', 2)
                ->where('attendances.total', $baseline + 55)
                ->has('attendances.data', min(50, $baseline + 55 - 50))
            );

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('attendances.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canManageSettings', true));
    }

    // ---- P2 + P3: isolation portal -------------------------------------------

    public function test_isolation_portal_distinguishes_three_non_billing_states_and_normalises_wa(): void
    {
        $source = $this->source('resources/js/Pages/Isolated/Index.tsx');

        $this->assertStringContainsString("'paid_recovering'", $source);
        $this->assertStringContainsString("'not_isolated'", $source);
        $this->assertStringContainsString("'unknown'", $source);
        $this->assertStringContainsString('Koneksi Anda normal', $source);
        $this->assertStringContainsString('wa_digits', $source);
        $this->assertStringContainsString('digits = `62${digits.slice(1)}`', $source);
        $this->assertStringNotContainsString("'https://wa.me/' + (status.wa_admin", $source);

        $controller = $this->source('app/Http/Controllers/IsolatedController.php');
        $this->assertStringNotContainsString('pulih 30 detik', $controller, 'P3: one recovery promise only (footer: 5 menit).');
    }

    // ---- X1: Indonesian validation --------------------------------------------

    public function test_validation_messages_are_indonesian_by_default(): void
    {
        $this->assertSame('id', config('app.locale'));
        $this->assertFileExists(base_path('lang/id/validation.php'));

        $validator = Validator::make(['name' => '', 'phone' => str_repeat('9', 30)], [
            'name' => ['required'],
            'phone' => ['string', 'max:20'],
        ]);

        $this->assertSame('Nama wajib diisi.', $validator->errors()->first('name'));
        $this->assertSame('Nomor telepon tidak boleh lebih dari 20 karakter.', $validator->errors()->first('phone'));
    }

    public function test_indonesian_validation_file_covers_every_framework_rule(): void
    {
        $id = require base_path('lang/id/validation.php');
        $en = require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');

        $missing = array_diff(array_keys($en), array_keys($id));

        $this->assertSame([], array_values($missing), 'lang/id/validation.php is missing framework keys.');
    }

    private function customer(): Customer
    {
        $area = Area::create(['code' => 'UX1-'.Str::upper(Str::random(4)), 'name' => 'UX1 Area']);
        $package = Package::create(['code' => 'UX1-'.Str::upper(Str::random(4)), 'name' => 'UX1 Paket', 'price' => 100000]);

        return Customer::create([
            'code' => 'UX1-'.Str::upper(Str::random(6)),
            'name' => 'UX1 Pelanggan',
            'address' => 'Jl. Test',
            'phone' => '081234567890',
            'pppoe_user' => 'ux1.'.Str::lower(Str::random(8)),
            'area_id' => $area->id,
            'package_id' => $package->id,
            'status' => 'active',
        ]);
    }
}
