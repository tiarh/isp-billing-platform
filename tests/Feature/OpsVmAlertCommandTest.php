<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\GuidedMigrationLookupService;
use App\Services\HostCpuProbe;
use App\Services\Ops\VmAlertEvaluator;
use App\Services\Ops\VmAlertService;
use App\Services\Report\ReportGroupRegistry;
use Illuminate\Cache\ArrayStore;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

#[Group('blocking-core')]
class OpsVmAlertCommandTest extends TestCase
{
    use RefreshDatabase;

    private const OPS_JID = '120363000000000000@g.us';

    private const PSB_JID = '120363000000000005@g.us';

    private const MIGRATION_JID = '120363000000000006@g.us';

    private HostCpuProbe $probe;

    private int $steal = 0;

    private int $idle = 0;

    private int $waStatus = 200;

    private bool $waFaked = false;

    private int $informCount = 1000;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-20 14:06:00');
        Queue::fake();
        config()->set('services.evolution_api.ops_alerts_enabled', true);
        config()->set('services.evolution_api.base_url', 'http://evolution.test');
        config()->set('services.evolution_api.api_key', 'test-evolution-key');
        config()->set('services.evolution_api.ops_group_id', self::OPS_JID);
        config()->set('services.evolution_api.delay_ms', 0);
        config()->set('ops.vm_alert.store', 'array');
        config()->set('ops.vm_alert.dry_run_sample_seconds', 0);
        config()->set('services.monitoring.admission_gate.operator_hold', false);
        Setting::set('psb_wa_group_ids', self::PSB_JID, 'text', 'wa');
        Setting::set(GuidedMigrationLookupService::ALLOWED_GROUP_JIDS, self::MIGRATION_JID, 'text', 'wa');

        $this->probe = new class extends HostCpuProbe
        {
            public ?string $stat = null;

            public function procStat(): ?string
            {
                return $this->stat;
            }
        };
        $this->app->instance(HostCpuProbe::class, $this->probe);
        Cache::forget('monitoring.genieacs.last_sync');
        Cache::store('array')->forget(VmAlertService::STATE_KEY);
        $this->fakeWa(200);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Evolution answers with this status from now on (the first Http::fake stays registered). */
    private function fakeWa(int $status): void
    {
        $this->waStatus = $status;
        if ($this->waFaked) {
            return;
        }
        $this->waFaked = true;
        Http::fake([
            'evolution.test/message/sendText/*' => fn () => Http::response(['key' => ['id' => 'abc']], $this->waStatus),
            '*' => Http::response([], 404),
        ]);
    }

    private function mode(string $mode): void
    {
        Setting::set(VmAlertService::SETTING_MODE, $mode, 'text', 'ops');
    }

    /** Texts Evolution accepted (failed attempts are not counted). @return list<string> */
    private function sentTexts(): array
    {
        return Http::recorded(static fn (Request $r, $response): bool => str_contains($r->url(), '/message/sendText/') && $response->successful())
            ->map(static fn (array $pair): string => (string) ($pair[0]->data()['text'] ?? ''))
            ->values()
            ->all();
    }

    private function setStat(float $stealPct): void
    {
        // every 10 minute slice adds 10000 ticks in total, `$stealPct` percent of them stolen
        $stolen = (int) round($stealPct * 100);
        $this->steal += $stolen;
        $this->idle += 10000 - $stolen;
        $this->probe->stat = "cpu  100 0 100 {$this->idle} 0 0 0 {$this->steal} 0 0\ncpu0 1 2 3 4 5 6 7 8 0 0\nintr 1\n";
    }

    /** @param  int  $agoMinutes  how long before now the devices informed (last_inform_at) */
    private function setInform(int $count, int $agoMinutes = 5): void
    {
        $this->informCount = $count;
        DB::table('genieacs_devices')->delete();
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['device_id' => 'dev-'.$i, 'last_inform_at' => now()->subMinutes($agoMinutes), 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('genieacs_devices')->insert($chunk);
        }
    }

