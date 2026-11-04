<?php

namespace Tests\Unit\Psb;

use App\Models\Olt;
use App\Models\PsbDraft;
use App\Models\Router;
use App\Services\MikrotikService;
use App\Services\Psb\PsbAreaResolver;
use App\Services\Psb\PsbOltBusyMarker;
use App\Services\Psb\PsbPppoeIpAllocator;
use App\Services\Psb\PsbUncfgMatcher;
use App\Services\PsbOrchestratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** Class-level checks for the PR 5 provisioning helpers (the flows are locked by the PR 0 goldens). */
#[Group('blocking-psb')]
class PsbProvisioningHelpersTest extends TestCase
{
    use RefreshDatabase;

    private function allocator(): PsbPppoeIpAllocator
    {
        return new PsbPppoeIpAllocator(new MikrotikService);
    }

    public function test_next_free_remote_ip_walks_up_then_wraps_and_returns_null_when_full(): void
    {
        $a = $this->allocator();

        $this->assertSame('198.18.23.6', $a->nextFreeRemoteIp('198.18.23.5', ['198.18.23.5' => true]));
        $this->assertSame('198.18.23.8', $a->nextFreeRemoteIp('198.18.23.5', ['198.18.23.6' => true, '198.18.23.7' => true]));

        $used = [];
        for ($i = 6; $i <= 254; $i++) {
            $used['198.18.23.'.$i] = true;
        }
        $this->assertSame('198.18.23.2', $a->nextFreeRemoteIp('198.18.23.5', $used));

        for ($i = 2; $i <= 5; $i++) {
            $used['198.18.23.'.$i] = true;
        }
        $this->assertNull($a->nextFreeRemoteIp('198.18.23.5', $used));
        $this->assertNull($a->nextFreeRemoteIp('bogus', []));
    }

    public function test_ip_helpers_validate_and_normalize(): void
    {
        $a = $this->allocator();

        $this->assertSame('198.18.5.3', $a->normalizeIp(' 198.18.5.3 '));
        $this->assertSame('', $a->normalizeIp('198.18.5'));
        $this->assertSame('198.18.5', $a->ipPrefix24('198.18.5.3'));
        $this->assertNull($a->ipPrefix24('nope'));
        $this->assertTrue($a->isPppoeStaticAddressValid('198.18.5.3'));
        $this->assertTrue($a->isPppoeStaticAddressValid('10.1'));
        $this->assertFalse($a->isPppoeStaticAddressValid(''));
        $this->assertFalse($a->isPppoeStaticAddressValid('a.b'));
    }

    public function test_mark_remote_ip_used_updates_the_45s_cache_map(): void
    {
        // Only the id is read, so the router is not saved (no device fields needed).
        $router = new Router;
        $router->id = 513;
        $this->allocator()->markRemoteIpUsed($router, '198.18.8.7', '198.18.8.1');

        $map = Cache::get('psb:ip-map:v1:'.$router->id);
        $this->assertTrue($map['used']['198.18.8.7']);
        $this->assertSame('198.18.8.7', $map['last_remote']);
        $this->assertSame('198.18.8.1', $map['local']);
    }

    public function test_heuristic_ip_source_is_saved_only_when_the_caller_is_not_connected(): void
    {
        $router = new Router;
        $router->id = 513;
        $a = $this->allocator();
        $a->markRemoteIpUsed($router, '198.18.8.7', '198.18.8.1'); // warm map: no device call

        $connected = PsbDraft::create(['status' => PsbDraft::STATUS_RECEIVED, 'group_jid' => 'ops@g.us', 'raw_text' => '#psb']);
        $a->suggestLiveIp($router, true, $connected);
        $this->assertSame('heuristic_dominant24', $connected->meta['ip_source']);
        $this->assertTrue($connected->isDirty('meta'), 'connected caller: not saved here');
        $this->assertNull($connected->fresh()->meta);

        $free = PsbDraft::create(['status' => PsbDraft::STATUS_RECEIVED, 'group_jid' => 'ops@g.us', 'raw_text' => '#psb']);
        $a->suggestLiveIp($router, false, $free);
        $this->assertFalse($free->isDirty('meta'));
        $this->assertSame('heuristic_dominant24', $free->fresh()->meta['ip_source']);
    }

