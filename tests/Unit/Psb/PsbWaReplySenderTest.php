<?php

namespace Tests\Unit\Psb;

use App\Models\PsbDraft;
use App\Services\EvolutionApiService;
use App\Services\Psb\PsbConfigResolver;
use App\Services\Psb\PsbWaReplySender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-psb')]
class PsbWaReplySenderTest extends TestCase
{
    use RefreshDatabase;

    private function sender(array|\Exception $response): PsbWaReplySender
    {
        $evo = Mockery::mock(EvolutionApiService::class);
        $evo->shouldReceive('forChannel')->with('ops')->andReturnSelf();
        $evo->shouldReceive('sendToGroup')->andReturnUsing(function () use ($response) {
            if ($response instanceof \Exception) {
                throw $response;
            }

            return $response;
        });

        return new PsbWaReplySender($evo, new PsbConfigResolver);
    }

    public function test_successful_send_records_health_cache(): void
    {
        Cache::put('psb_wa:last_reply_error', 'Old');

        $ok = $this->sender(['success' => true])->send('120363000000000001@g.us', 'halo');

        $this->assertTrue($ok);
        $this->assertSame('OK', Cache::get('psb_wa:last_reply_status'));
        $this->assertNotNull(Cache::get('psb_wa:last_reply_at'));
        $this->assertNull(Cache::get('psb_wa:last_reply_error'));
    }

    public function test_failed_send_returns_false_records_error_and_frees_dedupe_key(): void
    {
        $sender = $this->sender(['success' => false, 'error' => 'down']);
        $group = '120363000000000001@g.us';

        $this->assertTrue($sender->allowReplyMessage($group, 'halo'));
        $this->assertFalse($sender->send($group, 'halo'));

        $this->assertSame('error', Cache::get('psb_wa:last_reply_status'));
        $this->assertSame('Exception', Cache::get('psb_wa:last_reply_error'));
        $this->assertTrue($sender->allowReplyMessage($group, 'halo'), 'failed send forgets the dedupe key so a retry can go out');
    }

    public function test_exception_is_caught_and_reported_by_class_name(): void
    {
        $sender = $this->sender(new \RuntimeException('boom'));

        $this->assertFalse($sender->send('120363000000000001@g.us', 'halo'));
        $this->assertSame('RuntimeException', Cache::get('psb_wa:last_reply_error'));
    }

    public function test_dedupe_blocks_an_identical_reply_and_key_ignores_mention_order_and_group_format(): void
    {
        $sender = $this->sender(['success' => true]);

        $this->assertTrue($sender->allowReplyMessage('120363000000000001@g.us', ' halo ', ['b@s.whatsapp.net', 'a@s.whatsapp.net']));
        $this->assertFalse($sender->allowReplyMessage('120363000000000001', 'halo', ['a@s.whatsapp.net', 'b@s.whatsapp.net']));
        $this->assertTrue($sender->allowReplyMessage('120363000000000001@g.us', 'lain'));

        $key = $sender->replyDedupeKey('120363000000000001@g.us', 'halo');
        $this->assertStringStartsWith('psb:reply-dedupe:', $key);
        $this->assertSame(strlen('psb:reply-dedupe:') + 32, strlen($key));
    }

    public function test_mention_prefix_for_draft(): void
    {
        $sender = $this->sender(['success' => true]);

        $this->assertSame(['', []], $sender->mentionPrefixForDraft(new PsbDraft));

        $withPhone = new PsbDraft(['sender_name' => 'Teknisi Satu', 'sender_jid' => '6281200000011@s.whatsapp.net']);
        $this->assertSame(["Teknisi Satu\n", ['6281200000011@s.whatsapp.net']], $sender->mentionPrefixForDraft($withPhone));

        $lidOnly = new PsbDraft(['sender_jid' => '99887766@lid']);
        $this->assertSame(["teknisi\n", ['99887766@lid']], $sender->mentionPrefixForDraft($lidOnly));

        $pn = new PsbDraft(['sender_jid' => '99887766@lid', 'meta' => ['sender_jid_pn' => '6281200000012@c.us']]);
        $this->assertSame(["6281200000012\n", ['6281200000012@s.whatsapp.net']], $sender->mentionPrefixForDraft($pn));
    }
}