    /**
     * One scheduler run 10 minutes after the previous one.
     *
     * @param  int  $snapshotAgeMinutes  age of the GenieACS snapshot at run time
     * @param  int  $informAgoMinutes  how long before now the devices informed
     */
    private function tick(float $stealPct, ?int $inform = null, int $snapshotAgeMinutes = 1, int $informAgoMinutes = 5): int
    {
        Carbon::setTestNow(now()->addMinutes(10));
        $this->setStat($stealPct);
        if ($inform !== null) {
            $this->setInform($inform, $informAgoMinutes);
        }
        Cache::put('monitoring.genieacs.last_sync', ['finished_at' => now()->subMinutes($snapshotAgeMinutes)->toIso8601String()], now()->addDay());

        return Artisan::call('ops:vm-alert');
    }

    /** @return array<string, mixed> */
    private function state(): array
    {
        return Cache::store('array')->get(VmAlertService::STATE_KEY) ?? [];
    }

    private function assertCleanText(string $text): void
    {
        $this->assertDoesNotMatchRegularExpression('/\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}/', $text, 'no IP');
        $this->assertDoesNotMatchRegularExpression('/\b08\d{8,}\b|\b62\d{9,}\b/', $text, 'no phone');
        $this->assertStringNotContainsString('App\\', $text);
        $this->assertStringNotContainsString('@g.us', $text);
    }

    // ------------------------------------------------------------------ modes

    public function test_default_mode_is_off_nothing_runs(): void
    {
        $this->setStat(0);
        $code = Artisan::call('ops:vm-alert');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('mode off', Artisan::output());
        Http::assertNothingSent();
        $this->assertSame([], $this->state());
        $this->assertSame('off', VmAlertService::currentMode());
    }

    public function test_log_mode_logs_the_exact_text_and_sends_nothing(): void
    {
        $this->mode('log');
        Log::spy();

        $this->tick(0);
        $this->tick(35);
        $this->tick(35);
        $this->tick(35);

        Http::assertNothingSent();
        $expected = "eBilling ops: server eBilling (VM102) lambat.\nCPU steal 35% selama 30 menit (batas 30%).\nBiasanya karena VM lain di Proxmox sedang berat.";
        Log::shouldHaveReceived('info')->withArgs(
            static fn ($message, $context = []): bool => $message === '[OpsVmAlert] would send' && ($context['text'] ?? null) === $expected
        )->once();
        Log::shouldHaveReceived('info')->withArgs(
            static fn ($message): bool => $message === '[OpsVmAlert] sample'
        )->times(4);
        $this->assertSame('alert', $this->state()['types']['steal']['status']);
        $this->assertSame('log', $this->state()['types']['steal']['sent_mode']);
        Queue::assertNothingPushed();
    }

    public function test_on_mode_sends_one_exact_message_to_the_ops_group_only(): void
    {
        $this->mode('on');
        $this->tick(0);
        $this->tick(35);
        $this->tick(35);
        $this->tick(35);

        $texts = $this->sentTexts();
        $this->assertCount(1, $texts);
        $this->assertSame(
            "eBilling ops: server eBilling (VM102) lambat.\nCPU steal 35% selama 30 menit (batas 30%).\nBiasanya karena VM lain di Proxmox sedang berat.",
            $texts[0]
        );
        $this->assertCleanText($texts[0]);

        Http::assertSent(static function (Request $r): bool {
            return str_contains($r->url(), '/message/sendText/')
                && ($r->data()['number'] ?? null) === self::OPS_JID
                && ! str_contains(json_encode($r->data()), self::PSB_JID)
                && ! str_contains($r->url(), '120363000000000005')
                && ! str_contains(json_encode($r->data()), self::MIGRATION_JID)
                && ! str_contains($r->url(), '120363000000000006');
        });
        $this->assertFalse(app(ReportGroupRegistry::class)->isPsbGroup(self::OPS_JID));

        // still bad: no second message
        $this->tick(35);
        $this->assertCount(1, $this->sentTexts());

        // recovery: one pulih
        $this->tick(24);
        $this->tick(24);
        $this->tick(24);
        $texts = $this->sentTexts();
        $this->assertCount(2, $texts);
        $this->assertSame('eBilling ops: VM102 pulih. CPU steal 24% (30 menit terakhir).', $texts[1]);

        $this->tick(24);
        $this->assertCount(2, $this->sentTexts());
        Queue::assertNothingPushed();
    }

