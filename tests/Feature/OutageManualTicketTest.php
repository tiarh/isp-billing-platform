<?php

namespace Tests\Feature;

use App\Jobs\SendOutageTicketReportJob;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Olt;
use App\Models\OutageDiagnosis;
use App\Models\OutageSignal;
use App\Models\Package;
use App\Models\Router;
use App\Models\Setting;
use App\Models\SupportTicket;
use App\Models\TicketActivity;
use App\Models\User;
use App\Models\WaReportDelivery;
use App\Services\Outage\Diagnosis\PrecisionReport;
use App\Services\Outage\OutageManualTicketService;
use App\Services\Outage\OutageSettings;
use App\Services\Outage\OutageSettingsWriter;
use App\Services\Outage\OutageShadowReporter;
use App\Services\Outage\OutageTicketMatcher;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon as IlluminateCarbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Canary;
use Tests\TestCase;

/**
 * Manual "Buat tiket" of Deteksi Gangguan: one ticket per episode, only ONE Report-group WA message, never a
 * customer / technician / ticket-group message, nothing on the PSB / migration / billing paths.
 */
#[Group('blocking-core')]
class OutageManualTicketTest extends TestCase
{
    use RefreshDatabase;

    private const R = '120363-skynetreport@g.us';

    private const P = '120363-activation@g.us';

    private const TICKET_GROUP = '120363-ticketgroup@g.us';

    private const ALERT_GROUP = '120363-alertops@g.us';

    private const LOG_GROUP = '120363-shadowlog@g.us';

    private int $seq = 0;

    private Olt $olt;

