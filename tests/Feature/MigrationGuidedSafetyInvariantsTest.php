<?php

namespace Tests\Feature;

use App\Jobs\ExecuteCustomerMigrationWaJob;
use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\DeviceActionLog;
use App\Models\Package;
use App\Models\PsbStarliteEntry;
use App\Services\CustomerMigrationService;
use App\Services\HuaweiOltOnlyProvisioningService;
use App\Support\OcrBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Fixtures\SyntheticOcrFixtures;
use Tests\Support\MigrationGolden\MigrationGoldenHarness;
use Tests\Support\MigrationGolden\MigrationGoldenWorld;
use Tests\TestCase;

/**
 * Safety invariants of the guided WA flow (migration refactor M4, PR A, tests only).
 *
 * Plain asserts, no golden: they must hold before and after the M4 move. Every case drives the real service
 * through public entries (the WA job, the admin web routes, public service methods) with the same fakes as the
 * migration goldens, and never reflects a method that moves in M4.
 *
 *  1. a sender outside {current selector, bound sender, static allowlist} can never change a draft with
 *     konfirmasi, batal, sn or eksekusi (meta, status, SN, ledgers and queued jobs stay the same);
 *  2. an SN fix that keeps the chosen customer in the list keeps the selection; one that does not drops the
 *     selection and cancels the dry-run ledger;
 *  3. a Huawei OLT-only draft never gets a candidate list and never causes a C300 list or provision call;
 *  4. an override of syncCustomerAfterNewOnu and photoBatchBudget is still reached by a KTP photo on a synced migration;
 *  5. every lock is free after each case.
 *
 * Recorded as it is today (see the last tests, not fixed here): any member of the activation group may pilih,
 * hp and ktp are checked by group only, and batal from a group outside the allowlist takes the plain cancel path.
 */
#[Group('blocking-psb')]
class MigrationGuidedSafetyInvariantsTest extends TestCase
{
    use MigrationGoldenHarness;
    use MigrationGoldenWorld;
    use RefreshDatabase;

    /** Event types that change a device. */
    private const DEVICE_WRITES = [
        'mt.addPPPSecret', 'mt.disablePPPSecret', 'mt.renamePPPSecret', 'olt.provisionFiberhome',
        'olt.deleteOnu', 'olt.renameOnu', 'huawei.executePlan', 'acs.ensureTagAfterProvision',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMigrationGolden();
        $this->seedWorld();
        $this->olt->uncfg = [];
        MigrationGuidedOverrideProbe::$calls = [];
    }

    protected function tearDown(): void
    {
        $this->shutdownMigrationGolden();
        parent::tearDown();
    }

    // ───────────────────────── helpers ─────────────────────────

    private function row(int $id): CustomerMigration
    {
        return CustomerMigration::query()->findOrFail($id);
    }

    /** A guided draft with its new SN waiting in the uncfg list of the target OLT. */
    private function draft(string $person, string $sn): int
    {
        $this->olt->uncfg[] = ['sn' => $sn, 'port' => '1/2/2', 'onu_id' => 3 + count($this->olt->uncfg)];

        return $this->guidedIntake('draft of '.$person, $person, $sn);
    }

    /** Everything a refused command must leave alone. */
    private function state(int $id): string
    {
        $row = $this->row($id);

        return (string) json_encode([
            'status' => $row->status,
            'new_sn' => $row->new_sn,
            'customer_id' => $row->customer_id,
            'phone' => $row->phone,
            'last_error' => $row->last_error,
            'meta' => $row->meta,
            'rows' => CustomerMigration::query()->count(),
            'ledgers' => DeviceActionLog::query()->where('target_type', 'customer_migration')->orderBy('id')->get()
                ->map(fn (DeviceActionLog $l): array => [$l->id, $l->status, $l->result, $l->executed_at?->toIso8601String()])->all(),
            'jobs' => count($this->queue->pending),
        ]);
    }

    /** @return list<array<string, mixed>> recorded events of the given types */
    private function events(array $types): array
    {
        $out = [];
        foreach ($this->steps as $step) {
            foreach ($step['events'] as $event) {
                if (in_array($event['type'] ?? '', $types, true)) {
                    $out[] = $event;
                }
            }
        }

        return $out;
    }

    private function lastTexts(): string
    {
        $last = $this->steps[count($this->steps) - 1] ?? ['events' => []];
        $texts = [];
        foreach ($last['events'] as $event) {
            if (($event['type'] ?? '') === 'wa.send') {
                $texts[] = (string) $event['text'];
            }
        }

        return implode("\n", $texts);
    }

    /** @return array<string, string> */
    private function commands(int $id): array
    {
        return [
            'konfirmasi' => '#migrasi konfirmasi #'.$id,
            'batal' => '#migrasi batal #'.$id,
            'sn' => '#migrasi sn #'.$id.' FHTTC0FFEE7F',
            'eksekusi' => '#migrasi eksekusi #'.$id,
        ];
    }