    public function test_steal_and_inform_in_the_same_run_become_one_message(): void
    {
        $this->mode('on');
        $this->setInform(1000);
        $this->tick(0, 1000);
        for ($i = 0; $i < 7; $i++) {
            $this->tick(0, 1000);
        }
        $this->tick(35, 1000);
        $this->tick(35, 800);
        $this->tick(35, 800);

        $texts = $this->sentTexts();
        $this->assertCount(1, $texts);
        $this->assertStringContainsString('CPU steal 35%', $texts[0]);
        $this->assertStringContainsString('800 modem lapor ≤30 menit, normal ±1000 (turun 20%).', $texts[0]);
        $this->assertCleanText($texts[0]);
    }

    public function test_wa_failure_is_retried_next_run_and_sent_once(): void
    {
        $this->mode('on');
        $this->fakeWa(500);
        $this->tick(0);
        $this->tick(35);
        $this->tick(35);
        $this->tick(35);
        $this->assertTrue($this->state()['types']['steal']['pending']);
        $this->assertFalse($this->state()['types']['steal']['sent']);

        $this->fakeWa(200);
        $this->tick(35);
        $this->assertCount(1, $this->sentTexts());
        $this->assertTrue($this->state()['types']['steal']['sent']);

        $this->tick(35);
        $this->assertCount(1, $this->sentTexts());
    }

    public function test_stale_snapshot_sends_its_own_message(): void
    {
        $this->mode('on');
        $this->setStat(0);
        Artisan::call('ops:vm-alert'); // starts the clock at 14:06
        for ($i = 0; $i < 7; $i++) {
            Carbon::setTestNow(now()->addMinutes(10));
            $this->setStat(0);
            Cache::put('monitoring.genieacs.last_sync', ['finished_at' => '2026-10-20T14:00:00+07:00'], now()->addDay());
            Artisan::call('ops:vm-alert');
        }

        $texts = $this->sentTexts();
        $this->assertCount(1, $texts);
        $this->assertSame(
            "eBilling ops: data GenieACS tidak update sejak 14:00 WIB (>60 menit).\nSync gagal atau tertahan; jumlah modem belum bisa dicek.",
            $texts[0]
        );
        $this->assertCleanText($texts[0]);

        // fresh snapshot again: one pulih through the real send path
        $this->tick(0);
        $texts = $this->sentTexts();
        $this->assertCount(2, $texts);
        $this->assertSame('eBilling ops: data GenieACS update lagi.', $texts[1]);
        $this->tick(0);
        $this->assertCount(2, $this->sentTexts());
    }

    public function test_inform_drop_and_pulih_texts_go_through_the_real_send_path(): void
    {
        $this->mode('on');
        for ($i = 0; $i < 9; $i++) {
            $this->tick(0, 1000);
        }
        $this->tick(0, 800);
        $this->assertSame([], $this->sentTexts());
        $this->tick(0, 800);

        $texts = $this->sentTexts();
        $this->assertCount(1, $texts);
        $this->assertSame(
            "eBilling ops: modem yang lapor ke GenieACS turun.\n800 modem lapor ≤30 menit, normal ±1000 (turun 20%).\nBisa karena listrik padam, jaringan putus, atau GenieACS bermasalah.",
            $texts[0]
        );
        $this->assertCleanText($texts[0]);

        $this->tick(0, 1000);
        $this->tick(0, 1000);
        $texts = $this->sentTexts();
        $this->assertCount(2, $texts);
        $this->assertSame('eBilling ops: modem lapor GenieACS normal lagi (1000, normal ±1000).', $texts[1]);
        $this->assertCleanText($texts[1]);
    }