    /** 05:00 UTC = 12:00 WIB. */
    private string $base = '2026-10-15 05:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse($this->base.' UTC'));
        $this->olt = Olt::create(['name' => 'OLT RA', 'code' => 'MT-1', 'vendor' => 'zte_c300']);
        config([
            'outage.olt_ids' => [$this->olt->id],
            'services.evolution_api.base_url' => 'http://evolution.test',
            'services.evolution_api.api_key' => 'canary-pw',
            'queue.default' => 'sync',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function set(string $key, mixed $value): void
    {
        Setting::set($key, $value);
        Setting::flushRequestMemo();
    }

    private function reportGroup(): void
    {
        $this->set('report_wa_group_ids', [self::R]);
    }

    private function decoyGroups(): void
    {
        $this->set('psb_wa_group_ids', [self::P]);
        $this->set('wa_ticket_group_jid', self::TICKET_GROUP);
        $this->set('wa_report_group_jid', self::ALERT_GROUP);
        $this->set('outage_shadow_log_jid', self::LOG_GROUP);
    }

    private function customer(array $o = []): Customer
    {
        $package = Package::firstOrCreate(['code' => 'MT-PKG'], ['name' => 'Manual pkg', 'price' => 100000]);
        $n = ++$this->seq;

        return Customer::create(array_merge([
            'code' => 'RDG'.(100 + $n),
            'name' => 'Budi '.$n,
            'phone' => '0813555'.$n,
            'address' => 'Jl. Rahasia '.$n,
            'pppoe_user' => 'mt.'.$n,
            'package_id' => $package->id,
            'status' => 'active',
            'olt_id' => $this->olt->id,
            'olt_port_label' => '1/2/2:'.$n,
        ], $o));
    }

    private function signal(array $o = []): OutageSignal
    {
        $n = ++$this->seq;
        $base = Carbon::parse($this->base.' UTC');

        return OutageSignal::create(array_merge([
            'signal_type' => 'single_los',
            'scope_key' => 'customer:'.$n,
            'olt_id' => $this->olt->id,
            'pon_port' => '1/2/2',
            'state' => 'confirmed',
            'opened_at' => $base->copy()->subMinutes(40),
            'confirmed_at' => $base->copy()->subMinutes(10),
            'last_seen_at' => $base,
            'affected_count' => 1,
            'would_action' => 'ticket_single',
            'mode_at_confirm' => 'shadow',
            'payload' => ['cause' => 'los'],
        ], $o));
    }

    private function singleEpisode(array $customer = [], array $signal = []): array
    {
        $c = $this->customer($customer);
        $s = $this->signal(array_merge(['customer_id' => $c->id, 'affected_customer_ids' => [$c->id]], $signal));

        return [$c, $s];
    }

    /** @return array{0: OutageSignal, 1: list<Customer>} */
    private function massEpisode(int $n = 6): array
    {
        $cs = [];
        for ($i = 0; $i < $n; $i++) {
            $cs[] = $this->customer();
        }
        $s = $this->signal([
            'signal_type' => 'mass_los',
            'pon_port' => '1/3/3',
            'customer_id' => null,
            'affected_count' => $n,
            'affected_customer_ids' => array_map(fn (Customer $c) => $c->id, $cs),
            'payload' => ['pon_total' => 38, 'segment_estimate_m' => ['from_m' => 1200, 'to_m' => 1800]],
        ]);

        return [$s, $cs];
    }

    private function noc(): User
    {
        return User::factory()->create(['role' => 'noc', 'name' => 'Andi']);
    }

    private function superadmin(): User
    {
        return User::factory()->create(['role' => 'superadmin']);
    }

    private function postTicket(User $u, OutageSignal $s, array $body = [])
    {
        return $this->actingAs($u)->postJson(route('api.monitoring.outage-shadow.ticket', $s), $body);
    }

    private function fakeEvolution(): void
    {
        Http::fake(['*' => Http::response(['key' => ['id' => 'WAMID-1']], 201)]);
    }

    // ------------------------------------------------------------------ 1. fields

    public function test_single_episode_creates_one_open_unassigned_incident_ticket_with_evidence(): void
    {
        Queue::fake();
        [$c, $s] = $this->singleEpisode(['area_id' => Area::create(['name' => 'Randuagung', 'code' => 'RDG'])->id]);
        $before = (array) DB::table('outage_signals')->where('id', $s->id)->first();

        $res = $this->postTicket($this->noc(), $s, ['note' => 'Cek ONU dulu', 'priority' => 'urgent'])->assertCreated();
        $res->assertJsonPath('created', true)->assertJsonPath('message', 'Tiket #'.$res->json('ticket.id').' dibuat.');

        $t = SupportTicket::query()->findOrFail($res->json('ticket.id'));
        $this->assertSame('incident', $t->type);
        $this->assertSame('open', $t->status);
        $this->assertNull($t->assigned_to);
        $this->assertSame($c->id, (int) $t->customer_id);
        $this->assertSame('urgent', $t->priority);
        $this->assertSame((int) $c->area_id, (int) $t->area_id);
        $this->assertStringStartsWith('[Deteksi] LOS individual — '.$c->code.' '.$c->name, (string) $t->gangguan);
        $this->assertStringContainsString('Kejadian #'.$s->id, (string) $t->keterangan);
        $this->assertStringContainsString('Catatan NOC: Cek ONU dulu', (string) $t->keterangan);
        $this->assertStringContainsString('Jaringan: OLT RA, PON 1/2/2, ONU 1/2/2:', (string) $t->keterangan);

        $meta = $t->meta;
        $this->assertSame('deteksi_manual', $meta['source']);
        $this->assertSame($s->id, $meta['outage_signal_id']);
        $this->assertSame('single_los', $meta['signal_type']);
        $this->assertSame('never', $meta['customer_wa']);
        $this->assertSame('monitoring_deteksi', $meta['created_via']);
        $this->assertSame('confirmed', $meta['episode_snapshot']['state_at_click']);
        $this->assertSame('ticket_single', $meta['episode_snapshot']['would_action']);
        $this->assertArrayNotHasKey('auto_ticket', $meta);
        $this->assertArrayNotHasKey('monitoring_alert_dedupe_key', $meta);

        $this->assertSame($t->id, (int) OutageSignal::query()->findOrFail($s->id)->ticket_id);
        $this->assertTrue(TicketActivity::query()->where('ticket_id', $t->id)->where('description', 'Tiket dibuat dari Deteksi Gangguan (kejadian #'.$s->id.')')->exists());
        $this->assertSame(1, Activity::query()->where('log_name', 'outage')->where('event', 'manual_ticket_created')->count());

        // the episode changed only by the ticket link
        $after = (array) DB::table('outage_signals')->where('id', $s->id)->first();
        unset($before['updated_at'], $after['updated_at']);
        $this->assertSame(['ticket_id'], array_keys(array_diff_assoc($after, $before)));
    }

    // ------------------------------------------------------------------ 2. queue: only the report job

    public function test_only_the_report_job_is_pushed_and_no_customer_psb_migration_or_billing_job(): void
    {
        Queue::fake();
        Notification::fake();
        $this->reportGroup();
        [, $s] = $this->singleEpisode();

        $this->postTicket($this->noc(), $s)->assertCreated();

        Queue::assertPushed(SendOutageTicketReportJob::class, 1);
        Queue::assertPushedOn('wa', SendOutageTicketReportJob::class);
        foreach (glob(app_path('Jobs/*.php')) ?: [] as $file) {
            $class = 'App\\Jobs\\'.basename($file, '.php');
            if ($class !== SendOutageTicketReportJob::class) {
                Queue::assertNotPushed($class);
            }
        }
        Notification::assertNothingSent();
        $this->assertSame('wa', (new SendOutageTicketReportJob(1, 'x'))->queue);
    }

    // ------------------------------------------------------------------ 3. exactly one WA, report group only

    public function test_exactly_one_message_to_the_report_group_and_nothing_else(): void
    {
        $this->fakeEvolution();
        $this->reportGroup();
        $this->decoyGroups();
        [$c, $s] = $this->singleEpisode();
        $psbBefore = DB::table('settings')->where('key', 'like', 'psb%')->orderBy('key')->get()->all();

        $id = $this->postTicket($this->noc(), $s)->assertCreated()->json('ticket.id');

        Http::assertSentCount(1);
        Http::assertSent(function ($req) use ($c) {
            $text = (string) ($req['text'] ?? '');

            return str_contains($req->url(), '/message/sendText/')
                && $req['number'] === self::R
                && str_contains($text, 'TIKET GANGGUAN DARI DETEKSI')
                && str_contains($text, 'Pelanggan: '.$c->code.' - '.$c->name)
                && str_contains($text, 'Belum ada teknisi. Pelanggan tidak dikirimi pesan.')
                && str_contains($text, 'Dibuat oleh: Andi (NOC)')
                && ! str_contains($text, (string) $c->phone)
                && ! str_contains($text, 'Rahasia');
        });
        Http::assertNotSent(fn ($req) => in_array($req['number'] ?? '', [self::P, self::TICKET_GROUP, self::ALERT_GROUP, self::LOG_GROUP], true));

        $row = WaReportDelivery::query()->where('report_key', 'deteksi_ticket:'.$id)->sole();
        $this->assertSame('sent', $row->status);
        $this->assertSame(self::R, $row->group_jid);
        $this->assertEquals($psbBefore, DB::table('settings')->where('key', 'like', 'psb%')->orderBy('key')->get()->all());
    }

    public function test_report_message_snapshot_for_a_single_episode(): void
    {
        $this->fakeEvolution();
        $this->reportGroup();
        [$c, $s] = $this->singleEpisode(['code' => 'RDG196', 'name' => 'Budi', 'olt_port_label' => '1/2/2:7'], ['payload' => ['cause' => 'los']]);

        $id = $this->postTicket($this->noc(), $s)->assertCreated()->json('ticket.id');
        $t = SupportTicket::query()->findOrFail($id);

        $expected = implode("\n", [
            'TIKET GANGGUAN DARI DETEKSI',
            'Tiket #'.$id.' ('.$t->code.')',
            'Jenis: LOS individual',
            'Pelanggan: RDG196 - Budi',
            'Jaringan: OLT RA, PON 1/2/2, ONU 1/2/2:7',
            'Mulai: 15/10 11:20 WIB',
            'Dugaan sebab: Kabel/LOS',
            'Dibuat oleh: Andi (NOC)',
            'Belum ada teknisi. Pelanggan tidak dikirimi pesan.',
            'Detail: '.rtrim((string) config('app.url'), '/').'/tickets/'.$id,
        ]);
        Http::assertSent(fn ($req) => ($req['text'] ?? null) === $expected);
    }

    // ------------------------------------------------------------------ 4. idempotent

    public function test_double_post_double_job_and_meta_repair_never_duplicate(): void
    {
        $this->fakeEvolution();
        $this->reportGroup();
        [, $s] = $this->singleEpisode();
        $noc = $this->noc();

        $first = $this->postTicket($noc, $s)->assertCreated();
        $second = $this->postTicket($noc, $s)->assertOk();
        $second->assertJsonPath('created', false)->assertJsonPath('ticket.id', $first->json('ticket.id'))
            ->assertJsonPath('message', 'Tiket sudah ada: #'.$first->json('ticket.id').'.');
        $this->assertSame(1, SupportTicket::query()->count());
        Http::assertSentCount(1);

        // the same job again: the ledger already holds a sent row
        SendOutageTicketReportJob::dispatchSync((int) $first->json('ticket.id'), 'TIKET GANGGUAN DARI DETEKSI');
        Http::assertSentCount(1);
        $this->assertSame(1, WaReportDelivery::query()->count());

        // meta repair: ticket saved, link lost
        DB::table('outage_signals')->where('id', $s->id)->update(['ticket_id' => null]);
        $third = $this->postTicket($noc, $s)->assertOk();
        $third->assertJsonPath('created', false);
        $this->assertSame((int) $first->json('ticket.id'), (int) OutageSignal::query()->findOrFail($s->id)->ticket_id);
        $this->assertSame(1, SupportTicket::query()->count());
        Http::assertSentCount(1);
    }

    // ------------------------------------------------------------------ 5. open customer ticket

    public function test_customer_with_an_open_ticket_gets_409_and_nothing_is_written(): void
    {
        Queue::fake();
        [$c, $s] = $this->singleEpisode();
        $open = SupportTicket::create(['customer_id' => $c->id, 'customer_name' => $c->name, 'type' => 'request', 'status' => 'in_progress', 'priority' => 'normal', 'gangguan' => 'Minta pindah']);

        $this->postTicket($this->noc(), $s)->assertStatus(409)->assertJsonPath('message', 'Pelanggan ini sudah punya tiket terbuka #'.$open->id.'.');

        $this->assertSame(1, SupportTicket::query()->count());
        $this->assertNull(OutageSignal::query()->findOrFail($s->id)->ticket_id);
        Queue::assertNothingPushed();

        // a closed ticket does not block
        $open->update(['status' => 'closed']);
        $this->postTicket($this->noc(), $s)->assertCreated();
    }

    // ------------------------------------------------------------------ 6. mass / router / olt_wide

    public function test_mass_episode_makes_one_customerless_ticket_with_the_affected_list(): void
    {
        $this->fakeEvolution();
        $this->reportGroup();
        [$s, $cs] = $this->massEpisode(6);

        $id = $this->postTicket($this->noc(), $s)->assertCreated()->json('ticket.id');
        $t = SupportTicket::query()->findOrFail($id);

        $this->assertNull($t->customer_id);
        $this->assertNull($t->customer_phone);
        $this->assertSame('urgent', $t->priority);
        $this->assertSame('Gangguan massal — OLT RA PON 1/3/3 (6 pelanggan)', $t->customer_name);
        $this->assertStringStartsWith('[Deteksi] LOS massal OLT RA PON 1/3/3 — 6 pelanggan', (string) $t->gangguan);
        $this->assertStringContainsString('Terdampak (6 pelanggan):', (string) $t->keterangan);
        $this->assertStringContainsString('- '.$cs[0]->code.' '.$cs[0]->name, (string) $t->keterangan);
        $this->assertStringContainsString('Perkiraan titik putus: 1,2–1,8 km dari OLT', (string) $t->keterangan);
        $this->assertCount(6, $t->meta['affected_customer_ids']);
        $this->assertSame('mass_los', $t->meta['signal_type']);

        Http::assertSentCount(1);
        Http::assertSent(fn ($req) => str_starts_with((string) $req['text'], 'TIKET GANGGUAN MASSAL DARI DETEKSI')
            && str_contains((string) $req['text'], 'Jaringan: OLT RA, PON 1/3/3 (6 dari 38 pelanggan)')
            && str_contains((string) $req['text'], 'Terdampak: '.$cs[0]->code)
            && str_contains((string) $req['text'], 'Perkiraan titik putus: 1,2–1,8 km dari OLT')
            && ! str_contains((string) $req['text'], 'Pelanggan: '));
    }

    public function test_long_mass_list_is_capped_with_dan_n_lagi(): void
    {
        Queue::fake();
        [$s] = $this->massEpisode(34);

        $id = $this->postTicket($this->noc(), $s)->assertCreated()->json('ticket.id');
        $k = (string) SupportTicket::query()->findOrFail($id)->keterangan;

        $this->assertStringContainsString('dan 4 lagi', $k);
        $this->assertLessThanOrEqual(3000, mb_strlen($k));
    }

    public function test_router_level_pppoe_and_olt_wide_are_mass_tickets(): void
    {
        Queue::fake();
        $router = Router::create(['name' => 'RTR-KRIAN', 'ip_address' => '198.18.6.1', 'username' => 'admin', 'password' => Canary::of('pw'), 'port' => 8728, 'is_active' => true]);
        $cs = [$this->customer(), $this->customer(), $this->customer()];
        $ppp = $this->signal([
            'signal_type' => 'pppoe_down', 'scope_key' => 'router:'.$router->id.':ppp', 'customer_id' => null, 'pon_port' => null,
            'affected_count' => 3, 'affected_customer_ids' => array_map(fn (Customer $c) => $c->id, $cs),
            'payload' => ['router_id' => $router->id, 'level' => 'router'],
        ]);
        $wide = $this->signal(['signal_type' => 'olt_wide', 'scope_key' => 'olt:'.$this->olt->id, 'customer_id' => null, 'pon_port' => null, 'affected_count' => 40]);
        $noc = $this->noc();

        $a = SupportTicket::query()->findOrFail($this->postTicket($noc, $ppp)->assertCreated()->json('ticket.id'));
        $this->assertNull($a->customer_id);
        $this->assertSame('Router RTR-KRIAN: 3 pelanggan PPPoE putus', $a->customer_name);
        $this->assertSame('urgent', $a->priority);

        $b = SupportTicket::query()->findOrFail($this->postTicket($noc, $wide)->assertCreated()->json('ticket.id'));
        $this->assertNull($b->customer_id);
        $this->assertSame('Satu OLT: OLT RA', $b->customer_name);
        $this->assertSame('urgent', $b->priority);
    }

    public function test_customer_level_pppoe_down_and_weak_rx_are_single_tickets(): void
    {
        Queue::fake();
        $c1 = $this->customer();
        $ppp = $this->signal(['signal_type' => 'pppoe_down', 'customer_id' => $c1->id, 'payload' => ['level' => 'customer']]);
        $c2 = $this->customer();
        $rx = $this->signal(['signal_type' => 'weak_rx', 'customer_id' => $c2->id, 'payload' => ['rx_dbm' => -29.4]]);
        $noc = $this->noc();

        $a = SupportTicket::query()->findOrFail($this->postTicket($noc, $ppp)->assertCreated()->json('ticket.id'));
        $this->assertSame($c1->id, (int) $a->customer_id);
        $this->assertSame('high', $a->priority);

        $b = SupportTicket::query()->findOrFail($this->postTicket($noc, $rx)->assertCreated()->json('ticket.id'));
        $this->assertSame('normal', $b->priority);
        $this->assertStringContainsString('RX terakhir: -29,4 dBm', (string) $b->keterangan);
    }

    // ------------------------------------------------------------------ 7. rejected

    public function test_dying_gasp_data_blind_closed_and_isolated_episodes_are_refused_without_writes(): void
    {
        Queue::fake();
        $this->reportGroup();
        $noc = $this->noc();
        $c = $this->customer();
        $iso = $this->customer(['status' => 'isolated']);
        $cases = [
            'Jenis kejadian ini tidak bisa dibuatkan tiket.' => [
                $this->signal(['signal_type' => 'dying_gasp', 'customer_id' => $c->id]),
                $this->signal(['signal_type' => 'data_blind', 'customer_id' => null, 'payload' => ['reason' => 'router_offline']]),
            ],
            'Kejadian ini sudah selesai.' => [
                $this->signal(['customer_id' => $c->id, 'state' => 'resolved', 'resolved_at' => Carbon::parse($this->base.' UTC')]),
                $this->signal(['customer_id' => $c->id, 'state' => 'expired']),
            ],
            'Pelanggan ini sudah tidak aktif atau sedang diisolir.' => [
                $this->signal(['customer_id' => $iso->id]),
            ],
        ];

        foreach ($cases as $message => $signals) {
            foreach ($signals as $s) {
                $this->postTicket($noc, $s)->assertStatus(422)->assertJsonPath('message', $message);
                $this->actingAs($noc)->getJson(route('api.monitoring.outage-shadow.ticket-preview', $s))->assertStatus(422);
                $this->assertNull(OutageSignal::query()->findOrFail($s->id)->ticket_id);
            }
        }
        $this->assertSame(0, SupportTicket::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_validation_of_note_and_priority(): void
    {
        [, $s] = $this->singleEpisode();
        $noc = $this->noc();

        $this->postTicket($noc, $s, ['priority' => 'bogus'])->assertStatus(422);
        $this->postTicket($noc, $s, ['note' => str_repeat('x', 501)])->assertStatus(422);
        $this->assertSame(0, SupportTicket::query()->count());
    }

    // ------------------------------------------------------------------ 8. permissions

    public function test_permissions_roles_setting_and_flag(): void
    {
        Queue::fake();
        $scoped = User::factory()->create(['role' => 'admin']);
        $scoped->areas()->attach(Area::create(['name' => 'Scoped', 'code' => 'SCP']));
        [, $s] = $this->singleEpisode();

        $this->postJson(route('api.monitoring.outage-shadow.ticket', $s))->assertUnauthorized();
        foreach ([User::factory()->create(['role' => 'teknisi']), $scoped] as $denied) {
            $this->postTicket($denied, $s)->assertForbidden();
            $this->actingAs($denied)->getJson(route('api.monitoring.outage-shadow.ticket-preview', $s))->assertForbidden();
        }

        $admin = User::factory()->create(['role' => 'admin']);
        $this->set('outage_manual_ticket_roles', 'noc');
        $this->postTicket($admin, $s)->assertForbidden()->assertJsonPath('message', 'Anda tidak punya akses membuat tiket.');
        $this->actingAs($admin)->getJson(route('api.monitoring.outage-shadow.ticket-preview', $s))->assertForbidden();

        $this->set('outage_manual_ticket_enabled', '0');
        $this->postTicket($this->superadmin(), $s)->assertForbidden();
        $this->assertSame(0, SupportTicket::query()->count());

        $this->set('outage_manual_ticket_enabled', '1');
        $this->postTicket($this->superadmin(), $s)->assertCreated();
    }

    public function test_settings_defaults_and_writer_validation(): void
    {
        $this->assertTrue(OutageSettings::manualTicketEnabled());
        $this->assertSame(['admin', 'noc'], OutageSettings::manualTicketRoles());
        $this->assertTrue(OutageSettings::manualTicketAllowedTo('superadmin'));
        $this->assertTrue(OutageSettings::manualTicketAllowedTo('noc'));
        $this->assertFalse(OutageSettings::manualTicketAllowedTo('teknisi'));
        $this->assertFalse(OutageSettings::manualTicketAllowedTo(null));

        $w = new OutageSettingsWriter;
        $this->assertTrue($w->validate('outage_manual_ticket_roles', 'noc')['ok']);
        $this->assertFalse($w->validate('outage_manual_ticket_roles', 'teknisi')['ok']);
        $this->assertFalse($w->validate('outage_manual_ticket_roles', 'admin,superadmin')['ok']);
        $this->assertSame('1', $w->validate('outage_manual_ticket_enabled', 'on')['stored']);
        $this->assertTrue($w->set('outage_manual_ticket_roles', 'noc', 'test')['ok']);
        Setting::flushRequestMemo();
        $this->assertSame(['noc'], OutageSettings::manualTicketRoles());
    }

    // ------------------------------------------------------------------ 9. report group empty / overlap

    public function test_no_report_group_or_a_psb_overlap_creates_the_ticket_but_sends_nothing(): void
    {
        Queue::fake();
        [, $s] = $this->singleEpisode();
        $noc = $this->noc();

        $this->postTicket($noc, $s)->assertCreated();
        Queue::assertNothingPushed();

        [, $s2] = $this->singleEpisode();
        $this->set('report_wa_group_ids', [self::P]);
        $this->set('psb_wa_group_ids', [self::P]);
        $this->postTicket($noc, $s2)->assertCreated();
        Queue::assertNothingPushed();
        $this->assertSame(2, SupportTicket::query()->count());
        $this->assertSame(0, WaReportDelivery::query()->count());

        $this->actingAs($noc)->getJson(route('api.monitoring.outage-shadow.ticket-preview', $this->singleEpisode()[1]))
            ->assertOk()->assertJsonPath('data.report_group_set', false);
    }

    public function test_a_failed_send_keeps_the_ticket_and_is_not_sent_again_by_itself(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);
        $this->reportGroup();
        [, $s] = $this->singleEpisode();

        $id = $this->postTicket($this->noc(), $s)->assertCreated()->json('ticket.id');

        $row = WaReportDelivery::query()->where('report_key', 'deteksi_ticket:'.$id)->sole();
        $this->assertContains($row->status, ['failed', 'unknown']);
        $this->assertSame(1, (int) $row->attempts);
        $this->assertSame(1, SupportTicket::query()->count());

        // a second POST finds the ticket and does not queue another message
        $this->postTicket($this->noc(), $s)->assertOk()->assertJsonPath('created', false);
        $this->assertSame(1, (int) WaReportDelivery::query()->where('report_key', 'deteksi_ticket:'.$id)->sole()->attempts);
    }

    // ------------------------------------------------------------------ 10. detector keeps the link

    public function test_later_episode_saves_keep_the_ticket_link_and_the_detector_never_writes_it(): void
    {
        Queue::fake();
        [, $s] = $this->singleEpisode();
        $id = $this->postTicket($this->noc(), $s)->assertCreated()->json('ticket.id');

        $loaded = OutageSignal::query()->findOrFail($s->id);
        $loaded->flap_count = 2;
        $loaded->save();
        $loaded->state = 'resolved';
        $loaded->resolved_at = now();
        $loaded->save();

        $this->assertSame($id, (int) OutageSignal::query()->findOrFail($s->id)->ticket_id);
        $detector = (string) file_get_contents(base_path('app/Services/Outage/OutageDetector.php'));
        $this->assertStringNotContainsString("'ticket_id'", $detector);
    }

    // ------------------------------------------------------------------ 11. matcher / reporter

    public function test_manual_tickets_never_count_as_match_or_recall_and_get_their_own_report(): void
    {
        Queue::fake();
        [$c, $s] = $this->singleEpisode();
        $id = $this->postTicket($this->noc(), $s)->assertCreated()->json('ticket.id');
        $from = Carbon::parse($this->base.' UTC')->subDay();
        $to = Carbon::parse($this->base.' UTC')->addDay();

        $this->assertTrue(OutageTicketMatcher::isDetectionTicket(SupportTicket::query()->findOrFail($id)));
        (new OutageTicketMatcher)->run($from, $to);
        $this->assertNull(OutageSignal::query()->findOrFail($s->id)->matched_ticket_id);

        $report = (new OutageShadowReporter)->build($from, $to, []);
        $this->assertSame(0, $report['recall']['real_tickets']);

        $m = $report['manual_tickets']['single_los'];
        $this->assertSame(1, $m['shown']);
        $this->assertSame(1, $m['clicked']);
        $this->assertSame(1.0, $m['click_rate']);
        $this->assertSame(['confirmed' => 1], $m['state_at_click']);
        $this->assertSame(40.0, $m['median_open_to_click_min']);
        $this->assertSame(10.0, $m['median_confirm_to_click_min']);
        $this->assertSame(['real_fault' => 0, 'not_fault' => 0, 'unknown' => 0, 'still_open' => 1], $m['outcome']);
        $this->assertNull($m['proxy_precision']);

        SupportTicket::query()->whereKey($id)->update(['status' => 'closed', 'root_cause' => 'drop_los', 'resolved_at' => now()]);
        $m = (new OutageShadowReporter)->manualTickets($from, $to)['single_los'];
        $this->assertSame(['real_fault' => 1, 'not_fault' => 0, 'unknown' => 0, 'still_open' => 0], $m['outcome']);

        // a human ticket of another customer still counts for recall
        $other = $this->customer();
        SupportTicket::create(['customer_id' => $other->id, 'customer_name' => $other->name, 'type' => 'incident', 'status' => 'open', 'priority' => 'normal', 'gangguan' => 'Internet mati']);
        $this->assertSame(1, (new OutageShadowReporter)->recall($from, $to)['real_tickets']);
    }

    // ------------------------------------------------------------------ 12. PSB / migration untouched

    public function test_psb_and_migration_code_and_settings_are_untouched(): void
    {
        foreach (['app/Jobs/SendOutageTicketReportJob.php', 'app/Services/Outage/OutageManualTicketService.php', 'app/Services/Outage/OutageTicketEvidence.php', 'app/Http/Controllers/OutageManualTicketController.php'] as $file) {
            $src = (string) file_get_contents(base_path($file));
            foreach (['Psb', 'Migration', 'NotificationService', 'TicketController::', 'SendBillingWaJob', 'SendWaNotificationJob'] as $needle) {
                $this->assertStringNotContainsString($needle, $src, $file.' must not touch: '.$needle);
            }
        }

        Queue::fake();
        $this->reportGroup();
        $this->decoyGroups();
        $this->set('psb_recap_cmd_enabled', '1');
        $snapshot = fn () => DB::table('settings')->where(fn ($q) => $q->where('key', 'like', 'psb%')->orWhere('key', 'like', '%migration%'))->orderBy('key')->get()->all();
        $before = $snapshot();
        [, $s] = $this->singleEpisode();

        $this->postTicket($this->noc(), $s)->assertCreated();

        $this->assertEquals($before, $snapshot());
        Queue::assertPushed(SendOutageTicketReportJob::class, fn ($job) => $job->queue === 'wa');
    }

    // ------------------------------------------------------------------ 13. read model

    public function test_read_model_row_ticket_fields_and_constant_query_count(): void
    {
        $admin = $this->superadmin();
        // every tab: ticketed rows leave "Perlu dicek", this test reads their ticket fields
        $url = route('api.monitoring.outage-shadow', ['view' => 'semua']);

        [$c1, $s1] = $this->singleEpisode();
        [$c2, $s2] = $this->singleEpisode();
        $dg = $this->signal(['signal_type' => 'dying_gasp', 'customer_id' => $this->customer()->id]);
        $busy = $this->customer();
        SupportTicket::create(['customer_id' => $busy->id, 'customer_name' => $busy->name, 'type' => 'incident', 'status' => 'open', 'priority' => 'normal', 'gangguan' => 'x']);
        $s3 = $this->signal(['customer_id' => $busy->id, 'affected_customer_ids' => [$busy->id]]);

        $this->reportGroup();
        $this->fakeEvolution();
        $id = $this->postTicket($this->noc(), $s1)->assertCreated()->json('ticket.id');

        $json = $this->actingAs($admin)->getJson($url)->assertOk()->json();
        $rows = collect($json['data'])->keyBy('id');
        $this->assertSame(['enabled' => true, 'allowed' => true, 'report_group_set' => true], $json['manual_ticket']);
        $this->assertSame('Mode uji — tiket hanya dibuat manual', $json['mode_note']);

        $this->assertSame($id, $rows[$s1->id]['ticket']['id']);
        $this->assertSame('Terbuka', $rows[$s1->id]['ticket']['status_label']);
        $this->assertSame(route('tickets.show', $id), $rows[$s1->id]['ticket']['url']);
        $this->assertSame('sent', $rows[$s1->id]['report_wa_status']);
        $this->assertFalse($rows[$s1->id]['can_ticket']);

        $this->assertNull($rows[$s2->id]['ticket']);
        $this->assertTrue($rows[$s2->id]['can_ticket']);
        $this->assertFalse($rows[$dg->id]['can_ticket']);
        $this->assertFalse($rows[$s3->id]['can_ticket']);
        $this->assertNotNull($rows[$s3->id]['customer_open_ticket']);

        // phone / address never in the payload
        $body = json_encode($json);
        foreach ([(string) $c1->phone, (string) $c2->phone, 'Rahasia'] as $leak) {
            $this->assertStringNotContainsString($leak, $body);
        }

        $count = function () use ($admin, $url): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($admin)->getJson($url)->assertOk();
            $n = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'support_tickets') || str_contains($q['query'], 'wa_report_deliveries'))->count();
            DB::disableQueryLog();

            return $n;
        };
        $before = $count();
        for ($i = 0; $i < 12; $i++) {
            $this->singleEpisode();
        }
        $this->assertSame($before, $count());
    }

