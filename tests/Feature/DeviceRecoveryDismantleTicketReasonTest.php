<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\DeviceRecoveryCase;
use App\Models\Package;
use App\Models\SupportTicket;
use App\Models\TicketActivity;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\DismantleReason;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Dismantle tickets carry a reason (dropdown + note): validated, stored,
 * shown on the ticket and in Pengambilan Perangkat, logged, backfilled and
 * reported per month.
 */
#[Group('blocking-core')]
class DeviceRecoveryDismantleTicketReasonTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        config(['device-recovery.mode' => 'write']);

        $notifier = Mockery::mock(NotificationService::class);
        $notifier->shouldIgnoreMissing(true);
        app()->instance(NotificationService::class, $notifier);

        $this->admin = User::factory()->create(['role' => 'admin']);
        $area = Area::create(['name' => 'Reason Area', 'code' => 'RSN']);
        $package = Package::create(['code' => 'RSN-PKG', 'name' => 'Reason package', 'price' => 100000]);
        $this->customer = Customer::create([
            'code' => 'RSN-CUSTOMER',
            'name' => 'Reason customer',
            'phone' => '08123456780',
            'address' => 'Reason address',
            'area_id' => $area->id,
            'package_id' => $package->id,
            'pppoe_user' => 'rsn-customer',
            'onu_serial' => 'fhtt-c186-2f52',
            'status' => 'active',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function storePayload(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $this->customer->id,
            'customer_name' => '',
            'customer_phone' => '',
            'customer_coords' => '',
            'gangguan' => 'Dismantle pelanggan',
            'type' => 'dismantle',
            'priority' => 'normal',
        ], $overrides);
    }

    /** @param array<string, mixed> $attributes */
    private function makeTicket(array $attributes = []): SupportTicket
    {
        return SupportTicket::create(array_merge([
            'customer_id' => $this->customer->id,
            'created_by' => $this->admin->id,
            'gangguan' => 'Dismantle',
            'subject' => 'Dismantle',
            'status' => 'open',
            'priority' => 'normal',
            'type' => 'dismantle',
        ], $attributes));
    }

    // ---- validation -------------------------------------------------------

    public function test_reason_is_required_for_dismantle_ticket(): void
    {
        $this->actingAs($this->admin)->post(route('tickets.store'), $this->storePayload())
            ->assertSessionHasErrors('dismantle_reason');

        $this->assertDatabaseCount('support_tickets', 0);
    }

    public function test_unknown_reason_is_rejected(): void
    {
        $this->actingAs($this->admin)->post(route('tickets.store'), $this->storePayload(['dismantle_reason' => 'bosan']))
            ->assertSessionHasErrors('dismantle_reason');

        $this->assertDatabaseCount('support_tickets', 0);
    }

    public function test_other_reason_requires_note(): void
    {
        $this->actingAs($this->admin)->post(route('tickets.store'), $this->storePayload([
            'dismantle_reason' => 'other',
            'dismantle_reason_note' => '',
        ]))->assertSessionHasErrors('dismantle_reason_note');

        $this->actingAs($this->admin)->post(route('tickets.store'), $this->storePayload([
            'dismantle_reason' => 'other',
            'dismantle_reason_note' => str_repeat('x', DismantleReason::NOTE_MAX + 1),
        ]))->assertSessionHasErrors('dismantle_reason_note');

        $this->assertDatabaseCount('support_tickets', 0);
    }

    public function test_non_dismantle_ticket_is_unaffected_and_stores_no_reason(): void
    {
        $this->actingAs($this->admin)->post(route('tickets.store'), $this->storePayload([
            'gangguan' => 'Internet mati',
            'type' => 'incident',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->post(route('tickets.store'), $this->storePayload([
            'gangguan' => 'Internet lambat',
            'type' => 'incident',
            'dismantle_reason' => 'arrears',
            'dismantle_reason_note' => 'ignored',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2, SupportTicket::query()->whereNull('dismantle_reason')->whereNull('dismantle_reason_note')->count());
        $this->assertSame(0, TicketActivity::query()->where('action', 'dismantle_reason_set')->count());
    }

    public function test_update_without_type_does_not_require_reason(): void
    {
        $ticket = $this->makeTicket();

        // Status-only updates (e.g. technician workflow) keep working for
        // legacy dismantle tickets that have no reason yet.
        $this->actingAs($this->admin)->put(route('tickets.update', $ticket), ['status' => 'in_progress'])
            ->assertSessionHasNoErrors();
        $this->assertSame('in_progress', $ticket->fresh()->status);

        // The full edit form (type = dismantle) requires it.
        $this->actingAs($this->admin)->put(route('tickets.update', $ticket), ['type' => 'dismantle'])
            ->assertSessionHasErrors('dismantle_reason');
    }

    // ---- persistence + activity -------------------------------------------

    public function test_store_persists_reason_and_logs_ticket_and_customer_activity(): void
    {
        $this->actingAs($this->admin)->post(route('tickets.store'), $this->storePayload([
            'dismantle_reason' => 'other',
            'dismantle_reason_note' => '  Rumah dijual  ',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $ticket = SupportTicket::query()->firstOrFail();
        $this->assertSame('other', $ticket->dismantle_reason);
        $this->assertSame('Rumah dijual', $ticket->dismantle_reason_note);

        $activity = TicketActivity::query()->where('ticket_id', $ticket->id)->where('action', 'dismantle_reason_set')->firstOrFail();
        $this->assertSame('other', $activity->new_value['dismantle_reason']);
        $this->assertStringContainsString('Lainnya', $activity->description);

        $this->assertDatabaseHas('activity_log', [
            'description' => 'ticket_dismantle_reason_set',
            'subject_type' => Customer::class,
            'subject_id' => $this->customer->id,
            'causer_id' => $this->admin->id,
        ]);
    }

    public function test_update_changes_reason_and_logs_change_once(): void
    {
        $ticket = $this->makeTicket(['dismantle_reason' => 'moving_house']);

        $this->actingAs($this->admin)->put(route('tickets.update', $ticket), [
            'type' => 'dismantle',
            'dismantle_reason' => 'switch_provider',
            'dismantle_reason_note' => 'Ke provider X',
        ])->assertSessionHasNoErrors();

        $ticket->refresh();
        $this->assertSame('switch_provider', $ticket->dismantle_reason);
        $this->assertSame('Ke provider X', $ticket->dismantle_reason_note);
        $changed = TicketActivity::query()->where('ticket_id', $ticket->id)->where('action', 'dismantle_reason_changed')->get();
        $this->assertCount(1, $changed);
        $this->assertSame('moving_house', $changed->first()->old_value['dismantle_reason']);
        $this->assertDatabaseHas('activity_log', ['description' => 'ticket_dismantle_reason_changed', 'subject_id' => $this->customer->id]);

        // Same values again: no new activity.
        $this->actingAs($this->admin)->put(route('tickets.update', $ticket), [
            'type' => 'dismantle',
            'dismantle_reason' => 'switch_provider',
            'dismantle_reason_note' => 'Ke provider X',
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, TicketActivity::query()->where('ticket_id', $ticket->id)->whereIn('action', ['dismantle_reason_set', 'dismantle_reason_changed'])->count());
    }

    // ---- display ----------------------------------------------------------

    public function test_ticket_show_and_forms_expose_reason(): void
    {
        $ticket = $this->makeTicket(['dismantle_reason' => 'duplicate_device', 'dismantle_reason_note' => 'ONU kedua']);

        $this->actingAs($this->admin)->get(route('tickets.show', $ticket))
            ->assertInertia(fn ($page) => $page
                ->where('dismantleReason.code', 'duplicate_device')
                ->where('dismantleReason.label', 'Perangkat ganda (salah satu dicabut)')
                ->where('dismantleReason.note', 'ONU kedua'));

        $this->actingAs($this->admin)->get(route('tickets.create'))
            ->assertInertia(fn ($page) => $page
                ->has('dismantleReasonOptions', count(DismantleReason::OPTIONS))
                ->where('dismantleReasonOptions.0.value', 'not_continuing'));

        $this->actingAs($this->admin)->get(route('tickets.edit', $ticket))
            ->assertInertia(fn ($page) => $page
                ->where('ticket.dismantle_reason', 'duplicate_device')
                ->has('dismantleReasonOptions', count(DismantleReason::OPTIONS)));
    }

    public function test_recovery_list_and_detail_show_reason_from_ticket(): void
    {
        $this->actingAs($this->admin)->post(route('tickets.store'), $this->storePayload([
            'dismantle_reason' => 'arrears',
            'dismantle_reason_note' => 'Nunggak 3 bulan',
        ]))->assertSessionHasNoErrors();
        $case = DeviceRecoveryCase::query()->firstOrFail();

        $this->actingAs($this->admin)->get(route('device-recovery.index'))
            ->assertInertia(fn ($page) => $page
                ->where('cases.data.0.ticket.dismantle_reason', 'arrears')
                ->where('cases.data.0.ticket.dismantle_reason_label', 'Tunggakan / tidak bayar'));

        $this->actingAs($this->admin)->getJson(route('device-recovery.show', $case))
            ->assertOk()
            ->assertJsonPath('ticket.dismantle_reason_label', 'Tunggakan / tidak bayar')
            ->assertJsonPath('ticket.dismantle_reason_note', 'Nunggak 3 bulan');
    }

    // ---- backfill ---------------------------------------------------------

    public function test_subject_mapping(): void
    {
        $cases = [
            'Dismantle tidak lanjut' => 'not_continuing',
            'dismantle TDK LANJUT langganan' => 'not_continuing',
            'Dismantle pindah rumah' => 'moving_house',
            'Dismantle pindah provider' => 'switch_provider',
            'Dismantle, pakai provider lain' => 'switch_provider',
            'Dismantle tunggakan 3 bln' => 'arrears',
            'Dismantle tidak bayar' => 'arrears',
            'Dismantle karena gangguan terus' => 'unresolved_issue',
            'Dismantle terpasang 2 ONU' => 'duplicate_device',
            'Dismantle salah satu perangkat' => 'duplicate_device',
        ];
        foreach ($cases as $subject => $expected) {
            $this->assertSame(['reason' => $expected, 'note' => null], DismantleReason::classifySubject($subject), $subject);
        }

        $this->assertSame(['reason' => 'other', 'note' => 'Dismantle request owner'], DismantleReason::classifySubject('Dismantle request owner'));
    }

    public function test_backfill_dry_run_writes_nothing_and_apply_is_idempotent(): void
    {
        $byType = $this->makeTicket(['subject' => 'Dismantle tidak lanjut', 'gangguan' => 'Dismantle tidak lanjut']);
        $bySubject = $this->makeTicket(['type' => 'other', 'subject' => 'DISMANTLE pindah rumah', 'gangguan' => 'DISMANTLE pindah rumah']);
        $unknown = $this->makeTicket(['subject' => 'Cabut perangkat', 'gangguan' => 'Cabut perangkat']);
        $alreadySet = $this->makeTicket(['subject' => 'Dismantle tunggakan', 'dismantle_reason' => 'moving_house']);
        $unrelated = $this->makeTicket(['type' => 'incident', 'subject' => 'Internet mati', 'gangguan' => 'Internet mati']);

        $this->artisan('tickets:backfill-dismantle-reason')
            ->expectsOutputToContain('DRY-RUN: 3 dismantle ticket(s)')
            ->assertSuccessful();
        $this->artisan('tickets:backfill-dismantle-reason', ['--apply' => true, '--dry-run' => true])
            ->expectsOutputToContain('DRY-RUN')
            ->assertSuccessful();

        $this->assertSame(4, SupportTicket::query()->whereNull('dismantle_reason')->count());
        $this->assertSame(0, TicketActivity::query()->where('action', 'dismantle_reason_set')->count());

        $this->artisan('tickets:backfill-dismantle-reason', ['--apply' => true])
            ->expectsOutputToContain('APPLIED: 3 of 3')
            ->assertSuccessful();

        $this->assertSame('not_continuing', $byType->fresh()->dismantle_reason);
        $this->assertSame('moving_house', $bySubject->fresh()->dismantle_reason);
        $this->assertSame('other', $unknown->fresh()->dismantle_reason);
        $this->assertSame('Cabut perangkat', $unknown->fresh()->dismantle_reason_note);
        $this->assertSame('moving_house', $alreadySet->fresh()->dismantle_reason);
        $this->assertNull($unrelated->fresh()->dismantle_reason);
        $this->assertSame(3, TicketActivity::query()->where('action', 'dismantle_reason_set')->count());

        // Re-run: nothing left to do.
        $this->artisan('tickets:backfill-dismantle-reason', ['--apply' => true])
            ->expectsOutputToContain('APPLIED: 0 of 0')
            ->assertSuccessful();
        $this->assertSame(3, TicketActivity::query()->where('action', 'dismantle_reason_set')->count());
    }

    // ---- report -----------------------------------------------------------

    public function test_report_counts_per_reason_per_month_admin_only(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15 05:00:00', 'UTC'));

        $this->makeTicket(['dismantle_reason' => 'arrears']);
        $this->makeTicket(['dismantle_reason' => 'arrears']);
        $this->makeTicket(['dismantle_reason' => 'moving_house']);
        $this->makeTicket(); // dismantle without reason yet
        $this->makeTicket(['type' => 'incident']); // not counted

        $september = $this->makeTicket(['dismantle_reason' => 'other', 'dismantle_reason_note' => 'x']);
        $september->forceFill(['created_at' => '2026-09-10 03:00:00'])->saveQuietly();
        $legacy = $this->makeTicket(['type' => 'other', 'dismantle_reason' => 'arrears']);
        $legacy->forceFill(['created_at' => '2026-09-20 03:00:00'])->saveQuietly();
        $old = $this->makeTicket(['dismantle_reason' => 'arrears']);
        $old->forceFill(['created_at' => '2025-01-01 03:00:00'])->saveQuietly(); // outside window

        $this->actingAs($this->admin)->get(route('tickets.dismantle-reasons'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Tickets/DismantleReasons')
                ->where('months', 12)
                ->where('report.total', 6)
                ->where('report.rows.0.month', '2026-10')
                ->where('report.rows.0.counts.arrears', 2)
                ->where('report.rows.0.counts.moving_house', 1)
                ->where('report.rows.0.counts.unset', 1)
                ->where('report.rows.0.total', 4)
                ->where('report.rows.1.month', '2026-09')
                ->where('report.rows.1.counts.other', 1)
                ->where('report.rows.1.counts.arrears', 1)
                ->where('report.totals.arrears', 3)
                ->has('report.rows', 2));

        $technician = User::factory()->create(['role' => 'teknisi']);
        $this->actingAs($technician)->get(route('tickets.dismantle-reasons'))->assertForbidden();
    }
}
