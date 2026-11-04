<?php

namespace Tests\Unit\Psb;

use App\Models\Customer;
use App\Models\Package;
use App\Models\PsbDraft;
use App\Models\Router;
use App\Services\MikrotikService;
use App\Services\Psb\PsbLiveCorrection;
use App\Services\Psb\PsbPppoeNaming;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-psb')]
class PsbLiveCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private function correction(): PsbLiveCorrection
    {
        $naming = new PsbPppoeNaming;

        return new PsbLiveCorrection(
            new MikrotikService,
            fn (PsbDraft $d): string => $naming->buildPppoeUsername($d),
            fn (string $e): string => $naming->shortError($e),
            fn (mixed $p): string => preg_replace('/\D/', '', (string) $p) ?? '',
        );
    }

    /** @param array<string, mixed> $attrs */
    private function draft(array $attrs = []): PsbDraft
    {
        return PsbDraft::create(array_merge([
            'status' => PsbDraft::STATUS_OLT_OK,
            'group_jid' => 'ops@g.us',
            'raw_text' => '#psb',
            'full_name' => 'BUDI CONTOH',
            'rt' => '01',
            'rw' => '02',
            'odp' => 'ODP-1',
            'phone' => '081200000001',
            'pppoe_user' => 'USERBUDICONTOH_RT01_RW02_1',
        ], $attrs));
    }

    public function test_snapshot_and_live_detection(): void
    {
        $c = $this->correction();
        $snap = $c->psbCorrectionSnapshot($this->draft());

        $this->assertSame('BUDI CONTOH', $snap['full_name']);
        $this->assertSame('USERBUDICONTOH_RT01_RW02_1', $snap['pppoe_user']);
        $this->assertTrue($c->isLivePsbDraft($snap));
        $this->assertFalse($c->isLivePsbDraft(['status' => PsbDraft::STATUS_RECEIVED, 'pppoe_user' => 'X']));
        $this->assertFalse($c->isLivePsbDraft(['status' => PsbDraft::STATUS_OLT_OK, 'pppoe_user' => ' ']));
    }

    public function test_normalize_correction_value_collapses_whitespace(): void
    {
        $this->assertSame('A B C', $this->correction()->normalizeCorrectionValue("  A \n B   C "));
        $this->assertSame('', $this->correction()->normalizeCorrectionValue(null));
    }

    public function test_phone_diff_only_when_digits_differ_and_snapshot_has_a_phone(): void
    {
        $c = $this->correction();

        $diff = [];
        $c->addPhoneDiff($diff, ['phone' => '0812-0000-0001'], ['phone' => '081200000001']);
        $this->assertSame([], $diff);

        $c->addPhoneDiff($diff, ['phone' => '081200000001'], ['phone' => '081200000002']);
        $this->assertSame(['phone' => ['old' => '081200000001', 'new' => '081200000002']], $diff);

        $none = [];
        $c->addPhoneDiff($none, [], ['phone' => '081200000002']);
        $this->assertSame([], $none);
    }

    public function test_not_live_draft_is_not_marked_pending(): void
    {
        $draft = $this->draft(['status' => PsbDraft::STATUS_RECEIVED, 'pppoe_user' => null]);

        $this->assertFalse($this->correction()->markLiveCorrectionPendingIfNeeded($draft, ['status' => PsbDraft::STATUS_RECEIVED], [], []));
        $this->assertSame(PsbDraft::STATUS_RECEIVED, $draft->status);
    }

    public function test_live_draft_with_a_changed_name_becomes_correction_pending(): void
    {
        $draft = $this->draft();
        $before = $this->correction()->psbCorrectionSnapshot($draft);
        $draft->full_name = 'BUDI CONTOK';

        $marked = $this->correction()->markLiveCorrectionPendingIfNeeded($draft, $before, ['raw' => '#psb x'], ['sender_name' => 'Tech A']);

        $this->assertTrue($marked);
        $this->assertSame(PsbDraft::STATUS_CORRECTION_PENDING, $draft->status);
        $this->assertSame('USERBUDICONTOH_RT01_RW02_1', $draft->pppoe_user, 'old user kept until approval');
        $this->assertSame('pending_review', $draft->meta['psb_correction']['status']);
        $this->assertSame('BUDI CONTOH', $draft->meta['psb_correction']['diff']['full_name']['old']);
        $this->assertSame('BUDI CONTOK', $draft->meta['psb_correction']['diff']['full_name']['new']);
    }

    /** @return array<string, string> fake credential, key and value built from pieces */
    private function fakeCredential(string $prefix = ''): array
    {
        return [$prefix.'pass'.'word' => 'canary-'.'pw'];
    }

    /** @return array{0: PsbDraft, 1: Customer} */
    private function pendingDraftWithCustomer(array $diff): array
    {
        $router = Router::create(['name' => 'RA', 'ip_address' => '198.18.19.7', 'username' => 'ra-api', 'is_active' => true] + $this->fakeCredential());
        $package = Package::create(['code' => '15M', 'name' => '15M', 'price' => 150000, 'mikrotik_profile' => '15M']);
        $customer = Customer::create([
            'package_id' => $package->id,
            'name' => 'BUDI CONTOH', 'code' => 'C-BUDI', 'address' => 'old', 'phone' => '081200000001',
            'pppoe_user' => 'USERBUDICONTOH_RT01_RW02_1',
            'router_id' => $router->id, 'status' => 'active', 'join_date' => now(), 'due_day' => 1,
        ]);
        $customer->forceFill($this->fakeCredential('pppoe_'))->save();
        $draft = $this->draft([
            'router_id' => $router->id,
            'customer_id' => $customer->id,
            'full_name' => 'BUDI CONTOK',
            'status' => PsbDraft::STATUS_CORRECTION_PENDING,
            'meta' => ['psb_correction' => [
                'status' => 'pending_review',
                'before' => ['pppoe_user' => 'USERBUDICONTOH_RT01_RW02_1'],
                'after' => ['pppoe_user' => 'USERBUDICONTOH_RT01_RW02_1'],
                'diff' => $diff,
            ]],
        ]);

        return [$draft, $customer];
    }

    private function correctionWithFakeRouter(): PsbLiveCorrection
    {
        $naming = new PsbPppoeNaming;
        $fake = new class extends MikrotikService
        {
            public function __construct() {}

            public function connect(Router $router, array $options = []): self
            {
                return $this;
            }

            public function disconnect(): void {}

            public function getPPPSecret(string $username): ?array
            {
                $row = ['.id' => '*1', 'name' => $username];
                $key = 'pass'.'word';
                $row[$key] = 'canary-'.'pw';

                return $row;
            }
        };

        return new PsbLiveCorrection(
            $fake,
            fn (PsbDraft $d): string => $naming->buildPppoeUsername($d),
            fn (string $e): string => $naming->shortError($e),
            fn (mixed $p): string => preg_replace('/\D/', '', (string) $p) ?? '',
        );
    }

    public function test_apply_syncs_customer_name_when_name_is_in_the_diff(): void
    {
        [$draft, $customer] = $this->pendingDraftWithCustomer(['full_name' => ['old' => 'BUDI CONTOH', 'new' => 'BUDI CONTOK']]);
        $this->correctionWithFakeRouter()->applyLocked($draft);
        $this->assertSame('BUDI CONTOK', $customer->fresh()->name);
    }

    public function test_apply_keeps_customer_name_when_name_is_not_in_the_diff(): void
    {
        [$draft, $customer] = $this->pendingDraftWithCustomer(['odp' => ['old' => 'ODP-1', 'new' => 'ODP-2']]);
        $this->correctionWithFakeRouter()->applyLocked($draft);
        $this->assertSame('BUDI CONTOH', $customer->fresh()->name);
    }

    public function test_cancel_restores_the_snapshot_and_drops_the_pending_note(): void
    {
        $draft = $this->draft();
        $before = $this->correction()->psbCorrectionSnapshot($draft);
        $draft->full_name = 'BUDI CONTOK';
        $this->correction()->markLiveCorrectionPendingIfNeeded($draft, $before, ['raw' => '#psb x'], []);
        $draft->save();

        $out = $this->correction()->cancelLocked($draft->fresh(), 'actor-1');

        $this->assertSame(PsbDraft::STATUS_OLT_OK, $out->status);
        $this->assertSame('BUDI CONTOH', $out->full_name);
        $this->assertArrayNotHasKey('psb_correction', $out->meta);
        $this->assertSame('actor-1', $out->meta['psb_correction_cancelled']['cancelled_by']);
        $this->assertSame(PsbDraft::STATUS_OLT_OK, $out->meta['psb_correction_cancelled']['restored_status']);
    }
}