    public function test_busy_chain_starts_fresh_continues_live_and_expires(): void
    {
        $m = new PsbOltBusyMarker;
        $now = Carbon::parse('2026-10-28 10:00:00');

        $fresh = $m->chain([], $now);
        $this->assertFalse($fresh['live']);
        $this->assertSame(0, $fresh['done']);
        $this->assertSame(1, $fresh['next']);
        $this->assertSame($now->toIso8601String(), $fresh['first']->toIso8601String());

        $live = $m->chain(['first_at' => $now->copy()->subMinutes(5)->toIso8601String(), 'attempt' => 2], $now);
        $this->assertTrue($live['live']);
        $this->assertSame(2, $live['done']);
        $this->assertSame(3, $live['next']);

        $old = $m->chain(['first_at' => $now->copy()->subMinutes(PsbOrchestratorService::OLT_BUSY_CHAIN_MINUTES + 1)->toIso8601String(), 'attempt' => 4], $now);
        $this->assertFalse($old['live']);
        $this->assertSame(1, $old['next']);

        $bad = $m->chain(['first_at' => 'not a date', 'attempt' => 4], $now);
        $this->assertFalse($bad['live']);
        $this->assertSame(1, $bad['next']);
    }

    public function test_backoff_follows_the_table_with_0_to_15_s_jitter(): void
    {
        $m = new PsbOltBusyMarker;
        foreach (PsbOrchestratorService::OLT_BUSY_BACKOFF as $i => $base) {
            $d = $m->backoffSeconds($i + 1);
            $this->assertGreaterThanOrEqual($base, $d);
            $this->assertLessThanOrEqual($base + 15, $d);
        }
        $table = PsbOrchestratorService::OLT_BUSY_BACKOFF;
        $this->assertGreaterThanOrEqual($table[count($table) - 1], $m->backoffSeconds(99));
    }

    public function test_marker_resumable_stale_token_and_clear(): void
    {
        $m = new PsbOltBusyMarker;
        $draft = new PsbDraft([
            'status' => PsbDraft::STATUS_SECRET_OK,
            'last_error' => 'OLT antri: menunggu giliran OLT',
            'meta' => ['olt_busy_resume' => ['token' => 'tok-1'], 'keep' => 1],
        ]);

        $this->assertTrue($m->oltBusyResumable($draft));
        $this->assertFalse($m->oltResumeTokenStale($draft, null));
        $this->assertFalse($m->oltResumeTokenStale($draft, 'tok-1'));
        $this->assertTrue($m->oltResumeTokenStale($draft, 'tok-2'));

        $draft->last_error = 'lain';
        $this->assertTrue($m->oltResumeTokenStale($draft, 'tok-1'));

        $m->clearOltBusyMarker($draft);
        $this->assertSame([], $m->oltBusyMarker($draft));
        $this->assertSame(1, $draft->meta['keep']);
    }

    public function test_pon_port_key_normalizes_and_uncfg_matcher_picks_the_draft_sn(): void
    {
        $x = new PsbUncfgMatcher;
        $this->assertSame('1/2/5', $x->normalizePonPortKey('gpon-olt_1/2/5'));
        $this->assertSame('1/2/5', $x->normalizePonPortKey(' 1/2/5:7 '));

        $olt = new Olt(['name' => 'OLT TEST', 'code' => 'OLT-T1', 'vendor' => 'zte_c300', 'management_ip' => '198.18.4.2', 'router_id' => null, 'area_id' => null]);
        $olt->id = 1;
        $olt->save();
        $draft = new PsbDraft(['sn' => 'ZTEGC0000001']);

        $hit = $x->match($draft, $olt, [['sn' => 'ZTEGC0000001', 'port' => '', 'onu_id' => 3]]);
        $this->assertTrue($hit['found']);
        $this->assertSame('ZTEGC0000001', $hit['sn']);
        $this->assertSame(3, $hit['onu_id']);

        $miss = $x->match($draft, $olt, [['sn' => 'ZTEGC0000009', 'port' => '', 'onu_id' => 1], ['sn' => 'ZTEGC0000008', 'port' => '']]);
        $this->assertFalse($miss['found']);
        $this->assertStringContainsString('2 ONU uncfg lain', $miss['message']);

        $one = $x->match($draft, $olt, [['sn' => 'ZTEGC0000009', 'port' => '']]);
        $this->assertTrue($one['sn_mismatch']);
    }

    public function test_missing_required_lists_empty_fields(): void
    {
        $r = new PsbAreaResolver;
        $miss = $r->missingRequired(new PsbDraft(['full_name' => 'SITI CONTOH']));

        $this->assertNotContains('Nama lengkap', $miss);
        $this->assertContains('ODP', $miss);
        $this->assertContains('SN', $miss);
        $this->assertContains('Router (contoh: ra / krian / bumiayu / rdg)', $miss);
        $this->assertContains('Paket (contoh: 200M / STARTLITE / 100M)', $miss);
        $this->assertSame('rafullolt', $r->areaKey('RA-Full OLT'));
        $this->assertFalse($r->isRaOltDraft(null));
        $this->assertTrue($r->isRaOltDraft(new PsbDraft(['site_profile_key' => 'ra_full_olt'])));
        $this->assertNull($r->areaIdFromOlt(null));
    }
}