    public function test_old_snapshot_is_not_mistaken_for_a_modem_drop(): void
    {
        $this->mode('on');
        for ($i = 0; $i < 9; $i++) {
            $this->tick(0, 1000);
        }

        // two delayed syncs: the snapshot is 25 minutes old; its devices informed 33 minutes before now
        $this->tick(0, 1000, 25, 33);
        $this->tick(0, 1000, 25, 33);

        $this->assertSame('ok', $this->state()['types']['inform']['status']);
        $this->assertSame(0, $this->state()['types']['inform']['bad']);
        $raw = $this->state()['raw'];
        $this->assertSame(1000, (int) end($raw)[1]);
        Http::assertNothingSent();
    }

    public function test_alert_and_pulih_of_the_same_run_are_one_message(): void
    {
        $this->mode('on');
        $evaluator = new VmAlertEvaluator((array) config('ops.vm_alert'));
        $now = now()->timestamp;
        $state = $evaluator->newState($now - 7200);
        $state['last_run_at'] = $now - 600;
        $state['types']['steal']['recover_pending'] = true;
        $state['types']['steal']['recover_value'] = 24;
        $state['types']['stale']['status'] = 'alert';
        $state['types']['stale']['pending'] = true;
        $state['types']['stale']['since'] = $now - 3600;
        $state['types']['stale']['value'] = ['since' => null];
        Cache::store('array')->put(VmAlertService::STATE_KEY, $state, now()->addDay());

        $this->setStat(0);
        Artisan::call('ops:vm-alert');

        $texts = $this->sentTexts();
        $this->assertCount(1, $texts);
        $this->assertSame(
            "eBilling ops: data GenieACS tidak update (>60 menit).\nSync gagal atau tertahan; jumlah modem belum bisa dicek.\n\neBilling ops: VM102 pulih. CPU steal 24% (30 menit terakhir).",
            $texts[0]
        );
        $this->assertFalse($this->state()['types']['steal']['recover_pending']);
        $this->assertFalse($this->state()['types']['stale']['pending']);
    }

    public function test_nothing_is_sent_when_the_state_cannot_be_written(): void
    {
        $this->mode('on');
        $store = new class extends ArrayStore
        {
            public bool $failPuts = false;

            public function put($key, $value, $seconds)
            {
                return $this->failPuts ? false : parent::put($key, $value, $seconds);
            }
        };
        Cache::extend('ops_flaky', fn () => Cache::repository($store));
        config()->set('cache.stores.ops_flaky', ['driver' => 'ops_flaky']);
        config()->set('ops.vm_alert.store', 'ops_flaky');

        $this->tick(0);
        $this->tick(35);
        $this->tick(35);

        $store->failPuts = true;
        $this->tick(35); // would alert, but the state cannot be saved first
        Artisan::call('ops:vm-alert');
        $this->assertSame([], $this->sentTexts(), 'no WA when the state write fails (no repeat every run)');

        $store->failPuts = false;
        Artisan::call('ops:vm-alert');
        $this->assertCount(1, $this->sentTexts());
        Artisan::call('ops:vm-alert');
        $this->assertCount(1, $this->sentTexts());
    }