    public function test_preview_returns_lines_without_phone_and_writes_nothing(): void
    {
        Queue::fake();
        [$c, $s] = $this->singleEpisode();

        $res = $this->actingAs($this->noc())->getJson(route('api.monitoring.outage-shadow.ticket-preview', $s))->assertOk();

        $this->assertSame('single', $res->json('data.kind'));
        $this->assertSame('high', $res->json('data.priority'));
        $this->assertStringStartsWith('[Deteksi] LOS individual', $res->json('data.title'));
        $this->assertStringNotContainsString((string) $c->phone, json_encode($res->json()));
        $this->assertSame(0, SupportTicket::query()->count());
        Queue::assertNothingPushed();

        $id = $this->postTicket($this->noc(), $s)->assertCreated()->json('ticket.id');
        $this->actingAs($this->noc())->getJson(route('api.monitoring.outage-shadow.ticket-preview', $s))
            ->assertOk()->assertJsonPath('data.ticket.id', $id);
    }

    public function test_ticket_page_shows_the_detection_source(): void
    {
        Queue::fake();
        [, $s] = $this->singleEpisode();
        $id = $this->postTicket($this->noc(), $s)->assertCreated()->json('ticket.id');

        $this->actingAs($this->superadmin())->get(route('tickets.show', $id))->assertOk()
            ->assertInertia(fn ($page) => $page->where('detectionSource.episode_id', $s->id)->where('detectionSource.url', '/monitoring?tab=deteksi&episode='.$s->id));
    }

