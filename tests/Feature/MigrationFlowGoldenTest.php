<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerMigrationImportRow;
use App\Models\OltOnu;
use App\Models\Package;
use App\Models\PsbDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Fixtures\SyntheticOcrFixtures;
use Tests\Support\MigrationGolden\MigrationGoldenHarness;
use Tests\Support\MigrationGolden\MigrationGoldenWorld;
use Tests\TestCase;

/**
 * Characterization goldens of the migration WA flow, part 1 (migration refactor M0, tests only).
 *
 * m01-m05: guided C300 happy path, Excel review + admin web actions, Huawei OLT-only,
 * SN correction before execution and partial execution with recovery. Part 2 (m06-m08) is
 * MigrationFlowGoldenEdgesTest. See MigrationGoldenHarness for how the goldens are made.
 *
 * Fixtures: tests/Fixtures/migration-flow/<scenario>.golden.json, written ONLY with the env
 * MIGRATION_FLOW_GOLDEN_WRITE=1 (never in CI). A golden change needs owner OK.
 */
#[Group('blocking-psb')]
class MigrationFlowGoldenTest extends TestCase
{
    use MigrationGoldenHarness;
    use MigrationGoldenWorld;
    use RefreshDatabase;

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
            'm01-guided-c300-happy-path',
            'm02-excel-review-and-admin-actions',
            'm03-huawei-olt-only',
            'm04-sn-correction-before-execution',
            'm05-partial-execution-and-recovery',
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

    /** m01: Krian 15 to 514 (cross-router) from intake to success, then the repeated commands. */
    private function scenario_m01_guided_c300_happy_path(): void
    {
        $this->olt->uncfg = [['sn' => 'FHTTC0FFEE51', 'port' => '1/2/2', 'onu_id' => 3]];

        $id = $this->guidedIntake('guided intake: ack, unique candidate, automatic dry-run', 'budi', 'FHTTC0FFEE51');
        $this->say('pilih candidate 1, after the automatic dry-run (no state change)', '#migrasi pilih #'.$id.' 1');
        $this->say('konfirmasi by the technician who selected, after the automatic dry-run (no state change)', '#migrasi konfirmasi #'.$id);
        $this->say('eksekusi by the technician: job queued', '#migrasi eksekusi #'.$id);
        $this->drainJobs('execution job: MikroTik, OLT, customer sync, old ONU, success text');
        $this->say('cek after success', '#migrasi cek #'.$id);
        $this->say('eksekusi again: already done, no job', '#migrasi eksekusi #'.$id);
        $this->say('bare #migrasi: today summary', '#migrasi');
        $this->drainJobs('no job is left');
    }

    /** m02: Excel short-form review in a non-allowlisted group, and the admin web actions on the same row. */
    private function scenario_m02_excel_review_and_admin_actions(): void
    {
        $this->olt->uncfg = [['sn' => 'FHTTC0FFEE52', 'port' => '1/2/2', 'onu_id' => 4]];
        $siti = $this->person('siti');
        $agus = $this->person('agus');
        foreach ([[$siti, 'FHTTC0FFEE52', 'ZTEGA0000012', 1], [$agus, 'FHTTC0FFEE53', 'ZTEGA0000014', 2]] as [$customer, $newSn, $oldSn, $row]) {
            CustomerMigrationImportRow::create([
                'batch_key' => 'batch-m0', 'row_number' => $row, 'classification' => 'migration', 'project' => 'starlite-akuisisi',
                'status' => 'applied', 'match_status' => 'migrated', 'full_name' => $customer->name, 'pppoe_user' => $customer->pppoe_user,
                'sn_lama' => $oldSn, 'sn_baru' => $newSn,
            ]);
        }
        $other = [self::STRANGER, self::OTHER_GROUP];
        $text = "#migrasi\nPPPoE: ".$siti->pppoe_user."\nSN baru: FHTTC0FFEE52\nRouter baru: Skynet-Krian-OLT";

        $payload = $this->commandPayload($text, ...$other);
        $this->inbound('Excel short form: findOrCreate, applyPayload, review text', $payload);
        $id = $this->lastMigrationId();
        $this->inbound('same message id delivered again: the existing row, no new review', $payload);
        $this->say('same operation with a new message id: stable duplicate reply', $text, ...$other);
        $this->say('lanjut #ID: updated for review', '#migrasi lanjut #'.$id, ...$other);

        PsbDraft::create(['status' => PsbDraft::STATUS_RECEIVED, 'message_id' => 'SEED-M02', 'sn' => 'FHTTC0FFEE53', 'pppoe_user' => 'USERPSB_RT01_RW02']);
        $this->say('another customer whose new SN is in a PSB draft: psb overlap in the review', "#migrasi\nPPPoE: ".$agus->pppoe_user."\nSN baru: FHTTC0FFEE53\nRouter baru: Skynet-Krian-OLT", self::OTHER_SENDER, self::OTHER_GROUP);

        $this->adminPost('admin web: prepare the dry-run', 'customer-migrations.device-action.prepare', $id, ['reason' => 'Admin cek dry-run migrasi']);
        $this->adminPost('admin web: execute with the global flags ON (processMigrationLocked)', 'customer-migrations.device-action.execute', $id, ['reason' => 'Admin jalankan migrasi']);
        $this->adminPost('admin web: retry on the synced row changes nothing', 'customer-migrations.retry', $id);
    }

