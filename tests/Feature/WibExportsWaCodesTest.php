<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\DeviceRecoveryCase;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Setting;
use App\Models\SupportTicket;
use App\Models\Transaction;
use App\Models\User;
use App\Services\EvolutionApiService;
use App\Support\OpsTime;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use ZipArchive;

/**
 * WIB PR-3: exports, non-PSB ticket WA and TCK-/RPT-/INV- code dates must use
 * WIB (Asia/Jakarta) while storage stays UTC. 2026-11-01 03:00 WIB is
 * 2026-10-31 20:00 UTC, so the UTC and WIB dates/months differ.
 */
#[Group('blocking-billing')]
class WibExportsWaCodesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function wib(string $dateTime): CarbonImmutable
    {
        return CarbonImmutable::parse($dateTime, 'Asia/Jakarta');
    }

    private function sheetXml($response): string
    {
        $path = $response->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertSame(true, $zip->open($path));
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($path);

        return $sheet;
    }

    private function customer(string $code = 'WIB-CUST'): Customer
    {
        $package = Package::create([
            'code' => 'WIB-PKG-'.$code,
            'name' => 'Wib Pkg',
            'price' => 100000,
            'mikrotik_profile' => 'WIB',
        ]);

        return Customer::create([
            'code' => $code,
            'name' => 'Wib Customer '.$code,
            'phone' => '080000000099',
            'address' => 'Alamat',
            'pppoe_user' => 'wib.'.strtolower($code),
            'package_id' => $package->id,
            'status' => 'active',
        ]);
    }

    public function test_invoice_export_prints_paid_at_in_wib(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->customer();
        $invoice = Invoice::create([
            'customer_id' => $customer->id,
            'period' => '2026-10-01',
            'amount' => 100000,
            'status' => 'paid',
            'due_date' => '2026-10-20',
            'generated_at' => now(),
        ]);
        Transaction::create([
            'reference' => 'WIB-EXP-1',
            'invoice_id' => $invoice->id,
            'amount' => 100000,
            'channel' => 'manual',
            'method' => 'cash',
            'status' => 'verified',
            'paid_at' => CarbonImmutable::parse('2026-10-31 20:00:00', 'UTC'),
        ]);

        $sheet = $this->sheetXml($this->actingAs($admin)->get(route('invoices.export')));

        $this->assertStringContainsString('2026-11-01 03:00:00', $sheet);
        $this->assertStringNotContainsString('2026-10-31 20:00:00', $sheet);
    }

    public function test_customer_export_prints_mikrotik_sync_times_in_wib(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->customer();
        DB::table('customers')->where('id', $customer->id)->update([
            'mikrotik_synced_at' => '2026-10-31 20:00:00',
            'mikrotik_sync_checked_at' => '2026-10-31 20:05:00',
        ]);

        $sheet = $this->sheetXml($this->actingAs($admin)->get(route('customers.export')));

        $this->assertStringContainsString('2026-11-01 03:00:00', $sheet);
        $this->assertStringContainsString('2026-11-01 03:05:00', $sheet);
        $this->assertStringNotContainsString('2026-10-31 20:00:00', $sheet);
    }

    public function test_inventaris_recovery_csv_prints_updated_at_in_wib(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->customer();
        $area = Area::create(['code' => 'WIB-A', 'name' => 'Area Wib']);
        $ticket = SupportTicket::create([
            'code' => 'TCK-WIB-CSV',
            'customer_id' => $customer->id,
            'subject' => 'Dismantle',
            'status' => 'open',
            'type' => 'dismantle',
            'priority' => 'normal',
        ]);
        $case = DeviceRecoveryCase::create([
            'ticket_id' => $ticket->id,
            'customer_id' => $customer->id,
            'area_id' => $area->id,
            'status' => 'waiting_assignment',
            'created_by' => $admin->id,
            'version' => 0,
        ]);
        DB::table('device_recovery_cases')->where('id', $case->id)->update(['updated_at' => '2026-10-31 20:00:00']);

        $csv = $this->actingAs($admin)->get(route('inventaris.export', ['type' => 'recovery']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('2026-11-01 03:00:00', $csv);
        $this->assertStringNotContainsString('2026-10-31', $csv);
    }

    public function test_ticket_and_invoice_codes_use_the_wib_date(): void
    {
        // 2026-11-01 03:00 WIB = 2026-10-31 20:00 UTC
        $this->travelTo($this->wib('2026-11-01 03:00')->utc());
        $customer = $this->customer();

        $ticket = SupportTicket::create(['subject' => 'Kode', 'status' => 'open', 'priority' => 'normal']);
        $invoice = Invoice::create([
            'customer_id' => $customer->id,
            'period' => '2026-11-01',
            'amount' => 100000,
            'status' => 'unpaid',
            'due_date' => '2026-11-20',
            'generated_at' => now(),
        ]);

        $this->assertStringStartsWith('TCK-20261101-', $ticket->code);
        $this->assertStringStartsWith('RPT-20261101-', $ticket->report_id);
        $this->assertStringStartsWith('INV-202611-', $invoice->code);
        // Stored timestamps stay UTC.
        $this->assertSame('2026-10-31 20:00:00', DB::table('support_tickets')->where('id', $ticket->id)->value('created_at'));
    }

    public function test_api_ticket_due_at_without_offset_is_parsed_as_wib(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->postJson('/api/support-tickets', [
            'subject' => 'Due WIB',
            'due_at' => '2026-11-01 10:00:00',
        ])->assertStatus(201);
        $row = DB::table('support_tickets')->where('subject', 'Due WIB')->first();
        $this->assertSame('2026-11-01 03:00:00', $row->due_at);

        $this->actingAs($admin)->patchJson('/api/support-tickets/'.$row->id, [
            'due_at' => '2026-11-02 08:00:00',
        ])->assertOk();
        $this->assertSame('2026-11-02 01:00:00', DB::table('support_tickets')->where('id', $row->id)->value('due_at'));
    }

    public function test_ticket_wa_messages_print_wib_times(): void
    {
        config([
            'services.evolution_api.api_key' => 'test-key',
            'services.evolution_api.base_url' => 'http://evo.test',
        ]);
        Http::fake(['*' => Http::response(['key' => ['id' => 'x']], 200)]);
        Setting::set('wa_ticket_group_jid', '120363000000000001@g.us');
        Setting::set('wa_report_group_jid', '120363000000000002@g.us');

        $ticket = SupportTicket::create(['subject' => 'WA', 'status' => 'closed', 'priority' => 'normal', 'gangguan' => 'LOS']);
        DB::table('support_tickets')->where('id', $ticket->id)->update([
            'created_at' => '2026-10-31 20:00:00',
            'resolved_at' => '2026-10-31 21:30:00',
        ]);
        $ticket = $ticket->fresh();

        $service = new EvolutionApiService;
        $service->sendTicketClosedToGroup($ticket);
        $service->sendTicketClosedReportToGroup($ticket);
        $service->sendTicketCreatedToGroup($ticket);

        $texts = Http::recorded()->map(fn ($pair) => (string) ($pair[0]->data()['text'] ?? ''))->filter()->values();
        $this->assertCount(3, $texts);
        foreach ($texts as $text) {
            $this->assertStringContainsString('01/11/2026 03:00', $text);
            $this->assertStringNotContainsString('31/10/2026', $text);
        }
        $this->assertStringContainsString('01/11/2026 04:30', $texts[0]);
    }

    public function test_ticket_report_command_uses_wib_day_and_clock(): void
    {
        $sent = [];
        $fake = \Mockery::mock(EvolutionApiService::class);
        $fake->shouldReceive('forChannel')->andReturnSelf();
        $fake->shouldReceive('isConfigured')->andReturn(true);
        $fake->shouldReceive('sendToGroup')->andReturnUsing(function (string $jid, string $text) use (&$sent) {
            $sent[] = $text;

            return ['success' => true];
        });
        $this->app->instance(EvolutionApiService::class, $fake);

        // 2026-11-01 06:00 WIB = 2026-10-31 23:00 UTC
        $this->travelTo($this->wib('2026-11-01 06:00')->utc());

        $yesterdayWib = SupportTicket::create(['subject' => 'Y', 'status' => 'open', 'priority' => 'normal']);
        $todayWib = SupportTicket::create(['subject' => 'T', 'status' => 'closed', 'priority' => 'normal', 'gangguan' => 'LOS']);
        // 01:00 UTC Oct 31 = 08:00 WIB Oct 31 (yesterday in WIB, same UTC day as "now").
        DB::table('support_tickets')->where('id', $yesterdayWib->id)->update(['created_at' => '2026-10-31 01:00:00']);
        // 22:30 UTC Oct 31 = 05:30 WIB Nov 1.
        DB::table('support_tickets')->where('id', $todayWib->id)->update([
            'created_at' => '2026-10-31 22:30:00',
            'updated_at' => '2026-10-31 22:30:00',
        ]);

        $this->artisan('ticket:report-wa', ['--group' => '120363000000000009@g.us'])->assertExitCode(0);

        $this->assertCount(1, $sent);
        $this->assertStringContainsString('01/11/2026 Jam 06:00 WIB', $sent[0]);
        $this->assertMatchesRegularExpression('/Tiket Baru: \*1\*/', $sent[0]);
        $this->assertStringContainsString('(05:30)', $sent[0]);
        $this->assertSame('2026-11-01', OpsTime::today());
    }
}
