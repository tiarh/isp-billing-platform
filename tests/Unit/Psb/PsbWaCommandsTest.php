<?php

namespace Tests\Unit\Psb;

use App\Models\PsbDraft;
use App\Services\EvolutionApiService;
use App\Services\KtpOcrService;
use App\Services\MikrotikService;
use App\Services\Psb\PsbDraftIntake;
use App\Services\PsbDocumentGateService;
use App\Services\PsbMessageParser;
use App\Services\PsbOrchestratorService;
use App\Services\PsbWaMessages;
use App\Services\RadiusUserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * PsbWaCommands (PSB refactor PR 4): the no-draft and ambiguous replies, and
 * proof that the facade overrides (reply, processDraft, hasMinPhoto,
 * readVerifiedPppoePassword) still win inside the extracted commands.
 */
#[Group('blocking-psb')]
class PsbWaCommandsTest extends TestCase
{
    use RefreshDatabase;

    private const GROUP = 'ops@g.us';

    private function host(): WaCommandsHost
    {
        return new WaCommandsHost(
            new EvolutionApiService,
            new MikrotikService,
            new PsbMessageParser,
            new PsbWaMessages,
            new KtpOcrService,
            new RadiusUserService,
            app(PsbDocumentGateService::class),
        );
    }

    private function draft(string $status, string $name = 'BUDI CONTOH'): PsbDraft
    {
        return PsbDraft::create([
            'status' => $status,
            'group_jid' => self::GROUP,
            'sender_jid' => '6281200000011@s.whatsapp.net',
            'raw_text' => '#psb',
            'full_name' => $name,
            'sn' => 'ZTEGC0000001',
        ]);
    }

    public function test_cek_without_a_draft_replies_once_and_returns_an_ignored_draft(): void
    {
        $host = $this->host();

        $result = $host->cek(['group_jid' => self::GROUP], []);

        $this->assertSame('ignored', $result->status);
        $this->assertSame([(new PsbWaMessages)->noDraftWaiting()], $host->replies);
    }

    public function test_cek_with_an_unknown_id_says_the_draft_was_not_found(): void
    {
        $host = $this->host();

        $result = $host->cek(['group_jid' => self::GROUP], ['draft_id' => 987654]);

        $this->assertSame('ignored', $result->status);
        $this->assertSame([(new PsbWaMessages)->draftNotFound(987654)], $host->replies);
    }

    public function test_sn_command_with_several_open_drafts_and_no_id_is_ambiguous(): void
    {
        $this->draft(PsbDraft::STATUS_WAITING_SN, 'BUDI CONTOH');
        $this->draft(PsbDraft::STATUS_WAITING_SN, 'SITI CONTOH');
        $host = $this->host();

        $result = $host->sn(['group_jid' => self::GROUP], ['sn' => 'ZTEGC0000009']);

        $this->assertSame('ambiguous_sn', $result->status);
        $this->assertCount(1, $host->replies);
        $this->assertStringContainsString('#psb sn ZTEGC0000009 #ID', $host->replies[0]);
        $this->assertSame([], $host->processed);
    }

    private function finishedHtbDraft(): PsbDraft
    {
        $d = $this->draft(PsbDraft::STATUS_SECRET_OK, 'DEWI LESTARI');
        $d->forceFill(['meta' => ['htb_complete' => true]])->save();

        return $d;
    }

    public function test_vlan_without_id_and_several_open_drafts_asks_for_the_id(): void
    {
        $a = $this->draft(PsbDraft::STATUS_FAILED, 'BUDI CONTOH');
        $b = $this->draft(PsbDraft::STATUS_FAILED, 'SITI CONTOH');
        $host = $this->host();

        $result = $host->vlan(['group_jid' => self::GROUP], ['vlan_value' => 100]);

        $this->assertSame('ambiguous_cmd', $result->status);
        $this->assertSame(['Ada beberapa draft. Tulis #ID, contoh: #psb vlan 100 #812'], $host->replies);
        $this->assertSame([], $host->processed);
        $this->assertNull($a->fresh()->vlan);
        $this->assertNull($b->fresh()->vlan);
        $this->assertSame(PsbDraft::STATUS_FAILED, $a->fresh()->status);

        $host2 = $this->host();
        $host2->router(['group_jid' => self::GROUP], ['router_alias' => 'ra']);
        $this->assertSame(['Ada beberapa draft. Tulis #ID, contoh: #psb vlan 100 #812'], $host2->replies);
        $this->assertSame([], $host2->processed);
    }

    public function test_vlan_without_id_and_one_open_draft_updates_that_draft(): void
    {
        $this->finishedHtbDraft();
        $one = $this->draft(PsbDraft::STATUS_FAILED, 'BUDI CONTOH');
        $host = $this->host();

        $host->vlan(['group_jid' => self::GROUP], ['vlan_value' => 100]);

        $this->assertSame(100, (int) $one->fresh()->vlan);
        $this->assertSame([$one->id], $host->processed);
    }

    public function test_id_from_another_group_is_not_found_and_leaves_the_row_alone(): void
    {
        $other = $this->draft(PsbDraft::STATUS_FAILED, 'BUDI CONTOH');
        $other->update(['group_jid' => 'other@g.us']);
        $host = $this->host();

        $host->router(['group_jid' => self::GROUP], ['router_alias' => 'ra', 'draft_id' => $other->id]);
        $host->vlan(['group_jid' => self::GROUP], ['vlan_value' => 100, 'draft_id' => $other->id]);

        $notFound = (new PsbWaMessages)->draftNotFound((int) $other->id);
        $this->assertSame([$notFound, $notFound], $host->replies);
        $this->assertSame([], $host->processed);
        $this->assertNull($other->fresh()->vlan);
        $this->assertSame(PsbDraft::STATUS_FAILED, $other->fresh()->status);
    }