    public function test_two_open_episodes_of_one_customer_get_one_ticket(): void
    {
        Queue::fake();
        [$c, $first] = $this->singleEpisode();
        $second = $this->signal(['signal_type' => 'weak_rx', 'customer_id' => $c->id, 'payload' => ['rx_dbm' => -28.0]]);
        $noc = $this->noc();

        $id = $this->postTicket($noc, $first)->assertCreated()->json('ticket.id');
        $this->postTicket($noc, $second)->assertStatus(409)->assertJsonPath('message', 'Pelanggan ini sudah punya tiket terbuka #'.$id.'.');
        $this->assertSame(1, SupportTicket::query()->count());
    }

    public function test_outcome_classification_only_counts_listed_real_causes_and_pppoe_codes(): void
    {
        Queue::fake();
        $noc = $this->noc();
        foreach (['wlan_down', 'weird_code', 'pppoe_flap', 'drop_los', 'wifi_client'] as $cause) {
            [, $s] = $this->singleEpisode();
            $id = $this->postTicket($noc, $s)->assertCreated()->json('ticket.id');
            SupportTicket::query()->whereKey($id)->update(['status' => 'closed', 'root_cause' => $cause, 'resolved_at' => now()]);
        }
        $from = Carbon::parse($this->base.' UTC')->subDay();
        $to = Carbon::parse($this->base.' UTC')->addDay();

        $m = (new OutageShadowReporter)->manualTickets($from, $to)['single_los'];

        $this->assertSame(['real_fault' => 2, 'not_fault' => 2, 'unknown' => 1, 'still_open' => 0], $m['outcome']);
        $this->assertSame(5, $m['shown']);
        $this->assertSame(5, $m['clicked']);
    }