    // ───────────────────────── 1. foreign senders ─────────────────────────

    public function test_a_sender_outside_the_allowed_set_never_changes_a_draft(): void
    {
        $id = $this->draft('budi', 'FHTTC0FFEE71');
        $this->assertSame('dry_run_ready', data_get($this->row($id)->meta, 'device_action_approval.status'));
        $before = $this->state($id);

        // The selector and the bound sender are TECH, the static allowlist is NOC (in the guided group only).
        foreach ([[self::STRANGER, null], [self::OTHER_SENDER, null], [self::NOC, self::OTHER_GROUP], [self::STRANGER, self::OTHER_GROUP]] as [$sender, $group]) {
            foreach ($this->commands($id) as $name => $text) {
                if ($name === 'batal' && $sender === self::STRANGER && $group === self::OTHER_GROUP) {
                    continue; // recorded as is in the last test of this class
                }
                $this->say($name.' by '.$sender.($group ? ' in another group' : ''), $text, $sender, $group);
                $this->assertSame($before, $this->state($id), $name.' by '.$sender.' changed the draft');
            }
        }
        foreach ([[self::NOC, self::OTHER_GROUP], [self::STRANGER, self::OTHER_GROUP]] as [$sender, $group]) {
            $this->say('hp from another group by '.$sender, '#migrasi hp #'.$id.' 081200000077', $sender, $group);
            $this->assertSame($before, $this->state($id), 'hp from another group changed the draft');
        }
        $this->assertSame([], $this->events(self::DEVICE_WRITES), 'a refused command must never write a device');

        // Controls: the same commands from the allowed senders do act, so the test could see a change.
        $this->say('sn by the creator is accepted', '#migrasi sn #'.$id.' FHTTC0FFEE7F');
        $this->assertNotSame($before, $this->state($id));
        $this->assertSame('FHTTC0FFEE7F', $this->row($id)->new_sn);
        $this->probeLocksFree();
    }

    public function test_when_a_stranger_selected_only_the_selector_may_confirm_cancel_or_fix_the_sn(): void
    {
        $this->seedRinaCandidates();
        $this->olt->uncfg = [['sn' => 'FHTTC0FFEE72', 'port' => '1/2/2', 'onu_id' => 3]];
        $this->say('name-only intake: list of three', $this->rinaIntakeText('FHTTC0FFEE72'));
        $id = $this->lastMigrationId();
        $this->say('STRANGER pilih 1', '#migrasi pilih #'.$id.' 1', self::STRANGER);
        $this->assertSame(self::STRANGER, data_get($this->row($id)->meta, 'guided_wa_selection.selected_by_sender'));
        $before = $this->state($id);

        foreach ($this->commands($id) as $name => $text) {
            $this->say($name.' by OTHER_SENDER', $text, self::OTHER_SENDER);
            $this->assertSame($before, $this->state($id), $name.' by OTHER_SENDER changed the draft');
        }
        foreach (['konfirmasi', 'batal'] as $name) {
            $this->say($name.' by TECH (creator, not the selector)', $this->commands($id)[$name]);
            $this->assertSame($before, $this->state($id), $name.' by TECH changed a draft that a stranger selected');
        }
        $this->say('eksekusi by STRANGER (selector, not allowlisted, not bound)', $this->commands($id)['eksekusi'], self::STRANGER);
        $this->assertSame($before, $this->state($id));
        $this->probeLocksFree();
    }

    // ───────────────────────── 2. SN fix keeps or drops the selection ─────────────────────────