    /** m03: Huawei OLT-only on Gadang (router 511, OLT 11): success, session busy, command failure. */
    private function scenario_m03_huawei_olt_only(): void
    {
        $gadangPackage = (int) Package::query()->where('code', 'GADANG-200M')->value('id');
        $people = [];
        foreach ([['DRAFTC WIJAYA', 'USERANDI_USERGDG_RT01_RW02', 'HWTCC0FFEE53'], ['USERTO HARTONO', 'USERTONI_USERGDG_RT01_RW02', 'HWTCC0FFEE54'], ['DRAFTG LESTARI', 'USERDEWI_USERGDG_RT01_RW02', 'HWTCC0FFEE55']] as $i => [$name, $user, $sn]) {
            $customer = Customer::create([
                'code' => 'GDG-'.$i, 'name' => $name, 'phone' => '08120000002'.$i, 'address' => 'RT 1 RW 2 Gadang', 'package_id' => $gadangPackage,
                'pppoe_user' => $user, 'mikrotik_profile' => 'GADANG-200M', 'router_id' => self::GADANG_ROUTER, 'status' => 'active',
            ]);
            $this->fakeRouterRow($customer, self::GADANG_ROUTER, 'GADANG-200M');
            $people[] = [$customer, $sn];
            $this->huawei->autofind[] = ['serial_number' => $sn, 'pon_port' => '0/0/1', 'vendor_id' => 'HWTC'];
        }

        foreach ($people as $i => [$customer, $sn]) {
            $this->say('Huawei short form '.($i + 1).': draft with the read-only preflight', "#migrasi\nPPPoE: ".$customer->pppoe_user."\nSN baru: ".$sn."\nOLT: Gadang");
            $id = $this->lastMigrationId();
            $this->say('konfirmasi: Huawei dry-run', '#migrasi konfirmasi #'.$id);
            $this->say('eksekusi: job queued', '#migrasi eksekusi #'.$id);
            if ($i === 1) {
                $this->huawei->sessionBusy = true;
            }
            if ($i === 2) {
                $this->huawei->executeThrows = true;
            }
            $this->drainJobs(['execution: Huawei plan and success text', 'execution with the OLT session busy: ledger back at dry_run', 'execution with a failed command: failed state'][$i]);
            $this->huawei->sessionBusy = false;
            $this->huawei->executeThrows = false;
            $this->say('cek after the run', '#migrasi cek #'.$id);
        }
    }