    public function test_clicks_on_episodes_opened_outside_the_window_are_not_counted(): void
    {
        Queue::fake();
        [, $old] = $this->singleEpisode([], ['opened_at' => Carbon::parse($this->base.' UTC')->subDays(5), 'confirmed_at' => null, 'state' => 'open']);
        $this->postTicket($this->noc(), $old)->assertCreated();
        $from = Carbon::parse($this->base.' UTC')->subDay();
        $to = Carbon::parse($this->base.' UTC')->addDay();

        $this->assertSame([], (new OutageShadowReporter)->manualTickets($from, $to));
    }

    public function test_precision_report_counts_manual_tickets_by_source(): void
    {
        $now = IlluminateCarbon::parse('2026-10-19 08:12:00', 'Asia/Jakarta'); // Monday
        Carbon::setTestNow($now);
        $c = $this->customer();
        $admin = $this->superadmin();
        $inWeek = Carbon::parse('2026-10-14 10:00:00', 'Asia/Jakarta')->utc()->format('Y-m-d H:i:s');
        $make = function (array $meta) use ($c, $admin, $inWeek): SupportTicket {
            $t = SupportTicket::create(['created_by' => $admin->id, 'customer_id' => $c->id, 'gangguan' => 'Mati', 'status' => 'resolved', 'type' => 'incident', 'priority' => 'normal', 'root_cause' => 'drop_los', 'meta' => $meta]);
            DB::table('support_tickets')->where('id', $t->id)->update(['resolved_at' => $inWeek]);
            OutageDiagnosis::create(['customer_id' => $c->id, 'ticket_id' => $t->id, 'trigger' => 'ticket_open', 'state' => 'done', 'cause_code' => 'drop_los', 'confidence' => 'sedang']);

            return $t;
        };
        $make(['source' => 'deteksi_manual', 'outage_signal_id' => 1]);
        $make([]);

        $report = app(PrecisionReport::class)->compute(PrecisionReport::weekStart($now->copy()->subWeek()));

        $this->assertSame(2, $report['evaluated']);
        $this->assertSame(2, $report['correct']);
        $this->assertSame(['deteksi_manual' => ['evaluated' => 1, 'correct' => 1]], $report['by_source']);
    }

