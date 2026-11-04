<?php

namespace Tests\Feature;

use App\Jobs\ExecuteCustomerMigrationWaJob;
use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\DeviceActionLog;
use App\Models\OltOnu;
use App\Models\Package;
use App\Models\PsbStarliteEntry;
use App\Services\CustomerMigrationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Fixtures\SyntheticOcrFixtures;
use Tests\Support\MigrationGolden\MigrationGoldenHarness;
use Tests\Support\MigrationGolden\MigrationGoldenWorld;
use Tests\TestCase;

/**
 * Characterization goldens of the guided WA flow, part 3 (migration refactor M4, PR A, tests only).
 *
 * m15 mixed senders on one draft, m16 list refresh / keep / drop / refusals / web Retry, m17 the Huawei
 * OLT-only intake kept apart from the guided list, m18 replacement confirmation on the same router.
 * They are recorded on the code BEFORE the M4 move and must stay byte-identical after it.
 *
 * The pilih / hp / ktp sender behaviour (any member of the activation group may pilih, hp and ktp are
 * checked by group only) is recorded as it is today; it is not fixed here.
 *
 * Fixtures: tests/Fixtures/migration-flow/<scenario>.golden.json, written ONLY with the env
 * MIGRATION_FLOW_GOLDEN_WRITE=1 (never in CI). A golden change needs owner OK.
 */
#[Group('blocking-psb')]
class MigrationFlowGoldenGuidedTest extends TestCase
{
    use MigrationGoldenHarness;
    use MigrationGoldenWorld;
    use RefreshDatabase;

