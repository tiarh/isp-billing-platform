<?php

namespace Tests\Unit\Psb;

use App\Models\PsbDraft;
use App\Services\Psb\PsbDraftIntake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-psb')]
class PsbDraftIntakeTest extends TestCase
{
    use RefreshDatabase;

    private const GROUP = 'ops@g.us';

    private const SENDER = '6281200000011@s.whatsapp.net';

    private function intake(): PsbDraftIntake
    {
        return new PsbDraftIntake(
            fn (array $before): bool => in_array($before['status'] ?? '', [
                PsbDraft::STATUS_SECRET_OK, PsbDraft::STATUS_OLT_OK, PsbDraft::STATUS_APPLIED, PsbDraft::STATUS_CORRECTION_PENDING,
            ], true) && trim((string) ($before['pppoe_user'] ?? '')) !== '',
            fn (PsbDraft $d): array => ['status' => $d->status, 'pppoe_user' => $d->pppoe_user],
        );
    }

    /** @param array<string, mixed> $attrs */
    private function draft(string $status, array $attrs = []): PsbDraft
    {
        return PsbDraft::create(array_merge([
            'status' => $status,
            'group_jid' => self::GROUP,
            'sender_jid' => self::SENDER,
            'raw_text' => '#psb',
            'full_name' => 'BUDI CONTOH',
            'phone' => '081200000001',
            'sn' => 'ZTEGC0000001',
            'pppoe_user' => in_array($status, [PsbDraft::STATUS_OLT_OK, PsbDraft::STATUS_SECRET_OK], true) ? 'USERBUDICONTOH_RT01_RW01_1' : null,
        ], $attrs));
    }

    /** @return array<string, array{0: string, 1: ?string, 2: ?string, 3: ?string, 4: bool}> */
    public static function sameNameCases(): array
    {
        // [status, SN in message, phone in message, phone on draft, refused?]
        $cases = [];
        foreach ([PsbDraft::STATUS_SECRET_OK, PsbDraft::STATUS_OLT_OK, PsbDraft::STATUS_APPLIED, PsbDraft::STATUS_CORRECTION_PENDING] as $status) {
            $cases["$status: other SN, other phone"] = [$status, 'ZTEGC0000002', '081200000099', '081200000001', true];
            $cases["$status: other SN, same phone"] = [$status, 'ZTEGC0000002', '081200000001', '081200000001', false];
            $cases["$status: other SN, no phone in message"] = [$status, 'ZTEGC0000002', null, '081200000001', false];
            $cases["$status: other SN, no phone on draft"] = [$status, 'ZTEGC0000002', '081200000099', null, false];
            $cases["$status: same SN, other phone"] = [$status, 'ZTEGC0000001', '081200000099', '081200000001', false];
            $cases["$status: no SN, other phone"] = [$status, null, '081200000099', '081200000001', false];
        }
        $cases['received (open): never refused'] = [PsbDraft::STATUS_RECEIVED, 'ZTEGC0000002', '081200000099', '081200000001', false];
        $cases['+62 form equals 0 form'] = [PsbDraft::STATUS_OLT_OK, 'ZTEGC0000002', '+62 812-0000-0001', '081200000001', false];

        return $cases;
    }

    #[DataProvider('sameNameCases')]
    public function test_same_name_other_sn_and_phone_on_a_live_draft_is_refused(string $status, ?string $sn, ?string $phone, ?string $draftPhone, bool $refused): void
    {
        $draft = $this->draft($status, ['phone' => $draftPhone]);

        $this->assertSame($refused, $this->intake()->isSameNameOtherLiveCustomer($draft, [
            'full_name' => 'Budi  Contoh',
            'sn' => $sn,
            'phone' => $phone,
        ]));
    }

    public function test_different_name_is_never_a_same_name_refusal(): void
    {
        $draft = $this->draft(PsbDraft::STATUS_OLT_OK);

        $this->assertFalse($this->intake()->isSameNameOtherLiveCustomer($draft, [
            'full_name' => 'SITI SAMPEL', 'sn' => 'ZTEGC0000002', 'phone' => '081200000099',
        ]));
    }

    public function test_open_drafts_for_cmd_counts_open_drafts_only(): void
    {
        $this->draft(PsbDraft::STATUS_FAILED);
        $this->draft(PsbDraft::STATUS_WAITING_SN);
        $this->draft(PsbDraft::STATUS_CORRECTION_PENDING);
        $this->draft(PsbDraft::STATUS_OLT_OK);
        $htb = $this->draft(PsbDraft::STATUS_SECRET_OK);
        $htb->forceFill(['meta' => ['htb_complete' => true]])->save();
        $this->draft(PsbDraft::STATUS_FAILED, ['group_jid' => 'other@g.us']);

        $this->assertCount(2, $this->intake()->openDraftsForCmd(self::GROUP));
    }

    public function test_exact_name_merges_even_into_a_live_draft(): void
    {
        $live = $this->draft(PsbDraft::STATUS_OLT_OK, ['sn' => 'ZTEGC0000001']);

        $found = $this->intake()->findMergeableDraft(self::GROUP, self::SENDER, ['full_name' => 'Budi  Contoh', 'sn' => '']);

        $this->assertSame($live->id, $found?->id);
    }

    public function test_fuzzy_name_merges_an_open_draft_but_not_a_live_one(): void
    {
        $open = $this->draft(PsbDraft::STATUS_RECEIVED, ['sn' => null]);

        $this->assertSame($open->id, $this->intake()->findMergeableDraft(self::GROUP, self::SENDER, ['full_name' => 'BUDI CONTOK'])?->id);

        $open->update(['status' => PsbDraft::STATUS_OLT_OK, 'pppoe_user' => 'USERBUDICONTOH_RT01_RW01_1']);
        $this->assertNull($this->intake()->findMergeableDraft(self::GROUP, self::SENDER, ['full_name' => 'BUDI CONTOK']));
    }