    public function test_router_and_vlan_refuse_a_finished_htb_draft(): void
    {
        $htb = $this->finishedHtbDraft();
        $host = $this->host();

        $host->router(['group_jid' => self::GROUP], ['router_alias' => 'ra', 'draft_id' => $htb->id]);
        $host->vlan(['group_jid' => self::GROUP], ['vlan_value' => 100, 'draft_id' => $htb->id]);
        $host->sn(['group_jid' => self::GROUP], ['sn' => 'ZTEGC0000009', 'draft_id' => $htb->id]);

        $msg = (new PsbWaMessages)->draftNotReprocessable((int) $htb->id, PsbDraft::STATUS_SECRET_OK);
        $this->assertSame([$msg, $msg, $msg], $host->replies);
        $this->assertSame([], $host->processed);
        $fresh = $htb->fresh();
        $this->assertSame(PsbDraft::STATUS_SECRET_OK, $fresh->status);
        $this->assertSame('ZTEGC0000001', $fresh->sn);
        $this->assertNull($fresh->vlan);
    }

    public function test_lanjut_uses_the_host_has_min_photo_and_process_draft_overrides(): void
    {
        $draft = $this->draft(PsbDraft::STATUS_WAITING_SN);
        $host = $this->host();

        // Override says "no photo": the command must block, not process.
        $host->minPhoto = false;
        $blocked = $host->lanjut(['group_jid' => self::GROUP], ['draft_id' => $draft->id]);
        $this->assertSame([], $host->processed);
        $this->assertSame(PsbDraft::STATUS_RECEIVED, $blocked->status);
        $this->assertTrue((bool) ($blocked->fresh()->meta['waiting_photo'] ?? false));

        // Override says "photo ok": the command must go through the host's processDraft.
        $host->minPhoto = true;
        $host->lanjut(['group_jid' => self::GROUP], ['draft_id' => $draft->id]);
        $this->assertSame([$draft->id], $host->processed);
    }

    public function test_resend_reads_the_credential_through_the_host_override(): void
    {
        $draft = $this->draft(PsbDraft::STATUS_OLT_OK);
        $host = $this->host();

        $host->resend(['group_jid' => self::GROUP], ['draft_id' => $draft->id]);

        $this->assertSame([$draft->id], $host->credentialReads);
        $this->assertCount(1, $host->replies);
    }

    public function test_lanjut_query_prefers_the_draft_id_then_the_sn_then_the_newest(): void
    {
        $first = $this->draft(PsbDraft::STATUS_WAITING_SN, 'BUDI CONTOH');
        $second = $this->draft(PsbDraft::STATUS_WAITING_SN, 'SITI CONTOH');
        $second->update(['sn' => 'ZTEGC0000002']);
        $applied = $this->draft(PsbDraft::STATUS_APPLIED, 'DEWI LESTARI');

        $intake = new PsbDraftIntake(fn (): bool => false, fn (): array => []);

        $this->assertSame($first->id, $intake->findLanjutDraft(self::GROUP, null, $first->id)?->id);
        $this->assertSame($first->id, $intake->findLanjutDraft(self::GROUP, 'ZTEGC0000001', null)?->id);
        $this->assertSame($second->id, $intake->findLanjutDraft(self::GROUP, 'ZTEGC0000002', null)?->id);
        $this->assertSame($second->id, $intake->findLanjutDraft(self::GROUP, null, null)?->id);
        $this->assertNull($intake->findLanjutDraft(self::GROUP, null, $applied->id));
    }
}

class WaCommandsHost extends PsbOrchestratorService
{
    /** @var list<string> */
    public array $replies = [];

    /** @var list<int> */
    public array $processed = [];

    /** @var list<int> */
    public array $credentialReads = [];

    public bool $minPhoto = true;

    public function reply(string $groupJid, string $text, array $mentionJids = []): bool
    {
        $this->replies[] = $text;

        return true;
    }

    public function processDraft(PsbDraft $draft): PsbDraft
    {
        $this->processed[] = (int) $draft->id;

        return $draft;
    }

    protected function hasMinPhoto(PsbDraft $draft): bool
    {
        return $this->minPhoto;
    }

    protected function readVerifiedPppoePassword(PsbDraft $draft): ?string
    {
        $this->credentialReads[] = (int) $draft->id;

        return null;
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $parsed */
    public function cek(array $payload, array $parsed): PsbDraft
    {
        return $this->say($payload, '#psb cek', $parsed);
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $parsed */
    public function sn(array $payload, array $parsed): PsbDraft
    {
        return $this->say($payload, '#psb sn '.$parsed['sn'], $parsed);
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $parsed */
    public function router(array $payload, array $parsed): PsbDraft
    {
        return $this->say($payload, '#psb router '.$parsed['router_alias'], $parsed);
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $parsed */
    public function vlan(array $payload, array $parsed): PsbDraft
    {
        return $this->say($payload, '#psb vlan '.$parsed['vlan_value'], $parsed);
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $parsed */
    public function lanjut(array $payload, array $parsed): PsbDraft
    {
        return $this->say($payload, '#psb lanjut', $parsed);
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $parsed */
    public function resend(array $payload, array $parsed): PsbDraft
    {
        return $this->say($payload, '#psb kirim lagi', $parsed);
    }

    /**
     * Drives the command through handleInbound with real command text, so the
     * private seams() wiring stays under test.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $parsed
     */
    private function say(array $payload, string $text, array $parsed): PsbDraft
    {
        if (! empty($parsed['draft_id'])) {
            $text .= ' #'.$parsed['draft_id'];
        }

        return $this->handleInbound(array_merge($payload, ['text' => $text]));
    }
}