    private const PON_KEY = 'device-olt-pon-9-1/2/2';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMigrationGolden();
    }

    protected function tearDown(): void
    {
        $this->shutdownMigrationGolden();
        parent::tearDown();
    }

    /** @return array<string, array{0: string}> */
    public static function scenarios(): array
    {
        $names = [
            'm15-guided-mixed-senders',
            'm16-guided-refresh-keep-drop-and-refusals',
            'm17-huawei-intake-isolation',
            'm18-replacement-confirmation',
        ];

        return array_combine($names, array_map(fn (string $n): array => [$n], $names));
    }

    #[DataProvider('scenarios')]
    public function test_scenario_matches_golden(string $name): void
    {
        $this->seedWorld();
        $this->{'scenario_'.str_replace('-', '_', $name)}();
        $this->compareToGolden($name, $this->encode($this->recordedScenario($name)));
    }

    // ───────────────────────── helpers ─────────────────────────

    /** @return list<string> every WA text of the last recorded step (ids already scrubbed) */
    private function lastStepTexts(): array
    {
        $last = $this->steps[count($this->steps) - 1] ?? ['events' => []];
        $texts = [];
        foreach ($last['events'] as $event) {
            if (($event['type'] ?? '') === 'wa.send') {
                $texts[] = (string) $event['text'];
            }
        }

        return $texts;
    }

    /** Fails when the last step did not answer with the expected text: a label must reach the path it names. */
    private function assertReply(string $needle, string $path): void
    {
        $this->assertStringContainsString($needle, implode("\n", $this->lastStepTexts()), 'step did not reach: '.$path);
    }

    private function row(int $id): CustomerMigration
    {
        return CustomerMigration::query()->findOrFail($id);
    }

    private function eventCount(string $type, ?int $oltId = null): int
    {
        $n = 0;
        foreach ($this->steps as $step) {
            foreach ($step['events'] as $event) {
                if (($event['type'] ?? '') === $type && ($oltId === null || (int) ($event['olt_id'] ?? $event['input']['olt_id'] ?? 0) === $oltId)) {
                    $n++;
                }
            }
        }

        return $n;
    }

    private function approvalLedgerStatus(int $id): ?string
    {
        $ledgerId = (int) data_get($this->row($id)->meta, 'device_action_approval.ledger_id', 0);

        return $ledgerId > 0 ? (string) DeviceActionLog::query()->find($ledgerId)?->status : null;
    }

    /** A fixture change recorded as its own step, so both arms start from the same DB state. */
    private function seedChange(string $label, \Closure $change): void
    {
        $this->step('fixture: '.$label, 'fixture:db', $change);
    }

    /** Runs the one step with the clock moved forward, then puts the clock back. The message id is set by hand so later ids do not move. */
    private function sayLater(string $label, int $minutes, string $text, ?string $sender = null): void
    {
        $base = Carbon::now();
        Carbon::setTestNow($base->copy()->addMinutes($minutes));
        $payload = $this->commandPayload($text, $sender);
        $this->messageSeq--;
        $payload['message_id'] = 'FAKE-MIG-CLOCK-'.(++$this->clockSeq);
        $this->inbound($label, $payload);
        Carbon::setTestNow($base);
    }

    private int $clockSeq = 0;

    /** Guided draft of one customer by PPPoE, then "eksekusi" while the PON port is busy: partially executed. */
    private function partialByBusyPon(string $label, string $person, string $sn): int
    {
        $id = $this->guidedIntake($label.': guided intake, automatic dry-run', $person, $sn);
        $this->say($label.': eksekusi: job queued', '#migrasi eksekusi #'.$id);
        $this->hold(self::PON_KEY);
        $this->drainJobs($label.': job with the PON port busy: PPPoE moved, OLT step not started, partial state', null, ExecuteCustomerMigrationWaJob::class);
        $this->release(self::PON_KEY);

        return $id;
    }

    // ───────────────────────── m15 ─────────────────────────

    /**
     * m15: one draft, several senders (Krian 15 to 514, a list of three same-name customers).
     * A stranger of the group picks (recorded as is); then only that sender may konfirmasi, batal and fix the SN.
     */
    private function scenario_m15_guided_mixed_senders(): void
    {
        $this->seedRinaCandidates();
        foreach (['rina1', 'rina2', 'rina3'] as $label) {
            $this->person($label)->forceFill(['ktp_photo_url' => null])->save();
        }
        $this->olt->uncfg = [['sn' => 'FHTTC0FFEE91', 'port' => '1/2/2', 'onu_id' => 3]];

        // createGuidedDraftFromWa: three customers with the same score, a list and no automatic dry-run.
        $this->say('TECH intake without PPPoE: list of three candidates, no automatic dry-run', $this->rinaIntakeText('FHTTC0FFEE91'));
        $id = $this->lastMigrationId();
        $this->assertReply('Preview migrasi', 'createGuidedDraftFromWa preview text');
        $this->assertCount(3, (array) data_get($this->row($id)->meta, 'guided_wa_preview.candidates'));
        $this->assertNull(data_get($this->row($id)->meta, 'device_action_approval'));

        // selectGuidedCandidateFromWa has no sender check: the stranger becomes the selector.
        $this->say('STRANGER (in the group, not allowlisted) pilih 1: selected by the stranger, recorded as is', '#migrasi pilih #'.$id.' 1', self::STRANGER);
        $this->assertReply('kandidat #1 dipilih', 'selectGuidedCandidateFromWa selection text');
        $this->assertSame(self::STRANGER, data_get($this->row($id)->meta, 'guided_wa_selection.selected_by_sender'));

        // confirmGuidedSelectionFromWa: sameGuidedSelectionSender says no.
        $this->say('TECH (the creator) konfirmasi: refused, the selector is the stranger', '#migrasi konfirmasi #'.$id);
        $this->assertReply('Kandidat harus dikonfirmasi oleh teknisi yang memilihnya', 'confirmGuidedSelectionFromWa sender refusal');

        // confirmGuidedSelectionFromWa: same sender, missing-data branch; attachMigrationKtpFromWa (group check only).
        $this->say('STRANGER konfirmasi with the KTP missing: asks for the KTP', '#migrasi konfirmasi #'.$id, self::STRANGER);
        $this->assertReply('Data kurang: foto KTP', 'confirmGuidedSelectionFromWa missing KTP');
        $ktp = $this->storeImage('ktp-a', 856, 540, 1, SyntheticOcrFixtures::ktpColumnWiseText());
        $this->inbound('TECH ktp command with a photo: accepted although TECH did not select (group check only)', $this->imagePayload('#migrasi ktp #'.$id, [$ktp]));
        $this->assertReply('Foto KTP tersimpan', 'attachMigrationKtpFromWa');
        $this->say('STRANGER konfirmasi: dry-run ready', '#migrasi konfirmasi #'.$id, self::STRANGER);
        $this->assertSame('dry_run_ready', data_get($this->row($id)->meta, 'device_action_approval.status'));
        $firstLedger = (int) data_get($this->row($id)->meta, 'device_action_approval.ledger_id');

        // dispatchWaExecution: waExecutionAllowlistGuard with the bound sender (the creator).
        $this->say('STRANGER eksekusi: blocked, sender not allowlisted and not the bound sender', '#migrasi eksekusi #'.$id, self::STRANGER);
        $this->assertReply('sender_not_allowlisted', 'dispatchWaExecution allowlist guard');
        $this->assertSame(0, count($this->queue->pending), 'a refused eksekusi must not queue a job');

        // correctMigrationSnFromWa (canModifyMigrationFromWa) and cancelGuidedSelectionFromWa (sameGuidedSelectionSender).
        $this->say('OTHER_SENDER (in the group) sn: refused, not the selector, not bound, not allowlisted', '#migrasi sn #'.$id.' FHTTC0FFEE92', self::OTHER_SENDER);
        $this->assertReply('Koreksi SN', 'correctMigrationSnFromWa refusal');
        $this->assertSame('FHTTC0FFEE91', $this->row($id)->new_sn);
        $this->say('OTHER_SENDER batal: refused', '#migrasi batal #'.$id, self::OTHER_SENDER);
        $this->assertReply('Hanya teknisi yang membuat atau memilih draft tersebut yang boleh membatalkan', 'cancelGuidedSelectionFromWa refusal');
        $this->assertNotSame(CustomerMigration::STATUS_CANCELLED, $this->row($id)->status);

        // An allowlisted sender in another group passes canUseWa (sender allowlist) but every command looks the draft up by group.
        $this->say('NOC in OTHER_GROUP konfirmasi: the draft is not found in that group', '#migrasi konfirmasi #'.$id, self::NOC, self::OTHER_GROUP);
        $this->assertReply('Migrasi tidak ditemukan. Pakai: #migrasi konfirmasi', 'confirmGuidedSelectionFromWa group mismatch');
        $this->say('NOC in OTHER_GROUP sn: not found in this group', '#migrasi sn #'.$id.' FHTTC0FFEE92', self::NOC, self::OTHER_GROUP);
        $this->assertReply('Migrasi tidak ditemukan di grup ini', 'correctMigrationSnFromWa group mismatch');
        $this->say('NOC in OTHER_GROUP hp: not found', '#migrasi hp #'.$id.' 081200000077', self::NOC, self::OTHER_GROUP);
        $this->assertReply('Migrasi tidak ditemukan', 'updateMigrationPhoneFromWa group mismatch');
        $this->assertNotSame('081200000077', (string) $this->row($id)->phone);

        // selectGuidedCandidateFromWa again, by TECH: another customer, the dry-run of the first one is cancelled.
        $this->say('TECH pilih 2: another customer, the earlier dry-run ledger is cancelled (candidate_reselected) and the old approval is cleared from the draft', '#migrasi pilih #'.$id.' 2');
        $this->assertSame('cancelled', DeviceActionLog::query()->findOrFail($firstLedger)->status);
        $this->assertSame('candidate_reselected', data_get(DeviceActionLog::query()->findOrFail($firstLedger)->result, 'cancelled_reason'));
        $this->assertSame((int) $this->person('rina2')->id, (int) $this->row($id)->customer_id);
        $this->assertNull(data_get($this->row($id)->meta, 'device_action_approval'), 'the old approval must not survive a re-pick');
        $ledgerRows = DeviceActionLog::query()->where('target_type', 'customer_migration')->count();
        $this->inbound('TECH konfirmasi through the sender_jid_lid alias plus sender_jid_pn: accepted and a NEW dry-run is made for the new customer; the reply text equals an earlier one, so the 15 s reply dedupe drops it', $this->aliasPayload('#migrasi konfirmasi #'.$id, self::TECH, self::TECH_LID));
        $secondLedger = (int) data_get($this->row($id)->meta, 'device_action_approval.ledger_id');
        $this->assertNotSame($firstLedger, $secondLedger, 'a new dry-run ledger, not the cancelled one');
        $this->assertSame($ledgerRows + 1, DeviceActionLog::query()->where('target_type', 'customer_migration')->count(), 'one new ledger row');
        $this->assertSame('cancelled', DeviceActionLog::query()->findOrFail($firstLedger)->status, 'the first ledger stays cancelled');
        $this->assertSame('dry_run_ready', data_get($this->row($id)->meta, 'device_action_approval.status'));

        // dispatchWaExecution + ExecuteCustomerMigrationWaJob by the bound sender.
        $this->say('TECH eksekusi: job queued', '#migrasi eksekusi #'.$id);
        $this->drainJobs('execution job: MikroTik, OLT, customer sync, old ONU, success text, on the NEW ledger');
        $this->assertSame('cancelled', DeviceActionLog::query()->findOrFail($firstLedger)->status, 'the cancelled ledger is never executed');
        $this->assertSame('verified', DeviceActionLog::query()->findOrFail($secondLedger)->status, 'the new ledger is the one executed');
        $this->assertSame(CustomerMigration::STATUS_SYNCED, $this->row($id)->status);
        $this->probeLocksFree();
    }

    // ───────────────────────── m16 ─────────────────────────

    /**
     * m16: refresh of the guided list (keep / drop), the refusals of pilih and konfirmasi, and the web Retry.
     * Starts that cannot be reached through public entries are seeded in the DB in a step of their own.
     */
    private function scenario_m16_guided_refresh_keep_drop_and_refusals(): void
    {
        $this->seedRinaCandidates();
        $uncfg = [];
        foreach (range(0x11, 0x1F) as $i => $n) {
            $uncfg[] = ['sn' => 'FHTTC0FFEE'.strtoupper(dechex($n)), 'port' => '1/2/2', 'onu_id' => 3 + $i];
        }
        $this->olt->uncfg = $uncfg;

        // One text of this scenario is cut at a fixed length and carries raw row ids. Every migration of the scenario gets a
        // four digit id (the next id is above 5000), so the cut never moves with the random ids of burnIds().
        $bump = new CustomerMigration(['status' => CustomerMigration::STATUS_CANCELLED, 'message_id' => 'BUMP-IDS']);
        $bump->id = 5000;
        $bump->save();
        $bump->delete();

        // createGuidedDraftFromWa: LockBusy branch of the automatic dry-run. A row is created and deleted to learn the id
        // the new draft will get (ids are never reused), and the device lock of that id is held before the intake.
        $probe = CustomerMigration::create(['status' => CustomerMigration::STATUS_CANCELLED, 'message_id' => 'PROBE-NEXT-ID']);
        $nextId = (int) $probe->id + 1;
        $probe->delete();
        $this->hold('customer-migration-device-'.$nextId);
        $u = $this->guidedIntake('draft U: unique candidate, the device lock of its row is held: automatic dry-run meets LockBusy, busy text in the preview', 'budi', 'FHTTC0FFEE11');
        $this->release('customer-migration-device-'.$nextId);
        $this->assertSame($nextId, $u, 'the draft must get the predicted id');
        $this->assertReply('Dry-run tertahan: Migrasi', 'createGuidedDraftFromWa LockBusy');
        $this->assertNull(data_get($this->row($u)->meta, 'device_action_approval.status'));
        $this->assertGreaterThan(0, (int) data_get($this->row($u)->meta, 'guided_wa_selection.customer_id'), 'the candidate selection is saved');

        // List draft A (three same-name customers).
        $this->say('draft A: name-only intake, list of three candidates', $this->rinaIntakeText('FHTTC0FFEE12'));
        $a = $this->lastMigrationId();
        $this->say('lanjut in the guided group: blocked text, nothing changes', '#migrasi lanjut #'.$a);
        $this->assertReply('belum mengubah data', 'handleInbound guided lanjut');
        $this->say('pilih 9: number out of range', '#migrasi pilih #'.$a.' 9');
        $this->assertReply('Pilihan migrasi tidak valid', 'selectGuidedCandidateFromWa range check');
        $this->say('pilih 4: valid number but the list has three', '#migrasi pilih #'.$a.' 4');
        $this->assertReply('Kandidat #4 tidak tersedia', 'selectGuidedCandidateFromWa candidate not listed');

        $this->say('pilih 1', '#migrasi pilih #'.$a.' 1');
        $this->say('konfirmasi: dry-run ready', '#migrasi konfirmasi #'.$a);
        $this->assertSame('dry_run_ready', data_get($this->row($a)->meta, 'device_action_approval.status'));
        $customerA = (int) $this->row($a)->customer_id;
        $ledgerA1 = (int) data_get($this->row($a)->meta, 'device_action_approval.ledger_id');

        // correctMigrationSnFromWa + refreshGuidedPreview: the chosen customer is still a candidate, the selection is kept.
        $this->say('sn to a free SN: list refreshed, selection kept, old dry-run ledger cancelled (sn_corrected)', '#migrasi sn #'.$a.' FHTTC0FFEE13');
        $this->assertSame($customerA, (int) $this->row($a)->customer_id);
        $this->assertSame($customerA, (int) data_get($this->row($a)->meta, 'guided_wa_selection.customer_id'));
        $this->assertSame('sn_corrected', data_get(DeviceActionLog::query()->findOrFail($ledgerA1)->result, 'cancelled_reason'));
        $this->say('konfirmasi: new dry-run for the same customer (no WA text: the reply equals an earlier one and the 15 s reply dedupe drops it)', '#migrasi konfirmasi #'.$a);
        $this->assertSame('dry_run_ready', data_get($this->row($a)->meta, 'device_action_approval.status'));
        $this->assertSame($customerA, (int) $this->row($a)->customer_id);

        // The SN is now in the PSB history of another customer: every candidate has a blocking conflict, the selection is dropped.
        $this->seedChange('another customer holds the SN in the PSB history', function (): void {
            PsbStarliteEntry::create(['full_name' => 'WATI LESTARI', 'pppoe_user' => 'USERWATI_RT01_RW02', 'sn' => 'FHTTC0FFEE14', 'install_status' => 'success']);
        });
        $ledgerA2 = (int) data_get($this->row($a)->meta, 'device_action_approval.ledger_id');
        $this->say('sn to an SN in other PSB history: selection dropped, dry-run cancelled, list text', '#migrasi sn #'.$a.' FHTTC0FFEE14');
        $this->assertNull(data_get($this->row($a)->meta, 'guided_wa_selection'));
        $this->assertSame('cancelled', DeviceActionLog::query()->findOrFail($ledgerA2)->status);
        $this->assertReply('dibatalkan', 'refreshGuidedPreview dropped selection');

        // pilih on a stale list: the list is rebuilt and shown instead of selecting.
        $this->say('sn back to a free SN: list refreshed, nothing selected', '#migrasi sn #'.$a.' FHTTC0FFEE15');
        $this->seedChange('the stored list is marked as made for another SN (stale list, same DB change in both arms)', function () use ($a): void {
            $row = $this->row($a);
            $meta = (array) $row->meta;
            $meta['guided_wa_preview']['new_sn'] = 'FHTTC0FFEE00';
            $row->meta = $meta;
            $row->save();
        });
        $this->say('pilih 1 on the stale list: fresh list shown, no selection (pilih_stale)', '#migrasi pilih #'.$a.' 1');
        $this->assertReply('Daftar pelanggan', 'selectGuidedCandidateFromWa stale list');
        $this->assertNull(data_get($this->row($a)->meta, 'guided_wa_selection'));
        $this->say('konfirmasi without a selection: pick-first text', '#migrasi konfirmasi #'.$a);
        $this->assertReply('pilih pelanggan dulu', 'confirmGuidedSelectionFromWa without selection');

        // A draft whose list cannot be rebuilt (no raw text): plain answers.
        $agus = $this->person('agus');
        $this->seedChange('a guided draft with a selection and no raw text (the lookup input is incomplete)', function () use ($agus): void {
            CustomerMigration::create([
                'status' => CustomerMigration::STATUS_NEEDS_REVIEW, 'source' => 'guided_lookup_wa', 'group_jid' => self::GROUP,
                'sender_jid' => self::TECH, 'message_id' => 'guided-wa:seed-incomplete', 'customer_id' => $agus->id, 'new_sn' => 'FHTTC0FFEE16',
                'meta' => [
                    'guided_wa_preview' => ['status' => 'needs_review', 'candidates' => [['number' => 1, 'customer_id' => $agus->id, 'name' => $agus->name, 'pppoe_user' => $agus->pppoe_user, 'score' => 100, 'confidence' => 'high', 'conflicts' => []]], 'updated_at' => now()->toIso8601String(), 'new_sn' => 'FHTTC0FFEE1F'],
                    'guided_wa_selection' => ['candidate_number' => 1, 'customer_id' => $agus->id],
                    'migration_review' => ['match_status' => 'needs_review', 'reasons' => ['excel_confirm_missing']],
                ],
            ]);
        });
        $incomplete = $this->lastMigrationId();
        $this->say('input_incomplete draft: pilih gives a plain answer', '#migrasi pilih #'.$incomplete.' 1');
        $this->assertReply('data belum cocok', 'selectGuidedCandidateFromWa input_incomplete');
        $this->say('input_incomplete draft: konfirmasi gives a plain answer', '#migrasi konfirmasi #'.$incomplete);
        $this->assertReply('Pelanggan belum ketemu', 'confirmGuidedSelectionFromWa input_incomplete');

        // newerSyncedMigration.
        $n = $this->guidedIntake('draft N: unique candidate, automatic dry-run', 'dewi', 'FHTTC0FFEE17');
        $dewi = $this->person('dewi');
        $this->seedChange('a later migration of the same customer and SN is already synced', function () use ($dewi): void {
            CustomerMigration::create([
                'status' => CustomerMigration::STATUS_SYNCED, 'source' => 'guided_lookup_wa', 'group_jid' => self::GROUP, 'sender_jid' => self::TECH,
                'message_id' => 'guided-wa:seed-synced', 'customer_id' => $dewi->id, 'pppoe_user' => $dewi->pppoe_user, 'new_sn' => 'FHTTC0FFEE17', 'synced_at' => now(),
            ]);
        });
        $this->say('pilih on a draft that a newer synced migration already finished', '#migrasi pilih #'.$n.' 1');
        $this->assertReply('sudah selesai melalui', 'selectGuidedCandidateFromWa newerSyncedMigration');

        // confirmGuidedWaCandidate: the customer has another partially executed migration.
        $this->partialByBusyPon('customer 3 of the list', 'rina3', 'FHTTC0FFEE18');
        $this->say('draft D2: name-only intake, list of three candidates (the third has a partial migration)', $this->rinaIntakeText('FHTTC0FFEE19'));
        $d2 = $this->lastMigrationId();
        $this->say('pilih 3: the customer has another partial migration, "belum siap" with the partial migration named', '#migrasi pilih #'.$d2.' 3');
        $this->assertReply('belum siap: Pelanggan ini masih punya migrasi', 'confirmGuidedWaCandidate partial migration refusal');
        $this->assertNull(data_get($this->row($d2)->meta, 'guided_wa_selection.customer_id'));

        // retryGuidedFromWeb.
        $this->adminPost('web Retry on draft U: selection still valid (kept)', 'customer-migrations.retry', $u);
        $this->seedChange('the stored candidate list of draft D2 is emptied (the order will change)', function () use ($d2): void {
            $row = $this->row($d2);
            $meta = (array) $row->meta;
            $meta['guided_wa_preview']['candidates'] = [];
            $row->meta = $meta;
            $row->save();
        });
        $this->adminPost('web Retry on draft D2: list order changed, one WA list message', 'customer-migrations.retry', $d2);
        $this->assertReply('Daftar pelanggan', 'retryGuidedFromWeb order changed');
        $this->hold('customer-migration-review-'.$u);
        $this->adminPost('web Retry with the review lock held: busy error', 'customer-migrations.retry', $u);
        $this->release('customer-migration-review-'.$u);
        $this->seedChange('the SN of draft U is found in the PSB history of another customer', function (): void {
            PsbStarliteEntry::create(['full_name' => 'WATI LESTARI', 'pppoe_user' => 'USERWATI_RT01_RW02', 'sn' => 'FHTTC0FFEE11', 'install_status' => 'success']);
        });
        $this->adminPost('web Retry on draft U: selection dropped, dropped text', 'customer-migrations.retry', $u);
        $this->assertNull(data_get($this->row($u)->meta, 'guided_wa_selection'));
        $done = $this->guidedIntake('draft S: unique candidate, automatic dry-run', 'siti', 'FHTTC0FFEE1A');
        $this->say('eksekusi: job queued', '#migrasi eksekusi #'.$done);
        $this->drainJobs('job: success, synced', null, ExecuteCustomerMigrationWaJob::class);
        $this->assertSame(CustomerMigration::STATUS_SYNCED, $this->row($done)->status);
        $this->adminPost('web Retry on a synced guided draft: falls through to processMigration, nothing changes', 'customer-migrations.retry', $done);

        // createGuidedDraftFromWa: a name-only re-send whose unique candidate already has an open migration.
        $toni = $this->person('toni');
        $t = $this->guidedIntake('draft T: unique candidate, automatic dry-run', 'toni', 'FHTTC0FFEE1B');
        $this->say('name-only re-send (no PPPoE) with another SN: SN correction on the open draft of that customer', $this->intakeText((string) $toni->name, '', 'FHTTC0FFEE1C', ['PPPoE' => null, 'HP' => (string) $toni->phone]));
        $this->assertSame('FHTTC0FFEE1C', $this->row($t)->new_sn);
        $this->assertReply('SN diperbarui', 'createGuidedDraftFromWa name-only resend');

        $this->probeLocksFree();
    }

    // ───────────────────────── m17 ─────────────────────────

    /** m17: Gadang (router 511, Huawei OLT 11): the OLT-only intake never meets the guided list or the C300 path. */
    private function scenario_m17_huawei_intake_isolation(): void
    {
        $gadangPackage = (int) Package::query()->where('code', 'GADANG-200M')->value('id');
        $make = function (string $code, string $name, string $user, string $phone, ?string $oldSn) use ($gadangPackage): Customer {
            $customer = Customer::create([
                'code' => $code, 'name' => $name, 'phone' => $phone, 'address' => 'RT 1 RW 2 Gadang', 'package_id' => $gadangPackage,
                'pppoe_user' => $user, 'mikrotik_profile' => 'GADANG-200M', 'router_id' => self::GADANG_ROUTER, 'status' => 'active', 'onu_serial' => $oldSn,
            ]);
            $this->fakeRouterRow($customer, self::GADANG_ROUTER, 'GADANG-200M');

            return $customer;
        };
        $rudi = $make('GDG-51', 'RUDI HARTONO', 'USERRUDI_USERGDG_RT01_RW02', '081200000051', 'ZTEGA0000051');
        $wati = $make('GDG-52', 'WATI LESTARI', 'USERWATI_USERGDG_RT01_RW02', '081200000052', null);
        $yani = $make('GDG-53', 'YANI SAPUTRI', 'USERYANI_USERGDG_RT01_RW02', '081200000053', null);
        foreach (['HWTCC0FFEE61', 'HWTCC0FFEE62', 'HWTCC0FFEE63', 'HWTCC0FFEE64'] as $sn) {
            $this->huawei->autofind[] = ['serial_number' => $sn, 'pon_port' => '0/0/1', 'vendor_id' => 'HWTC'];
        }

        // createGuidedDraftFromWa -> createHuaweiOltOnlyDraftFromWa; PPPoE omitted, resolved read-only from the old SN.
        $text = "#migrasi\nNama: ".$rudi->name."\nSN Baru: HWTCC0FFEE61\nSN Lama: ZTEGA0000051\nOLT: Gadang";
        $this->say('Huawei intake without PPPoE: PPPoE resolved from the old SN, draft with the read-only preflight', $text);
        $h = $this->lastMigrationId();
        $this->assertSame('USERRUDI_USERGDG_RT01_RW02', strtoupper((string) $this->row($h)->pppoe_user));
        $this->assertSame('old_sn', data_get($this->row($h)->meta, 'pppoe_resolution.source'));
        $this->assertReply('Draft Huawei OLT-only', 'createHuaweiOltOnlyDraftFromWa draft text');

        // Nothing of the guided machinery touches the Huawei draft.
        $this->say('pilih 1 on the Huawei draft: no candidate list exists', '#migrasi pilih #'.$h.' 1');
        $this->assertReply('Kandidat #1 tidak tersedia', 'selectGuidedCandidateFromWa on a Huawei draft');
        $this->assertNull(data_get($this->row($h)->meta, 'guided_wa_preview.candidates'));
        $this->say('sn on the Huawei draft: refused, send the Huawei format again', '#migrasi sn #'.$h.' HWTCC0FFEE62');
        $this->assertReply('mode Huawei OLT-only', 'correctMigrationSnFromWa Huawei refusal');
        $this->assertSame('HWTCC0FFEE61', $this->row($h)->new_sn);
        $this->adminPost('web Retry on the Huawei draft: not used', 'customer-migrations.retry', $h);
        $photo = $this->storeImage('ktp-a', 856, 540, 1, SyntheticOcrFixtures::ktpColumnWiseText());
        $this->callPublic('follow-up photo on the Huawei draft (status needs_review): the photo is kept, no review runs', 'public:attachFollowUpImages', fn () => app(CustomerMigrationService::class)->attachFollowUpImages($this->row($h), [$this->imageBytes($photo)], '#migrasi ktp #'.$h));
        $this->assertSame([], $this->lastStepTexts(), 'attachFollowUpImages on needs_review sends no WA text');
        // The follow-up photo reaches the review (prepareReviewDraft) only on a failed, received or waiting row: seeded as failed.
        $this->seedChange('the Huawei draft is marked failed (same DB change in both arms)', function () use ($h): void {
            CustomerMigration::query()->whereKey($h)->update(['status' => CustomerMigration::STATUS_FAILED]);
        });
        $this->callPublic('follow-up photo on the failed Huawei draft: processMigration, review guard answers "mode Huawei", no candidate list', 'public:attachFollowUpImages', fn () => app(CustomerMigrationService::class)->attachFollowUpImages($this->row($h), [$this->imageBytes($photo)], '#migrasi ktp #'.$h));
        $this->assertReply('mode Huawei', 'prepareReviewDraft Huawei guard (skipReviewForGuidedDraft)');
        $this->assertNull(data_get($this->row($h)->meta, 'guided_wa_preview.candidates'));
        $this->seedChange('the Huawei draft is put back to needs_review (same DB change in both arms)', function () use ($h): void {
            CustomerMigration::query()->whereKey($h)->update(['status' => CustomerMigration::STATUS_NEEDS_REVIEW]);
        });

        $this->say('the same text re-sent: same draft row (same huawei-olt-only-wa key), preflight run again (no draft reply: it equals the first one and the 15 s reply dedupe drops it)', $text);
        $this->assertSame($h, $this->lastMigrationId(), 'the re-send must reuse the Huawei draft row');
        $this->assertSame(1, CustomerMigration::query()->where('message_id', 'like', 'huawei-olt-only-wa:%')->count());

        // Preflight with the OLT session busy.
        $this->huawei->sessionBusy = true;
        $this->say('Huawei intake with the OLT session busy: draft kept, olt_session_busy', "#migrasi\nPPPoE: ".$yani->pppoe_user."\nSN baru: HWTCC0FFEE63\nOLT: Gadang");
        $this->huawei->sessionBusy = false;
        $busyDraft = $this->lastMigrationId();
        $this->assertContains('olt_session_busy', (array) data_get($this->row($busyDraft)->meta, 'huawei_olt_only.preflight.reasons'));

        // confirmGuidedSelectionFromWa -> confirmHuaweiOltOnlyFromWa.
        $this->say('OTHER_SENDER konfirmasi: refused, not the sender of the draft', '#migrasi konfirmasi #'.$h, self::OTHER_SENDER);
        $this->assertReply('Kandidat harus dikonfirmasi oleh teknisi yang memilihnya', 'confirmGuidedSelectionFromWa sender refusal on a Huawei draft');
        $this->sayLater('konfirmasi 31 minutes later: the pending choice has expired', 31, '#migrasi konfirmasi #'.$h);
        $this->assertReply('kedaluwarsa', 'confirmGuidedSelectionFromWa expiry');
        $this->hold('customer-migration-device-'.$h);
        $this->say('konfirmasi with the device lock held: busy text, last_error restored', '#migrasi konfirmasi #'.$h);
        $this->release('customer-migration-device-'.$h);
        $this->assertReply('sedang diproses perangkat', 'confirmHuaweiOltOnlyFromWa LockBusy');
        $this->assertStringNotContainsString('menyiapkan dry-run', (string) $this->row($h)->last_error);
        $this->say('konfirmasi: Huawei dry-run', '#migrasi konfirmasi #'.$h);
        $this->assertSame('dry_run_ready', data_get($this->row($h)->meta, 'device_action_approval.status'));

        // A Huawei short form from a stranger in another group is not a guided message: the Excel review path.
        $this->say('Huawei short form from STRANGER in OTHER_GROUP: Excel review path, recorded as is', "#migrasi\nPPPoE: ".$wati->pppoe_user."\nSN baru: HWTCC0FFEE64\nOLT: Gadang", self::STRANGER, self::OTHER_GROUP);

        $this->assertSame(0, $this->eventCount('olt.listUncfg', self::OLT_GADANG), 'no C300 list call for the Huawei OLT');
        $this->assertSame(0, $this->eventCount('olt.provisionFiberhome', self::OLT_GADANG), 'no FiberHome provision for the Huawei OLT');
        $this->assertSame(0, count(CustomerMigration::query()->where('source', 'guided_lookup_wa')->get()->filter(fn (CustomerMigration $m): bool => (array) data_get($m->meta, 'huawei_olt_only') !== [] && (array) data_get($m->meta, 'guided_wa_preview.candidates', []) !== [])));
        $this->probeLocksFree(['psb-huawei-olt-'.self::OLT_GADANG]);
    }

    // ───────────────────────── m18 ─────────────────────────

    /** m18: replacement confirmation on the same router (RA 513, OLT 1): confirmReplacementFromWa. */
    private function scenario_m18_replacement_confirmation(): void
    {
        $hendra = Customer::create([
            'code' => 'RA-41', 'name' => 'DRAFTI KURNIAWAN', 'phone' => '081200000041', 'address' => 'RT 1 RW 2 Klampok',
            'package_id' => (int) Package::query()->where('code', 'RA-200M')->value('id'), 'pppoe_user' => 'USERHENDRA_RA_RT01_RW02', 'mikrotik_profile' => '200M',
            'ktp_photo_url' => 'customers/ktp/hendra.jpg', 'onu_serial' => 'ZTEGA0000041', 'olt_port_label' => '1/1/1:5', 'odp_name' => 'ODP-RA-01',
            'router_id' => self::RA_ROUTER, 'olt_id' => self::OLT_RA, 'status' => 'active',
        ]);
        $this->people['hendra'] = (int) $hendra->id;
        OltOnu::create(['olt_id' => self::OLT_RA, 'onu_ref' => '1/1/1:5', 'pon_port' => '1/1/1', 'serial_number' => 'ZTEGA0000041', 'onu_name' => $hendra->pppoe_user, 'state' => 'online', 'phase_state' => 'working']);
        $this->fakeRouterRow($hendra, self::RA_ROUTER, '200M');
        $this->mt->sourceRouterIds = [self::KRIAN_OLD, self::RA_ROUTER];
        $this->olt->uncfg = [['sn' => 'FHTTC0FFEE81', 'port' => '1/1/1', 'onu_id' => 3], ['sn' => 'FHTTC0FFEE82', 'port' => '1/1/1', 'onu_id' => 4]];
        $same = ['Router lama' => 'SKYNET-RA-OLT', 'Router baru' => 'SKYNET-RA-OLT', 'OLT' => 'RA'];

        $id = $this->guidedIntake('intake on the same router with a new SN: candidate found, automatic dry-run held back (replacement not confirmed)', 'hendra', 'FHTTC0FFEE81', $same);
        $this->assertNull(data_get($this->row($id)->meta, 'device_action_approval.status'));
        $this->say('pilih 1', '#migrasi pilih #'.$id.' 1');
        $this->say('konfirmasi: confirmReplacementFromWa, replacement confirmed, dry-run ready', '#migrasi konfirmasi #'.$id);
        $this->assertReply('Replacement', 'confirmReplacementFromWa');
        $this->assertSame('confirmed', data_get($this->row($id)->meta, 'replacement_confirmation.status'));
        $this->assertSame('dry_run_ready', data_get($this->row($id)->meta, 'device_action_approval.status'));
        $ledger1 = (int) data_get($this->row($id)->meta, 'device_action_approval.ledger_id');

        $this->say('konfirmasi again: already confirmed, reusable dry-run, short answer', '#migrasi konfirmasi #'.$id);
        $this->assertReply('sudah dikonfirmasi', 'confirmReplacementFromWa short-circuit');
        $this->assertSame($ledger1, (int) data_get($this->row($id)->meta, 'device_action_approval.ledger_id'));

        $this->say('sn to a free SN: dry-run cancelled, replacement confirmation re-affirmed through sn_correction', '#migrasi sn #'.$id.' FHTTC0FFEE82');
        $this->assertSame('sn_correction', data_get($this->row($id)->meta, 'replacement_confirmation.via'));
        $this->assertSame('cancelled', DeviceActionLog::query()->findOrFail($ledger1)->status);
        $this->say('konfirmasi after the SN fix: no reusable dry-run, a new dry-run is prepared', '#migrasi konfirmasi #'.$id);
        $this->assertSame('dry_run_ready', data_get($this->row($id)->meta, 'device_action_approval.status'));

        $this->sayLater('konfirmasi 31 minutes later: the pilih confirmation expired, refused', 31, '#migrasi konfirmasi #'.$id);
        $this->assertReply('kedaluwarsa', 'confirmGuidedSelectionFromWa expiry');
        $base = Carbon::now();
        Carbon::setTestNow($base->copy()->addMinutes(31));
        $this->say('pilih 1 again 31 minutes later: a fresh pending choice', '#migrasi pilih #'.$id.' 1');
        $this->hold('customer-migration-device-'.$id);
        $this->say('konfirmasi with the device lock held: the replacement confirmation expired, so the dry-run is asked again and meets LockBusy', '#migrasi konfirmasi #'.$id);
        $this->release('customer-migration-device-'.$id);
        $this->assertReply('sedang diproses perangkat', 'confirmReplacementFromWa LockBusy');
        $this->say('konfirmasi again, lock free: the failed attempt had already renewed the confirmation, a reusable dry-run exists, short answer', '#migrasi konfirmasi #'.$id);
        Carbon::setTestNow($base);
        $this->assertReply('sudah dikonfirmasi', 'confirmReplacementFromWa short-circuit after a renewed confirmation');
        $this->assertSame('dry_run_ready', data_get($this->row($id)->meta, 'device_action_approval.status'));

        $this->say('eksekusi: job queued', '#migrasi eksekusi #'.$id);
        $this->drainJobs('execution job on the same router: replacement runs, success text');
        $this->say('cek after the run', '#migrasi cek #'.$id);
        $this->probeLocksFree();
    }
}