    public function test_an_sn_fix_keeps_a_valid_selection_and_a_conflicting_one_is_dropped(): void
    {
        $this->seedRinaCandidates();
        $this->olt->uncfg = [
            ['sn' => 'FHTTC0FFEE73', 'port' => '1/2/2', 'onu_id' => 3],
            ['sn' => 'FHTTC0FFEE74', 'port' => '1/2/2', 'onu_id' => 4],
            ['sn' => 'FHTTC0FFEE75', 'port' => '1/2/2', 'onu_id' => 5],
        ];
        $this->say('name-only intake: list of three', $this->rinaIntakeText('FHTTC0FFEE73'));
        $id = $this->lastMigrationId();
        $this->say('pilih 1', '#migrasi pilih #'.$id.' 1');
        $this->say('konfirmasi: dry-run ready', '#migrasi konfirmasi #'.$id);
        $customerId = (int) $this->row($id)->customer_id;
        $ledger = (int) data_get($this->row($id)->meta, 'device_action_approval.ledger_id');
        $this->assertSame('dry_run_ready', data_get($this->row($id)->meta, 'device_action_approval.status'));

        // Kept: the chosen customer is still in the fresh list.
        $this->say('sn to a free SN', '#migrasi sn #'.$id.' FHTTC0FFEE74');
        $this->assertSame('FHTTC0FFEE74', $this->row($id)->new_sn);
        $this->assertSame($customerId, (int) $this->row($id)->customer_id);
        $this->assertSame($customerId, (int) data_get($this->row($id)->meta, 'guided_wa_selection.customer_id'));
        $this->assertSame('confirmed_pending_approval', data_get($this->row($id)->meta, 'migration_review.match_status'));
        $this->assertSame('cancelled', DeviceActionLog::query()->findOrFail($ledger)->status);
        $this->assertSame('sn_corrected', data_get(DeviceActionLog::query()->findOrFail($ledger)->result, 'cancelled_reason'));
        $this->assertNull(data_get($this->row($id)->meta, 'device_action_approval'));

        // Dropped: every candidate now has a blocking conflict. The selection goes, the dry-run is cancelled.
        $this->say('konfirmasi: new dry-run', '#migrasi konfirmasi #'.$id);
        $ledger2 = (int) data_get($this->row($id)->meta, 'device_action_approval.ledger_id');
        $this->assertSame('dry_run_ready', data_get($this->row($id)->meta, 'device_action_approval.status'));
        PsbStarliteEntry::create(['full_name' => 'WATI LESTARI', 'pppoe_user' => 'USERWATI_RT01_RW02', 'sn' => 'FHTTC0FFEE75', 'install_status' => 'success']);
        $this->say('sn to an SN in the PSB history of another customer', '#migrasi sn #'.$id.' FHTTC0FFEE75');
        $this->assertNull(data_get($this->row($id)->meta, 'guided_wa_selection'), 'the selection must go');
        $this->assertSame('cancelled', data_get($this->row($id)->meta, 'guided_wa_confirmation.status'));
        $this->assertSame('cancelled', DeviceActionLog::query()->findOrFail($ledger2)->status);
        $this->assertSame('sn_corrected', data_get(DeviceActionLog::query()->findOrFail($ledger2)->result, 'cancelled_reason'), 'the SN command cancels the dry-run of the old SN before the refresh runs');
        $this->assertNull(data_get($this->row($id)->meta, 'device_action_approval'));
        // Recorded as is: the customer link on the row stays; only the selection and the approval go.
        $this->assertSame($customerId, (int) $this->row($id)->customer_id);
        $this->assertStringContainsString('dibatalkan', $this->lastTexts());
        $this->assertSame([], $this->events(self::DEVICE_WRITES));
        $this->probeLocksFree();
    }

    // ───────────────────────── 3. Huawei isolation ─────────────────────────

    public function test_a_huawei_draft_never_gets_a_candidate_list_or_a_c300_call(): void
    {
        $customer = Customer::create([
            'code' => 'GDG-61', 'name' => 'RUDI HARTONO', 'phone' => '081200000061', 'address' => 'RT 1 RW 2 Gadang',
            'package_id' => (int) Package::query()->where('code', 'GADANG-200M')->value('id'), 'pppoe_user' => 'USERRUDI_GADANG_RT01_RW02',
            'mikrotik_profile' => 'GADANG-200M', 'router_id' => self::GADANG_ROUTER, 'status' => 'active',
        ]);
        $this->fakeRouterRow($customer, self::GADANG_ROUTER, 'GADANG-200M');
        $this->huawei->autofind[] = ['serial_number' => 'HWTCC0FFEE71', 'pon_port' => '0/0/1', 'vendor_id' => 'HWTC'];

        $text = "#migrasi\nPPPoE: ".$customer->pppoe_user."\nSN baru: HWTCC0FFEE71\nOLT: Gadang";
        $this->say('Huawei short form', $text);
        $id = $this->lastMigrationId();
        $this->assertSame(HuaweiOltOnlyProvisioningService::MODE, data_get($this->row($id)->meta, 'migration_operation_mode'));

        $this->say('pilih 1', '#migrasi pilih #'.$id.' 1');
        $this->say('sn', '#migrasi sn #'.$id.' HWTCC0FFEE72');
        $this->say('the same text again', $text);
        $service = app(CustomerMigrationService::class);
        $retry = $service->retryGuidedFromWeb($this->row($id));
        $this->assertTrue($retry['handled']);
        $this->assertStringContainsString('mode Huawei dari WA: Retry web tidak dipakai', $retry['message']);
        $this->callPublic('processMigration (the review entry)', 'public:processMigration', fn () => $service->processMigration($this->row($id)));
        $this->assertStringContainsString('mode Huawei', $this->lastTexts());
        $steps = array_column((array) data_get($this->row($id)->meta, 'timeline'), 'step');
        $this->assertContains('review_skipped_huawei_olt_only', $steps, 'the refresh of a Huawei draft is skipped for huawei_olt_only');
        $this->assertNotContains('guided_wa_preview_refreshed', $steps);

        $meta = $this->row($id)->meta;
        $this->assertSame([], (array) data_get($meta, 'guided_wa_preview.candidates', []), 'no candidate list on a Huawei draft');
        $this->assertSame([], $this->events(['olt.listUncfg', 'olt.provisionFiberhome']), 'no C300 call for a Huawei draft');
        $this->assertSame(1, CustomerMigration::query()->count(), 'the re-send must reuse the Huawei draft');
        $this->probeLocksFree(['psb-huawei-olt-'.self::OLT_GADANG]);
    }