    public function test_manual_tickets_created_before_the_window_start_are_not_counted(): void
    {
        Queue::fake();
        [, $s] = $this->singleEpisode();
        $id = $this->postTicket($this->noc(), $s)->assertCreated()->json('ticket.id');
        $from = Carbon::parse($this->base.' UTC')->subDay();
        $to = Carbon::parse($this->base.' UTC')->addDay();
        $this->assertSame(1, (new OutageShadowReporter)->manualTickets($from, $to)['single_los']['clicked']);

        DB::table('support_tickets')->where('id', $id)->update(['created_at' => $from->copy()->subHour()]);

        $m = (new OutageShadowReporter)->manualTickets($from, $to)['single_los'];
        $this->assertSame(1, $m['shown']);
        $this->assertSame(0, $m['clicked']);
    }

    public function test_as_guessed_never_counts_unknown_equals_unknown(): void
    {
        Queue::fake();
        $noc = $this->noc();
        foreach (['drop_los' => 'drop_los', 'unknown' => 'unknown', 'cpe_dead' => 'drop_los'] as $cause => $diagnosed) {
            [, $s] = $this->singleEpisode();
            $id = $this->postTicket($noc, $s)->assertCreated()->json('ticket.id');
            $t = SupportTicket::query()->findOrFail($id);
            $meta = (array) $t->meta;
            $meta['episode_snapshot'] = array_merge((array) ($meta['episode_snapshot'] ?? []), ['diagnosed_cause' => $diagnosed]);
            $t->meta = $meta;
            $t->status = 'closed';
            $t->root_cause = $cause;
            $t->resolved_at = now();
            $t->save();
        }
        $from = Carbon::parse($this->base.' UTC')->subDay();
        $to = Carbon::parse($this->base.' UTC')->addDay();

        $m = (new OutageShadowReporter)->manualTickets($from, $to)['single_los'];

        $this->assertSame(1, $m['as_guessed']); // only drop_los == drop_los
    }