    /** m04: SN correction before execution on a guided draft (conflict, ownership, KTP, resend, cancel). */
    private function scenario_m04_sn_correction_before_execution(): void
    {
        $dewi = $this->person('dewi');
        $dewi->forceFill(['ktp_photo_url' => null])->save();
        $this->olt->uncfg = [['sn' => 'FHTTC0FFEE54', 'port' => '1/2/2', 'onu_id' => 5]];

        $id = $this->guidedIntake('intake with an SN that another customer owns: conflict line', 'dewi', 'ZTEGA0000014');
        $this->say('pilih refused: SN conflict', '#migrasi pilih #'.$id.' 1');
        $this->say('sn command with a bad format', '#migrasi sn #'.$id.' FHTT-XYZ');
        $this->say('sn command with an SN of another customer: ownership refusal', '#migrasi sn #'.$id.' ZTEGA0000016');
        $this->say('sn command with a free SN: list refreshed', '#migrasi sn #'.$id.' FHTTC0FFEE54');
        $this->say('pilih candidate 1', '#migrasi pilih #'.$id.' 1');

        // The pending choice lives 30 minutes. The clock moves 31 minutes for this one step and goes back
        // after it. The message id is set by hand so the ids of the later steps do not move.
        $base = Carbon::now();
        Carbon::setTestNow($base->copy()->addMinutes(31));
        $late = $this->commandPayload('#migrasi konfirmasi #'.$id);
        $this->messageSeq--;
        $late['message_id'] = 'FAKE-MIG-CLOCK-1';
        $this->inbound('konfirmasi 31 minutes after pilih: the pending choice has expired', $late);
        Carbon::setTestNow($base);

        $this->say('konfirmasi without a KTP: asks for it', '#migrasi konfirmasi #'.$id);

        $ktp = $this->storeImage('ktp-a', 856, 540, 1, SyntheticOcrFixtures::ktpColumnWiseText());
        $this->inbound('ktp command with a photo: classified by the PSB facade', $this->imagePayload('#migrasi ktp #'.$id, [$ktp]));
        $this->say('konfirmasi: dry-run ready', '#migrasi konfirmasi #'.$id);
        $this->say('full message resent with another SN: correction by resend', $this->intakeText('DRAFTG LESTARI', (string) $dewi->pppoe_user, 'FHTTC0FFEE58'));
        $this->say('batal by the same technician: cancelled before any device call', '#migrasi batal #'.$id);
    }

    /** m05: partial execution after a typo SN, the refusals while half-migrated, the recovery, and a forced cancel. */
    private function scenario_m05_partial_execution_and_recovery(): void
    {
        $this->olt->uncfg = [];
        OltOnu::create([
            'olt_id' => self::OLT_KRIAN, 'onu_ref' => '1/2/2:3', 'pon_port' => '1/2/2', 'serial_number' => 'FHTTC0FFEE56',
            'onu_name' => 'USERAGU', 'state' => 'online', 'phase_state' => 'working',
        ]);

        $id = $this->guidedIntake('intake with a typo SN: automatic dry-run', 'agus', 'FHTTC0FFEE55');
        $this->say('konfirmasi by the technician', '#migrasi konfirmasi #'.$id);
        $this->say('eksekusi: job queued', '#migrasi eksekusi #'.$id);
        $this->drainJobs('execution: MikroTik moved, OLT misses the typo, partially_executed with a hint');
        $this->adminPost('admin web retry is refused while half-migrated', 'customer-migrations.retry', $id);
        $this->say('cek shows the partial lines', '#migrasi cek #'.$id);
        $this->say('batal is refused with the rollback hint', '#migrasi batal #'.$id);
        $this->say('eksekusi on the executed ledger: continuation dry-run, no job', '#migrasi eksekusi #'.$id);
        $this->say('sn command with the real SN: continuation dry-run ready, name note', '#migrasi sn #'.$id.' FHTTC0FFEE56');
        $this->say('eksekusi: continuation job queued', '#migrasi eksekusi #'.$id);
        $this->drainJobs('continuation: no MikroTik write repeated, OLT link, synced');
        $this->say('cek after the continuation', '#migrasi cek #'.$id);

        $second = $this->guidedIntake('second customer with a typo SN: automatic dry-run', 'andi', 'FHTTC0FFEE57');
        $this->say('eksekusi second: job queued', '#migrasi eksekusi #'.$second);
        $this->drainJobs('second execution ends partially_executed');
        $this->say('batal paksa by the same technician: force cancel with rollback hint', '#migrasi batal paksa #'.$second);
        $this->say('eksekusi on the cancelled row', '#migrasi eksekusi #'.$second);
    }
}