    // ───────────────────────── 4. override probe ─────────────────────────

    public function test_an_override_of_the_sync_and_photo_budget_names_is_reached_by_a_ktp_photo_on_a_synced_migration(): void
    {
        $this->app->bind(CustomerMigrationService::class, MigrationGuidedOverrideProbe::class);
        $this->app->forgetInstance(CustomerMigrationService::class);

        $id = $this->draft('siti', 'FHTTC0FFEE76');
        $this->say('eksekusi: job queued', '#migrasi eksekusi #'.$id);
        $this->drainJobs('job: success, synced', null, ExecuteCustomerMigrationWaJob::class);
        $this->assertSame(CustomerMigration::STATUS_SYNCED, $this->row($id)->status);
        $this->assertContains('sync', MigrationGuidedOverrideProbe::$calls, 'the run itself must go through the sync override');

        MigrationGuidedOverrideProbe::$calls = [];
        $ktp = $this->storeImage('ktp-a', 856, 540, 1, SyntheticOcrFixtures::ktpColumnWiseText());
        $this->inbound('ktp command with a photo on a synced migration', $this->imagePayload('#migrasi ktp #'.$id, [$ktp]));
        $this->assertStringContainsString('Foto KTP tersimpan', $this->lastTexts());
        $this->assertSame(['budget', 'sync'], MigrationGuidedOverrideProbe::$calls, 'photoBatchBudget then syncCustomerAfterNewOnu, both through the override');
        $this->probeLocksFree();
    }

    // ───────────────────────── recorded as is (not fixed here) ─────────────────────────

    public function test_recorded_as_is_hp_and_ktp_are_checked_by_group_only_and_any_member_may_pilih(): void
    {
        $id = $this->draft('dewi', 'FHTTC0FFEE77');
        $this->say('hp by a stranger of the same group', '#migrasi hp #'.$id.' 081200000078', self::STRANGER);
        $this->assertSame('081200000078', (string) $this->row($id)->phone, 'hp is accepted from any sender of the group');
        $ktp = $this->storeImage('ktp-a', 856, 540, 1, SyntheticOcrFixtures::ktpColumnWiseText());
        $this->inbound('ktp by a stranger of the same group', $this->imagePayload('#migrasi ktp #'.$id, [$ktp], self::STRANGER));
        $this->assertNotNull($this->row($id)->ktp_photo_path, 'ktp is accepted from any sender of the group');

        $this->seedRinaCandidates();
        $this->say('name-only intake: list of three', $this->rinaIntakeText('FHTTC0FFEE78'));
        $list = $this->lastMigrationId();
        $this->say('pilih by a stranger of the group', '#migrasi pilih #'.$list.' 2', self::STRANGER);
        $this->assertSame(self::STRANGER, data_get($this->row($list)->meta, 'guided_wa_selection.selected_by_sender'));
        $this->probeLocksFree();
    }

    public function test_recorded_as_is_batal_from_a_group_outside_the_allowlist_takes_the_plain_cancel_path(): void
    {
        $id = $this->draft('agus', 'FHTTC0FFEE79');
        $this->say('batal by a stranger in another group', '#migrasi batal #'.$id, self::STRANGER, self::OTHER_GROUP);
        $this->assertSame(CustomerMigration::STATUS_CANCELLED, $this->row($id)->status, 'recorded: the non-guided branch of handleInbound cancels by id');
        $this->probeLocksFree();
    }
}

/**
 * Real CustomerMigrationService that only records that its two overridable names are reached, then calls the original.
 */
class MigrationGuidedOverrideProbe extends CustomerMigrationService
{
    /** @var list<string> */
    public static array $calls = [];

    protected function syncCustomerAfterNewOnu(CustomerMigration $migration, Customer $customer, array $extra = []): void
    {
        self::$calls[] = 'sync';
        parent::syncCustomerAfterNewOnu($migration, $customer, $extra);
    }

    protected function photoBatchBudget(float $seconds): OcrBudget
    {
        self::$calls[] = 'budget';

        return parent::photoBatchBudget($seconds);
    }
}