    // ------------------------------------------------------------------ 13. dispatch after commit

    public function test_report_is_deferred_until_the_outer_transaction_commits(): void
    {
        $this->fakeEvolution();
        $this->reportGroup();
        [, $s] = $this->singleEpisode();
        $noc = $this->noc();
        $service = app(OutageManualTicketService::class);

        DB::beginTransaction();
        $ticketId = (int) $service->create($s, $noc, null, null)['ticket']->id;
        Http::assertNothingSent();
        $this->assertSame(0, WaReportDelivery::query()->where('report_key', 'deteksi_ticket:'.$ticketId)->count());
        DB::commit();

        Http::assertSentCount(1);
        $this->assertSame('sent', WaReportDelivery::query()->where('report_key', 'deteksi_ticket:'.$ticketId)->sole()->status);
    }

    public function test_report_is_not_sent_when_the_outer_transaction_rolls_back(): void
    {
        $this->fakeEvolution();
        $this->reportGroup();
        [, $s] = $this->singleEpisode();
        $noc = $this->noc();
        $service = app(OutageManualTicketService::class);

        DB::beginTransaction();
        $service->create($s, $noc, null, null);
        DB::rollBack();

        Http::assertNothingSent();
        $this->assertSame(0, WaReportDelivery::query()->where('report_key', 'like', 'deteksi_ticket:%')->count());
    }
}