    public function test_nothing_is_decided_or_sent_when_the_state_cannot_be_read(): void
    {
        $this->mode('on');
        Cache::extend('ops_broken', function () {
            throw new \RuntimeException('store down');
        });
        config()->set('cache.stores.ops_broken', ['driver' => 'ops_broken']);
        config()->set('ops.vm_alert.store', 'ops_broken');

        $this->setStat(0);
        $this->assertSame(0, Artisan::call('ops:vm-alert'));

        $this->assertStringContainsString('state store unavailable', Artisan::output());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_schedule_entry_runs_only_in_log_and_on_mode(): void
    {
        config()->set('services.ebilling_scheduler.mode', 'monitoring');
        $schedule = new Schedule;
        $this->app->instance(Schedule::class, $schedule);
        ScheduleFacade::clearResolvedInstance(Schedule::class);
        require base_path('routes/console.php');
        $event = collect($schedule->events())->first(fn ($e) => $e->description === 'Ops: VM102 steal / GenieACS inform alert');
        $this->assertNotNull($event);

        $this->assertFalse($event->filtersPass($this->app));
        $this->mode('log');
        $this->assertTrue($event->filtersPass($this->app));
        $this->mode('on');
        $this->assertTrue($event->filtersPass($this->app));
        $this->mode('off');
        $this->assertFalse($event->filtersPass($this->app));
        $this->mode('banana');
        $this->assertFalse($event->filtersPass($this->app));
    }

    public function test_operator_hold_silences_the_stale_alert(): void
    {
        $this->mode('on');
        config()->set('services.monitoring.admission_gate.operator_hold', true);
        $this->setStat(0);
        Artisan::call('ops:vm-alert');
        for ($i = 0; $i < 8; $i++) {
            Carbon::setTestNow(now()->addMinutes(10));
            $this->setStat(0);
            Cache::put('monitoring.genieacs.last_sync', ['finished_at' => '2026-10-20T14:00:00+07:00'], now()->addDay());
            Artisan::call('ops:vm-alert');
        }

        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------- dry-run

    public function test_dry_run_writes_and_sends_nothing_in_every_mode(): void
    {
        foreach (['off', 'log', 'on'] as $mode) {
            $this->mode($mode);
            $this->setInform(120);
            Cache::put('monitoring.genieacs.last_sync', ['finished_at' => now()->subMinutes(2)->toIso8601String()], now()->addDay());
            $this->setStat(0);
            $before = $this->state();
            $devices = DB::table('genieacs_devices')->count();
            $settings = Setting::query()->count();
            $activity = Activity::query()->count();

            $code = Artisan::call('ops:vm-alert', ['--dry-run' => true]);
            $out = Artisan::output();

            $this->assertSame(0, $code);
            $this->assertStringContainsString('DRY RUN', $out);
            $this->assertStringContainsString('inform_le_30m=120', $out);
            $this->assertStringContainsString('steal_short_pct=', $out);
            $this->assertStringContainsString('contoh teks stale', $out);
            $this->assertSame($before, $this->state());
            $this->assertSame($devices, DB::table('genieacs_devices')->count());
            $this->assertSame($settings, Setting::query()->count());
            $this->assertSame($activity, Activity::query()->count());
        }

        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_status_is_read_only(): void
    {
        $this->mode('log');
        $this->tick(0);
        $before = $this->state();

        Artisan::call('ops:vm-alert', ['--status' => true]);
        $out = Artisan::output();

        $this->assertStringContainsString('mode=log', $out);
        $this->assertStringContainsString('steal: status=ok', $out);
        $this->assertSame($before, $this->state());
    }

    // --------------------------------------------------------------- set-mode

    public function test_set_mode_writes_setting_and_activity_and_refuses_unknown(): void
    {
        $this->assertSame(0, Artisan::call('ops:vm-alert', ['--set-mode' => 'log', '--actor' => 'owner-via-claude']));
        $this->assertSame('log', (string) Setting::get('ops_vm_alert_mode'));

        $row = Activity::query()->where('event', 'ops_vm_alert_mode_changed')->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertSame('owner-via-claude', $row->properties['actor']);
        $this->assertSame('off', $row->properties['old']);
        $this->assertSame('log', $row->properties['new']);

        $this->assertSame(1, Artisan::call('ops:vm-alert', ['--set-mode' => 'foo']));
        $this->assertSame('log', (string) Setting::get('ops_vm_alert_mode'));
        $this->assertSame(1, Activity::query()->where('event', 'ops_vm_alert_mode_changed')->count());
    }

    public function test_never_dispatches_jobs_on_any_path(): void
    {
        $this->mode('on');
        $this->tick(0);
        $this->tick(35);
        Artisan::call('ops:vm-alert', ['--dry-run' => true]);
        Artisan::call('ops:vm-alert', ['--status' => true]);
        Artisan::call('ops:vm-alert', ['--set-mode' => 'off']);

        Queue::assertNothingPushed();
    }
}