    public function test_sn_match_wins_over_name_and_name_less_message_takes_last_open_draft(): void
    {
        $open = $this->draft(PsbDraft::STATUS_WAITING_SN, ['sn' => 'ZTEGC0000009', 'full_name' => 'SITI SAMPEL']);

        $this->assertSame($open->id, $this->intake()->findMergeableDraft(self::GROUP, self::SENDER, ['full_name' => 'OTHER NAME', 'sn' => 'ztegc0000009'])?->id);
        $this->assertSame($open->id, $this->intake()->findMergeableDraft(self::GROUP, self::SENDER, ['full_name' => '', 'sn' => ''])?->id);
        $this->assertNull($this->intake()->findMergeableDraft(self::GROUP, self::SENDER, ['full_name' => 'OTHER NAME', 'sn' => '']));
        $this->assertNull($this->intake()->findMergeableDraft('', self::SENDER, ['full_name' => 'SITI SAMPEL']));
    }

    public function test_cancelled_and_other_group_drafts_are_not_candidates(): void
    {
        $this->draft(PsbDraft::STATUS_CANCELLED);
        $this->draft(PsbDraft::STATUS_RECEIVED, ['group_jid' => 'other@g.us']);

        $this->assertNull($this->intake()->findMergeableDraft(self::GROUP, self::SENDER, ['full_name' => 'BUDI CONTOH']));
        $this->assertCount(0, $this->intake()->mergeCandidates(self::GROUP, self::SENDER));
    }

    public function test_sn_reuse_conflict_only_for_a_live_draft_and_a_clearly_other_customer(): void
    {
        $live = $this->draft(PsbDraft::STATUS_OLT_OK);
        $intake = $this->intake();

        $this->assertSame($live->id, $intake->findSnReuseConflict(self::GROUP, self::SENDER, [
            'sn' => 'ZTEGC0000001', 'full_name' => 'SITI SAMPEL', 'phone' => '081200000002',
        ])?->id);
        // same customer (typo) or no phone signal: not a conflict
        $this->assertNull($intake->findSnReuseConflict(self::GROUP, self::SENDER, [
            'sn' => 'ZTEGC0000001', 'full_name' => 'BUDI CONTOK', 'phone' => '081200000002',
        ]));
        $this->assertNull($intake->findSnReuseConflict(self::GROUP, self::SENDER, [
            'sn' => 'ZTEGC0000001', 'full_name' => 'SITI SAMPEL', 'phone' => '',
        ]));
        $this->assertNull($intake->findSnReuseConflict(self::GROUP, self::SENDER, ['sn' => '', 'full_name' => 'SITI SAMPEL', 'phone' => '081200000002']));

        $live->update(['status' => PsbDraft::STATUS_RECEIVED, 'pppoe_user' => null]);
        $this->assertNull($intake->findSnReuseConflict(self::GROUP, self::SENDER, [
            'sn' => 'ZTEGC0000001', 'full_name' => 'SITI SAMPEL', 'phone' => '081200000002',
        ]));
    }

    public function test_normalize_phone(): void
    {
        $intake = $this->intake();

        $this->assertSame('081200000001', $intake->normalizePsbPhone('+62 812-0000-0001'));
        $this->assertSame('', $intake->normalizePsbPhone(null));
    }

    public function test_apply_parsed_keeps_old_values_for_empty_fields_and_resets_provisioning_on_sn_change(): void
    {
        $draft = $this->draft(PsbDraft::STATUS_OLT_OK, ['rt' => '01', 'odp' => 'ODP-1', 'olt_at' => now()]);

        $out = $this->intake()->applyParsedToDraft($draft, ['sender_name' => 'Tech A'], [
            'raw' => '#psb baru', 'full_name' => 'BUDI CONTOH', 'rt' => '', 'odp' => 'ODP-2', 'sn' => 'ztegc0000002',
        ], 'WAMSG-1', 'ZTEGC0000001');

        $this->assertSame($draft, $out);
        $this->assertSame('01', $out->rt);
        $this->assertSame('ODP-2', $out->odp);
        $this->assertSame('ZTEGC0000002', $out->sn);
        $this->assertSame('WAMSG-1', $out->message_id);
        $this->assertSame(PsbDraft::STATUS_SECRET_OK, $out->status);
        $this->assertNull($out->olt_at);
    }

    public function test_resolve_draft_in_group_and_find_draft_for_cmd(): void
    {
        $failed = $this->draft(PsbDraft::STATUS_FAILED);
        $applied = $this->draft(PsbDraft::STATUS_APPLIED);
        $intake = $this->intake();

        $this->assertSame($failed->id, $intake->resolveDraftInGroup(self::GROUP, null)?->id, 'default statuses skip applied');
        $this->assertSame($applied->id, $intake->resolveDraftInGroup(self::GROUP, null, allowAnyStatus: true)?->id);
        $this->assertNull($intake->resolveDraftInGroup(self::GROUP, $applied->id, false, [PsbDraft::STATUS_FAILED]));
        $this->assertSame($applied->id, $intake->resolveDraftInGroup(self::GROUP, $applied->id)?->id);
        $this->assertNull($intake->resolveDraftInGroup('other@g.us', $applied->id));

        $this->assertSame($failed->id, $intake->findDraftForCmd(self::GROUP, [])?->id);
        $this->assertNull($intake->findDraftForCmd('other@g.us', ['draft_id' => $applied->id]), 'a #ID from another group is never used');
    }
}
