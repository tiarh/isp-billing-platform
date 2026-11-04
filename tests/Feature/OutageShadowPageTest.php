<?php

namespace Tests\Feature;

use App\Enums\OutageRootCause;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\OutageSignal;
use App\Models\Package;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Outage\OutageShadowEpisodeReadModel;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * "Deteksi Gangguan" tab (outage shadow mode): read-only GET JSON for global admins. No WA, no queue, no writes.
 */
#[Group('blocking-core')]
class OutageShadowPageTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private Olt $olt;

    /** 05:00 UTC = 12:00 WIB. */
    private string $base = '2026-10-15 05:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse($this->base.' UTC'));
        $this->olt = Olt::create(['name' => 'OLT RA', 'code' => 'DT-1', 'vendor' => 'zte_c300']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function customer(array $o = []): Customer
    {
        $package = Package::firstOrCreate(['code' => 'DT-PKG'], ['name' => 'Deteksi pkg', 'price' => 100000]);
        $n = ++$this->seq;

        return Customer::create(array_merge([
            'code' => 'DT-C'.$n,
            'name' => 'Pelanggan Uji '.$n,
            'phone' => '0813777'.$n,
            'address' => 'Jl. Rahasia '.$n,
            'pppoe_user' => 'dt.'.$n,
            'package_id' => $package->id,
            'status' => 'active',
            'olt_id' => $this->olt->id,
            'olt_port_label' => '1/1/1:'.$n,
        ], $o));
    }

    private function signal(array $o = []): OutageSignal
    {
        $n = ++$this->seq;

        return OutageSignal::create(array_merge([
            'signal_type' => 'single_los',
            'scope_key' => 'customer:'.$n,
            'olt_id' => $this->olt->id,
            'pon_port' => '1/1/1',
            'state' => 'confirmed',
            'opened_at' => Carbon::parse($this->base.' UTC'),
            'confirmed_at' => Carbon::parse($this->base.' UTC')->addMinutes(30),
            'affected_count' => 1,
            'would_action' => 'ticket_single',
            'mode_at_confirm' => 'shadow',
        ], $o));
    }

    private function superadmin(): User
    {
        return User::factory()->create(['role' => 'superadmin']);
    }

    private function getJson2(User $u, string $url)
    {
        return $this->actingAs($u)->getJson($url);
    }

    public function test_only_global_admins_can_read(): void
    {
        $url = route('api.monitoring.outage-shadow');
        $signal = $this->signal();
        $customersUrl = route('api.monitoring.outage-shadow.customers', $signal);

        $this->getJson($url)->assertUnauthorized();

        $scoped = User::factory()->create(['role' => 'admin']);
        $scoped->areas()->attach(Area::create(['name' => 'Scoped', 'code' => 'SCP']));
        foreach ([User::factory()->create(['role' => 'teknisi']), $scoped] as $denied) {
            $this->getJson2($denied, $url)->assertForbidden();
            $this->getJson2($denied, $customersUrl)->assertForbidden();
        }

        foreach ([$this->superadmin(), User::factory()->create(['role' => 'noc']), User::factory()->create(['role' => 'admin'])] as $ok) {
            $this->getJson2($ok, $url)->assertOk();
            $this->getJson2($ok, $customersUrl)->assertOk();
        }
    }

    public function test_deteksi_is_a_monitoring_view_tab(): void
    {
        $response = $this->actingAs($this->superadmin())->get('/monitoring?tab=deteksi');
        $response->assertOk();
        Assert::fromTestResponse($response)->component('Monitoring/Index')->where('viewTab', 'deteksi');

        $this->actingAs(User::factory()->create(['role' => 'teknisi']))->get('/monitoring?tab=deteksi')->assertForbidden();
    }

    public function test_row_shape_and_would_ticket_rule(): void
    {
        $c = $this->customer();
        $this->signal(['customer_id' => $c->id, 'affected_customer_ids' => [$c->id]]);
        $this->signal(['signal_type' => 'pppoe_down', 'would_action' => 'noc_alarm', 'customer_id' => null]);

        $json = $this->getJson2($this->superadmin(), route('api.monitoring.outage-shadow'))->assertOk()->json();

        $this->assertSame(2, $json['total']);
        $this->assertSame('Mode uji — tiket hanya dibuat manual', $json['mode_note']);
        $byType = collect($json['data'])->keyBy('signal_type');
        $this->assertTrue($byType['single_los']['would_ticket']);
        $this->assertSame('OLT RA', $byType['single_los']['olt_name']);
        $this->assertSame('Putus 1 rumah', $byType['single_los']['type_label']);
        $this->assertSame('Masih putus', $byType['single_los']['state_label']);
        $this->assertFalse($byType['pppoe_down']['would_ticket']);
        $this->assertSame('noc_alarm', $byType['pppoe_down']['would_action']);
        $this->assertSame($c->id, $byType['single_los']['customers'][0]['id']);
    }

    public function test_filters_and_default_range(): void
    {
        $this->signal(['signal_type' => 'mass_los', 'state' => 'open']);
        $this->signal(['signal_type' => 'weak_rx', 'state' => 'resolved', 'suppressed_reason' => 'psb']);
        $this->signal(['signal_type' => 'dying_gasp']);
        $other = Olt::create(['name' => 'OLT Lain', 'code' => 'DT-2', 'vendor' => 'zte_c300']);
        $this->signal(['olt_id' => $other->id]);
        $old = $this->signal(['state' => 'resolved', 'opened_at' => Carbon::parse($this->base.' UTC')->subDays(10)]);
        $admin = $this->superadmin();
        $url = route('api.monitoring.outage-shadow');
        // every tab and the info group on = the unfiltered list of before the tabs existed
        $all = $url.'?view=semua&info=1';

        // default = last 7 WIB days: the 10-day-old row is out
        $this->assertSame(4, $this->getJson2($admin, $all)->json('total'));
        $this->assertSame(5, $this->getJson2($admin, $all.'&from=2026-10-01')->json('total'));
        $this->assertSame(1, $this->getJson2($admin, $all.'&type=mass_los')->json('total'));
        $this->assertSame(1, $this->getJson2($admin, $all.'&type=lainnya')->json('total'));
        $this->assertSame(1, $this->getJson2($admin, $all.'&state=open')->json('total'));
        $this->assertSame(1, $this->getJson2($admin, $all.'&state=suppressed')->json('total'));
        $this->assertSame(1, $this->getJson2($admin, $all.'&olt_id='.$other->id)->json('total'));
        // episode id ignores the date range
        $this->assertSame([$old->id], collect($this->getJson2($admin, $url.'?episode='.$old->id)->json('data'))->pluck('id')->all());
        // span is clamped to 31 days ending at "to"
        $this->assertSame('2026-09-15', $this->getJson2($admin, $url.'?from=2026-01-01&to=2026-10-15')->json('filters.from'));
        // the new default (no view, no info): only unresolved, visible rows: the mass row, the dying gasp and the other-OLT row
        $this->assertSame(3, $this->getJson2($admin, $url)->json('total'));
    }

    public function test_page_two_returns_the_remaining_rows_and_pagination_keys(): void
    {
        $ids = [];
        for ($i = 0; $i < 27; $i++) {
            // newest first: higher i = later opened_at
            $ids[] = $this->signal(['opened_at' => Carbon::parse($this->base.' UTC')->subMinutes(100 - $i)])->id;
        }
        $admin = $this->superadmin();
        $url = route('api.monitoring.outage-shadow');

        $json = $this->getJson2($admin, $url.'?page=2')->assertOk()->json();

        $this->assertSame(2, $json['current_page']);
        $this->assertSame(2, $json['last_page']);
        $this->assertSame(27, $json['total']);
        $this->assertSame(26, $json['from']);
        $this->assertSame(27, $json['to']);
        $this->assertSame([$ids[1], $ids[0]], collect($json['data'])->pluck('id')->all());
        $this->assertArrayHasKey('links', $json);
        $this->assertArrayHasKey('path', $json);
        $this->assertNotEmpty($json['path']);
        $this->assertCount(25, $this->getJson2($admin, $url)->json('data'));
    }

    public function test_unresolved_episodes_open_over_24h_are_pinned_first_across_pages(): void
    {
        $now = Carbon::parse($this->base.' UTC');
        // 30 h = 1 day, 100 h = 4 days (oldest), 60 h = 2 days
        $pinA = $this->signal(['state' => 'open', 'opened_at' => $now->copy()->subHours(30)])->id;
        $pinB = $this->signal(['state' => 'open', 'opened_at' => $now->copy()->subHours(100)])->id;
        $pinC = $this->signal(['state' => 'confirmed', 'opened_at' => $now->copy()->subHours(60)])->id;
        $pinD = $this->signal(['state' => 'open', 'opened_at' => $now->copy()->subHours(25), 'signal_type' => 'pppoe_down'])->id;
        // 12 days old = outside the 7-day window, still pinned and shown; weak_rx and resolved of that age stay hidden
        $pinOld = $this->signal(['state' => 'open', 'opened_at' => $now->copy()->subDays(12)])->id;
        $this->signal(['state' => 'open', 'signal_type' => 'weak_rx', 'opened_at' => $now->copy()->subDays(12)]);
        $this->signal(['state' => 'resolved', 'opened_at' => $now->copy()->subDays(12)]);
        // weak_rx is never pinned, even when open for 40 h
        $weakOld = $this->signal(['state' => 'open', 'signal_type' => 'weak_rx', 'opened_at' => $now->copy()->subHours(40)])->id;
        $resolvedOld = $this->signal(['state' => 'resolved', 'opened_at' => $now->copy()->subHours(120)])->id;
        $under24 = $this->signal(['state' => 'open', 'opened_at' => $now->copy()->subHours(23)])->id;
        $fresh = [];
        for ($i = 0; $i < 14; $i++) {
            $fresh[] = $this->signal(['state' => 'open', 'opened_at' => $now->copy()->subMinutes(200 - $i)])->id;
        }
        $admin = $this->superadmin();
        // all tabs + info on: the pinned block and its paging are what this test is about
        $url = route('api.monitoring.outage-shadow').'?per_page=10&view=semua&info=1';

        $p1 = $this->getJson2($admin, $url.'&page=1')->assertOk()->json();
        $p2 = $this->getJson2($admin, $url.'&page=2')->assertOk()->json();
        $p3 = $this->getJson2($admin, $url.'&page=3')->assertOk()->json();
        $ids1 = collect($p1['data'])->pluck('id')->all();
        $ids2 = collect($p2['data'])->pluck('id')->all();
        $ids3 = collect($p3['data'])->pluck('id')->all();

        // pinned block first (even the 12-day-old one outside the window), oldest first;
        // then the rest newest first (23 h row and the 40 h weak_rx are normal rows)
        $this->assertSame([$pinOld, $pinB, $pinC, $pinA, $pinD], array_slice($ids1, 0, 5));
        $expectedRest = [...array_reverse($fresh), $under24, $weakOld, $resolvedOld];
        $this->assertSame(array_slice($expectedRest, 0, 5), array_slice($ids1, 5));
        $this->assertSame(array_slice($expectedRest, 5, 10), $ids2);
        $this->assertSame(array_slice($expectedRest, 15), $ids3);
        // no repeats, no skips across pages; total counts the pinned out-of-window row, not the hidden ones
        $this->assertCount(22, array_unique([...$ids1, ...$ids2, ...$ids3]));
        $this->assertSame(22, $p1['total']);
        $this->assertSame(3, $p1['last_page']);

        // age label values: whole days, 24-48 h = 1; never for closed, fresh or weak_rx rows
        $age = collect([...$p1['data'], ...$p2['data'], ...$p3['data']])->pluck('pinned_age_days', 'id');
        $this->assertSame(12, $age[$pinOld]);
        $this->assertNull($age[$weakOld]);
        $this->assertSame(4, $age[$pinB]);
        $this->assertSame(2, $age[$pinC]);
        $this->assertSame(1, $age[$pinA]);
        $this->assertSame(1, $age[$pinD]);
        $this->assertNull($age[$resolvedOld]);
        $this->assertNull($age[$under24]);
        $this->assertNull($age[$fresh[0]]);

        // other filters still apply to pinned rows; only the date window is bypassed
        $base = route('api.monitoring.outage-shadow');
        $weak = $this->getJson2($admin, $base.'?type=weak_rx')->json();
        $this->assertSame([$weakOld], collect($weak['data'])->pluck('id')->all());
        $this->assertSame([$pinD], collect($this->getJson2($admin, $base.'?type=pppoe_down')->json('data'))->pluck('id')->all());
        $this->assertSame([$pinC], collect($this->getJson2($admin, $base.'?state=confirmed')->json('data'))->pluck('id')->all());
        $this->assertSame(0, $this->getJson2($admin, $base.'?type=mass_los')->json('total'));
        $resolved = $this->getJson2($admin, route('api.monitoring.outage-shadow').'?state=resolved')->json();
        $this->assertSame([$resolvedOld], collect($resolved['data'])->pluck('id')->all());
        $this->assertNull($resolved['data'][0]['pinned_age_days']);
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $admin = $this->superadmin();
        $url = route('api.monitoring.outage-shadow');
        foreach (['from=15-10-2026', 'type=bogus', 'state=bogus', 'olt_id=abc', 'episode=x', 'from=2026-10-15&to=2026-10-10', 'view=bogus', 'info=maybe', 'q='.str_repeat('a', 65)] as $q) {
            $this->getJson2($admin, $url.'?'.$q)->assertUnprocessable();
        }
        $this->getJson2($admin, $url.'?from=2026-10-10&to=2026-10-10')->assertOk();
    }

    public function test_more_count_is_honest_when_ids_are_missing_and_rx_collected_at_is_returned(): void
    {
        $c = $this->customer(['olt_port_label' => '1/1/1:9']);
        OltOnu::create(['olt_id' => $this->olt->id, 'onu_ref' => 'gpon-onu_1/1/1:9', 'rx_power_dbm' => -26.0, 'rx_collected_at' => Carbon::parse($this->base.' UTC')]);
        // 12 counted, only 2 ids stored (one customer deleted): nothing more to expand, the other 11 are "unlisted"
        $ghost = $this->customer();
        $ghost->delete();
        $this->signal(['signal_type' => 'mass_los', 'customer_id' => null, 'affected_count' => 12, 'affected_customer_ids' => [$c->id, $ghost->id]]);

        $row = $this->getJson2($this->superadmin(), route('api.monitoring.outage-shadow'))->json('data.0');

        $this->assertSame(1, count($row['customers']));
        $this->assertSame(0, $row['customers_more']);
        $this->assertSame(11, $row['customers_unlisted']);
        $this->assertSame(Carbon::parse($this->base.' UTC')->getTimestamp(), Carbon::parse($row['customers'][0]['rx_collected_at'])->getTimestamp());
    }

    public function test_more_count_excludes_soft_deleted_customers_beyond_the_inline_ten(): void
    {
        $ids = [];
        for ($i = 0; $i < 14; $i++) {
            $ids[] = $this->customer()->id;
        }
        Customer::query()->whereKey($ids[11])->first()->delete();
        $s = $this->signal(['signal_type' => 'mass_los', 'customer_id' => null, 'affected_count' => 14, 'affected_customer_ids' => $ids]);
        $admin = $this->superadmin();

        $row = $this->getJson2($admin, route('api.monitoring.outage-shadow'))->json('data.0');
        $this->assertCount(10, $row['customers']);
        $this->assertSame(3, $row['customers_more']); // 4 ids beyond the inline ten, one is soft-deleted
        $this->assertSame(1, $row['customers_unlisted']);
        $this->assertCount(13, $this->getJson2($admin, route('api.monitoring.outage-shadow.customers', $s))->json('data'));
    }

    public function test_mass_row_shows_ten_customers_and_expand_returns_all_with_whitelisted_keys(): void
    {
        $ids = [];
        for ($i = 0; $i < 14; $i++) {
            $ids[] = $this->customer()->id;
        }
        $s = $this->signal(['signal_type' => 'mass_los', 'customer_id' => null, 'affected_count' => 14, 'affected_customer_ids' => $ids]);
        $admin = $this->superadmin();

        $row = $this->getJson2($admin, route('api.monitoring.outage-shadow'))->json('data.0');
        $this->assertCount(10, $row['customers']);
        $this->assertSame(4, $row['customers_more']);

        $all = $this->getJson2($admin, route('api.monitoring.outage-shadow.customers', $s))->assertOk()->json('data');
        $this->assertCount(14, $all);
        $keys = ['id', 'code', 'name', 'pppoe_user', 'onu_port', 'rx_dbm', 'rx_collected_at', 'status'];
        foreach ($all as $c) {
            $this->assertSame($keys, array_keys($c));
        }
        $body = json_encode($row).json_encode($all);
        foreach (['phone', 'nik', 'address', 'latitude', 'longitude', 'password', '0813777', 'Rahasia'] as $leak) {
            $this->assertStringNotContainsString($leak, $body);
        }
    }

    public function test_affected_ids_fall_back_to_payload_and_rx_comes_from_olt_onus(): void
    {
        $c = $this->customer(['olt_port_label' => '1/1/1:7']);
        OltOnu::create(['olt_id' => $this->olt->id, 'onu_ref' => 'gpon-onu_1/1/1:7', 'rx_power_dbm' => -27.5, 'rx_collected_at' => now()]);
        $this->signal(['signal_type' => 'pppoe_down', 'customer_id' => null, 'payload' => ['affected_customer_ids' => [$c->id]]]);

        $row = $this->getJson2($this->superadmin(), route('api.monitoring.outage-shadow'))->json('data.0');
        $this->assertSame($c->id, $row['customers'][0]['id']);
        $this->assertSame(-27.5, $row['customers'][0]['rx_dbm']);
    }

    public function test_query_count_is_constant_between_one_and_twenty_five_rows(): void
    {
        $admin = $this->superadmin();
        $url = route('api.monitoring.outage-shadow');

        $count = function () use ($admin, $url): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson2($admin, $url)->assertOk();
            $queries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'outage_signals') || str_contains($q['query'], 'customers') || str_contains($q['query'], 'olt_onus') || str_contains($q['query'], 'olts'))->count();
            DB::disableQueryLog();

            return $queries;
        };

        $c = $this->customer();
        $this->signal(['customer_id' => $c->id]);
        $one = $count();

        for ($i = 0; $i < 24; $i++) {
            $c = $this->customer();
            $this->signal(['customer_id' => $c->id, 'affected_customer_ids' => [$c->id]]);
        }
        $this->assertSame($one, $count());
    }

    public function test_endpoints_are_get_only_and_touch_no_wa_or_queue(): void
    {
        Http::fake();
        Queue::fake();
        $s = $this->signal();
        $admin = $this->superadmin();
        $before = OutageSignal::query()->count();

        $this->getJson2($admin, route('api.monitoring.outage-shadow'))->assertOk();
        $this->getJson2($admin, route('api.monitoring.outage-shadow.customers', $s))->assertOk();
        $this->actingAs($admin)->postJson(route('api.monitoring.outage-shadow'))->assertStatus(405);
        $this->actingAs($admin)->deleteJson(route('api.monitoring.outage-shadow.customers', $s))->assertStatus(405);

        Http::assertNothingSent();
        Queue::assertNothingPushed();
        $this->assertSame($before, OutageSignal::query()->count());
    }

    private function ticket(?Customer $c, string $status): SupportTicket
    {
        Queue::fake();
        Http::fake();

        return SupportTicket::create([
            'customer_id' => $c?->id, 'customer_name' => $c?->name ?? 'Massal', 'type' => 'incident', 'status' => $status, 'priority' => 'normal', 'gangguan' => 'Internet mati',
        ]);
    }

    /** @return list<int> */
    private function ids(User $admin, string $qs): array
    {
        return collect($this->getJson2($admin, route('api.monitoring.outage-shadow').'?'.$qs)->assertOk()->json('data'))->pluck('id')->all();
    }

    public function test_search_finds_customers_by_name_code_pppoe_phone_and_id_and_never_returns_the_phone(): void
    {
        $a = $this->customer(['name' => 'Alpha Uji Satu', 'code' => 'ZA-ALPHA', 'pppoe_user' => 'alpha.satu', 'phone' => '081200000011']);
        $b = $this->customer(['name' => 'Beta Uji Dua', 'code' => 'ZB-BETA', 'pppoe_user' => 'beta.dua', 'phone' => '081200000022']);
        DB::table('customers')->where('id', $a->id)->update(['id' => 4242]);
        $sa = $this->signal(['customer_id' => 4242, 'affected_customer_ids' => [4242]]);
        $sb = $this->signal(['customer_id' => $b->id, 'affected_customer_ids' => [$b->id]]);
        $admin = $this->superadmin();

        // name: every term must match, any order, any case
        $this->assertSame([$sa->id], $this->ids($admin, http_build_query(['q' => 'alpha uji'])));
        $this->assertSame([$sa->id], $this->ids($admin, http_build_query(['q' => 'SATU uji'])));
        $this->assertSame([$sb->id, $sa->id], $this->ids($admin, http_build_query(['q' => 'uji'])));
        // code and PPPoE prefix
        $this->assertSame([$sa->id], $this->ids($admin, http_build_query(['q' => 'ZA-AL'])));
        $this->assertSame([$sb->id], $this->ids($admin, http_build_query(['q' => 'beta.d'])));
        // phone tail, with spaces, a leading 0 or +62
        $this->assertSame([$sa->id], $this->ids($admin, http_build_query(['q' => '0812 0000 0011'])));
        $this->assertSame([$sa->id], $this->ids($admin, http_build_query(['q' => '+62 812 0000 0011'])));
        $this->assertSame([$sa->id], $this->ids($admin, http_build_query(['q' => '20000001'])));
        // numeric customer id, "#episode" and "kejadian N"
        $this->assertSame([$sa->id], $this->ids($admin, http_build_query(['q' => '4242'])));
        $this->assertSame([$sb->id], $this->ids($admin, http_build_query(['q' => '#'.$sb->id])));
        $this->assertSame([$sb->id], $this->ids($admin, http_build_query(['q' => 'kejadian '.$sb->id])));

        $json = $this->getJson2($admin, route('api.monitoring.outage-shadow').'?'.http_build_query(['q' => '0812 0000 0011']))->assertOk()->json();
        $this->assertSame(['q' => '0812 0000 0011', 'customers_found' => 1, 'too_many' => false], $json['search']);
        $this->assertSame([['id' => 4242, 'code' => 'ZA-ALPHA', 'name' => 'Alpha Uji Satu', 'pppoe_user' => 'alpha.satu']], $json['data'][0]['matched_customers']);
        $this->assertNoPhoneAnywhere($json);
        $this->assertStringNotContainsString('081200000011', json_encode($json));
        $this->assertStringNotContainsString('81200000011', json_encode($json));
    }

    /** @param  array<mixed>  $data */
    private function assertNoPhoneAnywhere(array $data): void
    {
        $walk = function (array $node) use (&$walk): void {
            foreach ($node as $k => $v) {
                $this->assertNotSame('phone', $k);
                if (is_array($v)) {
                    $walk($v);
                }
            }
        };
        $walk($data);
    }

    public function test_search_finds_a_customer_inside_a_mass_episode_and_inside_the_legacy_payload_ids(): void
    {
        $ids = [];
        for ($i = 1; $i <= 14; $i++) {
            $ids[] = $this->customer(['name' => 'Gamma Member '.$i])->id;
        }
        $mass = $this->signal(['signal_type' => 'mass_los', 'state' => 'open', 'customer_id' => null, 'affected_count' => 14, 'affected_customer_ids' => $ids]);
        $legacyCustomer = $this->customer(['name' => 'Delta Lama Solo']);
        $legacy = $this->signal(['signal_type' => 'pppoe_down', 'customer_id' => null, 'affected_customer_ids' => null, 'payload' => ['affected_customer_ids' => [$legacyCustomer->id]]]);
        $this->signal(['customer_id' => $this->customer(['name' => 'Omega Lain'])->id]);
        $admin = $this->superadmin();
        $url = route('api.monitoring.outage-shadow');

        $row = $this->getJson2($admin, $url.'?'.http_build_query(['q' => 'member 12']))->assertOk()->json('data.0');
        $this->assertSame($mass->id, $row['id']);
        $this->assertSame(['Gamma Member 12'], array_column($row['matched_customers'], 'name'));
        // number 12 is beyond the inline ten: the row still shows who matched
        $this->assertNotContains('Gamma Member 12', array_column($row['customers'], 'name'));

        $all = $this->getJson2($admin, $url.'?'.http_build_query(['q' => 'gamma']))->json();
        $this->assertSame([$mass->id], collect($all['data'])->pluck('id')->all());
        $this->assertSame(14, $all['search']['customers_found']);
        $this->assertCount(14, $all['data'][0]['matched_customers']);

        $this->assertSame([$legacy->id], $this->ids($admin, http_build_query(['q' => 'delta lama'])));
    }

    public function test_search_escapes_like_characters_and_ignores_one_character(): void
    {
        $this->signal(['customer_id' => $this->customer(['name' => 'Epsilon Abc'])->id]);
        $under = $this->signal(['customer_id' => $this->customer(['name' => 'Epsilon A_c'])->id]);
        $admin = $this->superadmin();
        $url = route('api.monitoring.outage-shadow');

        // "_" is a literal underscore, not "any character"; "%" matches nothing
        $this->assertSame([$under->id], $this->ids($admin, http_build_query(['q' => 'a_c'])));
        foreach (['%%', '__', 'e%'] as $q) {
            $json = $this->getJson2($admin, $url.'?'.http_build_query(['q' => $q]))->json();
            $this->assertSame(0, $json['total'], $q);
            $this->assertSame(0, $json['search']['customers_found'], $q);
        }

        // one character (or only spaces) is not a search
        $plain = $this->getJson2($admin, $url)->json('total');
        foreach (['a', ' a ', '  '] as $q) {
            $json = $this->getJson2($admin, $url.'?'.http_build_query(['q' => $q]))->json();
            $this->assertSame($plain, $json['total'], $q);
            $this->assertNull($json['search']['q'], $q);
        }
    }

    public function test_search_with_more_than_fifty_matches_asks_for_a_longer_term(): void
    {
        for ($i = 1; $i <= 51; $i++) {
            $c = $this->customer(['name' => 'Omega Banyak '.$i]);
            if ($i === 1) {
                $this->signal(['customer_id' => $c->id]);
            }
        }
        for ($i = 1; $i <= 50; $i++) {
            $c = $this->customer(['name' => 'Sigma Cukup '.$i]);
            if ($i === 1) {
                $this->signal(['customer_id' => $c->id]);
            }
        }
        $admin = $this->superadmin();
        $url = route('api.monitoring.outage-shadow');

        $many = $this->getJson2($admin, $url.'?'.http_build_query(['q' => 'omega banyak']))->assertOk()->json();
        $this->assertTrue($many['search']['too_many']);
        $this->assertSame(0, $many['total']);
        $this->assertSame([], $many['data']);

        $fifty = $this->getJson2($admin, $url.'?'.http_build_query(['q' => 'sigma cukup']))->assertOk()->json();
        $this->assertFalse($fifty['search']['too_many']);
        $this->assertSame(50, $fifty['search']['customers_found']);
        $this->assertSame(1, $fifty['total']);
    }

    public function test_search_looks_back_31_days_unless_dates_are_picked_and_has_a_second_page(): void
    {
        $c = $this->customer(['name' => 'Theta Sering']);
        $ids = [];
        for ($i = 0; $i < 27; $i++) {
            // 1 .. 27 days ago, all resolved
            $ids[] = $this->signal(['customer_id' => $c->id, 'affected_customer_ids' => [$c->id], 'state' => 'resolved', 'opened_at' => Carbon::parse($this->base.' UTC')->subDays(1 + $i)])->id;
        }
        $admin = $this->superadmin();
        $url = route('api.monitoring.outage-shadow');
        $q = http_build_query(['q' => 'theta sering', 'view' => 'semua', 'per_page' => 10]);

        $this->assertLessThan(27, $this->getJson2($admin, $url.'?view=semua')->json('total'));
        $p1 = $this->getJson2($admin, $url.'?'.$q)->assertOk()->json();
        $p2 = $this->getJson2($admin, $url.'?'.$q.'&page=2')->json();
        $p3 = $this->getJson2($admin, $url.'?'.$q.'&page=3')->json();
        $this->assertSame(27, $p1['total']);
        $this->assertSame('2026-09-15', $p1['filters']['from']);
        $this->assertSame(2, $p2['current_page']);
        $this->assertSame(27, $p2['total']);
        $this->assertCount(10, $p1['data']);
        $this->assertCount(10, $p2['data']);
        $this->assertCount(7, $p3['data']);
        $seen = [...array_column($p1['data'], 'id'), ...array_column($p2['data'], 'id'), ...array_column($p3['data'], 'id')];
        $this->assertCount(27, array_unique($seen));
        $this->assertEqualsCanonicalizing($ids, $seen);

        // picked dates are respected (5 episodes between 10 and 15 Oct)
        $picked = $this->getJson2($admin, $url.'?'.$q.'&from=2026-10-10&to=2026-10-15')->json();
        $this->assertSame(5, $picked['total']);
        $this->assertSame('2026-10-10', $picked['filters']['from']);

        // all in "Selesai": the tab counts follow the search
        $this->assertSame(['perlu' => 0, 'sedang' => 0, 'selesai' => 27, 'semua' => 27], $p1['counts']);
    }

    public function test_tabs_counts_and_chips_match_the_list_for_every_view(): void
    {
        $a = $this->customer(['name' => 'Alpha Tab']);
        $b = $this->customer(['name' => 'Beta Tab']);
        $c = $this->customer(['name' => 'Gamma Tab']);
        $this->ticket($b, 'in_progress');
        $this->ticket($c, 'closed');
        $s1 = $this->signal(['customer_id' => $a->id, 'affected_customer_ids' => [$a->id]]);
        $s2 = $this->signal(['customer_id' => $b->id, 'affected_customer_ids' => [$b->id], 'state' => 'open']);
        $s3 = $this->signal(['customer_id' => $c->id, 'affected_customer_ids' => [$c->id], 'state' => 'open']);
        $s4 = $this->signal(['signal_type' => 'mass_los', 'state' => 'open', 'customer_id' => null, 'ticket_id' => $this->ticket(null, 'assigned')->id]);
        // an episode whose own ticket is already final needs a new look
        $s5 = $this->signal(['signal_type' => 'mass_los', 'state' => 'open', 'customer_id' => null, 'ticket_id' => $this->ticket(null, 'resolved')->id]);
        $s6 = $this->signal(['state' => 'resolved', 'resolved_at' => now()]);
        $s7 = $this->signal(['state' => 'expired']);
        $s8 = $this->signal(['signal_type' => 'weak_rx', 'state' => 'open']);
        $admin = $this->superadmin();
        $expected = ['perlu' => [$s1, $s3, $s5], 'sedang' => [$s2, $s4], 'selesai' => [$s6, $s7], 'semua' => [$s1, $s2, $s3, $s4, $s5, $s6, $s7]];

        foreach ($expected as $view => $rows) {
            $json = $this->getJson2($admin, route('api.monitoring.outage-shadow').'?view='.$view)->assertOk()->json();
            $this->assertEqualsCanonicalizing(collect($rows)->pluck('id')->all(), collect($json['data'])->pluck('id')->all(), $view);
            $this->assertSame(['perlu' => 3, 'sedang' => 2, 'selesai' => 2, 'semua' => 7], $json['counts'], $view);
            $this->assertSame($json['total'], $json['counts'][$view], $view);
            // chips: one row belongs to one chip, so they add up to the tab
            $this->assertSame($json['total'], array_sum(array_column($json['facets'], 'count')), $view);
            foreach ($json['facets'] as $chip) {
                $this->assertSame(OutageShadowEpisodeReadModel::CHIP_LABELS[$chip['value']], $chip['label']);
                $this->assertContains($chip['value'], OutageShadowEpisodeReadModel::TYPE_FILTERS);
            }
        }
        // default tab = perlu
        $this->assertEqualsCanonicalizing(collect($expected['perlu'])->pluck('id')->all(), $this->ids($admin, ''));

        $perlu = $this->getJson2($admin, route('api.monitoring.outage-shadow').'?view=perlu')->json();
        $this->assertEqualsCanonicalizing([['value' => 'single_los', 'count' => 2], ['value' => 'mass_los', 'count' => 1]], array_map(fn ($f) => ['value' => $f['value'], 'count' => $f['count']], $perlu['facets']));
        // chip click = type filter inside the tab
        $this->assertSame([$s5->id], $this->ids($admin, 'view=perlu&type=mass_los'));
        // the hidden weak_rx is counted for the toggle, only in the tab it belongs to
        $this->assertSame(1, $perlu['info_count']);
        $this->assertSame(0, $this->getJson2($admin, route('api.monitoring.outage-shadow').'?view=selesai')->json('info_count'));
        $on = $this->getJson2($admin, route('api.monitoring.outage-shadow').'?view=perlu&info=1')->json();
        $this->assertSame(4, $on['counts']['perlu']);
        $this->assertContains($s8->id, collect($on['data'])->pluck('id')->all());
    }

    public function test_info_group_is_hidden_by_default_and_shown_with_info_on(): void
    {
        $normal = $this->signal();
        $drop = $this->signal(['diagnosed_cause' => 'drop_los']);
        $weak = $this->signal(['signal_type' => 'weak_rx', 'state' => 'open']);
        $blind = $this->signal(['signal_type' => 'data_blind', 'state' => 'open']);
        $held = $this->signal(['suppressed_reason' => 'psb']);
        $isolir = $this->signal(['diagnosed_cause' => 'isolir_admin']);
        $notFault = $this->signal(['diagnosed_cause' => 'not_fault']);
        $admin = $this->superadmin();

        // diagnosed_cause = NULL (the normal row) must not drop out of the default list
        $this->assertEqualsCanonicalizing([$normal->id, $drop->id], $this->ids($admin, 'view=semua'));
        $this->assertEqualsCanonicalizing([$normal->id, $drop->id], $this->ids($admin, 'view=semua&info=0'));
        $on = $this->ids($admin, 'view=semua&info=1');
        $this->assertEqualsCanonicalizing([$normal->id, $drop->id, $weak->id, $blind->id, $held->id, $isolir->id, $notFault->id], $on);
        $json = $this->getJson2($admin, route('api.monitoring.outage-shadow').'?view=semua')->json();
        $this->assertFalse($json['filters']['info']);
        $this->assertSame(5, $json['info_count']);
        $this->assertSame(2, $json['counts']['semua']);
        // old links: an info type or "ditahan" asked for by name still works without the toggle
        $this->assertSame([$weak->id], $this->ids($admin, 'type=weak_rx'));
        $this->assertSame([$blind->id], $this->ids($admin, 'type=data_blind'));
        $this->assertSame([$held->id], $this->ids($admin, 'state=suppressed'));
    }

    public function test_pinned_rows_still_come_first_inside_each_tab_and_follow_search_and_info(): void
    {
        $now = Carbon::parse($this->base.' UTC');
        $p1c = $this->customer(['name' => 'Kappa Pin Satu']);
        $p2c = $this->customer(['name' => 'Kappa Pin Dua']);
        $this->ticket($p2c, 'assigned');
        $p1 = $this->signal(['customer_id' => $p1c->id, 'affected_customer_ids' => [$p1c->id], 'state' => 'open', 'opened_at' => $now->copy()->subHours(36)])->id;
        $p2 = $this->signal(['customer_id' => $p2c->id, 'affected_customer_ids' => [$p2c->id], 'state' => 'confirmed', 'opened_at' => $now->copy()->subHours(50)])->id;
        $p3 = $this->signal(['state' => 'open', 'suppressed_reason' => 'psb', 'opened_at' => $now->copy()->subHours(60)])->id;
        $fresh = [];
        for ($i = 0; $i < 3; $i++) {
            $fresh[] = $this->signal(['state' => 'open', 'opened_at' => $now->copy()->subMinutes(30 - $i)])->id;
        }
        $admin = $this->superadmin();
        $get = fn (string $qs) => $this->getJson2($admin, route('api.monitoring.outage-shadow').'?'.$qs)->assertOk()->json();

        $perlu = $get('view=perlu');
        $this->assertSame([$p1, ...array_reverse($fresh)], array_column($perlu['data'], 'id'));
        $this->assertSame(1, $perlu['pinned_total']);

        $sedang = $get('view=sedang');
        $this->assertSame([$p2], array_column($sedang['data'], 'id'));
        $this->assertSame(1, $sedang['pinned_total']);

        $info = $get('view=perlu&info=1');
        $this->assertSame([$p3, $p1, ...array_reverse($fresh)], array_column($info['data'], 'id'));
        $this->assertSame(2, $info['pinned_total']);

        $selesai = $get('view=selesai');
        $this->assertSame(0, $selesai['total']);
        $this->assertSame(0, $selesai['pinned_total']);

        $searched = $get('q='.urlencode('kappa pin'));
        $this->assertSame([$p1], array_column($searched['data'], 'id'));
        $this->assertSame(1, $searched['pinned_total']);
        $this->assertSame(1, $searched['data'][0]['pinned_age_days']);

        // a pinned row older than the date window stays visible inside its tab
        $old = $this->signal(['state' => 'open', 'opened_at' => $now->copy()->subDays(12)])->id;
        $this->assertSame($old, $get('view=perlu')['data'][0]['id']);
        $this->assertSame(2, $get('view=perlu')['pinned_total']);
    }

    public function test_episode_link_ignores_tab_info_and_search(): void
    {
        $held = $this->signal(['state' => 'resolved', 'suppressed_reason' => 'psb', 'opened_at' => Carbon::parse($this->base.' UTC')->subDays(20)]);
        $admin = $this->superadmin();

        $json = $this->getJson2($admin, route('api.monitoring.outage-shadow').'?'.http_build_query(['episode' => $held->id, 'view' => 'perlu', 'q' => 'zzz-none']))->assertOk()->json();

        $this->assertSame([$held->id], array_column($json['data'], 'id'));
        $this->assertSame(['perlu' => 0, 'sedang' => 0, 'selesai' => 0, 'semua' => 0], $json['counts']);
    }

    public function test_page_words_are_plain_but_ticket_and_report_labels_keep_theirs(): void
    {
        $this->signal(['signal_type' => 'olt_wide', 'state' => 'open']);
        $row = $this->getJson2($this->superadmin(), route('api.monitoring.outage-shadow'))->json('data.0');

        $this->assertSame('Satu OLT mati', $row['type_label']);
        $this->assertSame('Baru', $row['state_label']);
        $this->assertEqualsCanonicalizing(array_keys(OutageShadowEpisodeReadModel::TYPE_LABELS), array_keys(OutageShadowEpisodeReadModel::PAGE_TYPE_LABELS));
        $this->assertEqualsCanonicalizing(array_keys(OutageShadowEpisodeReadModel::STATE_LABELS), array_keys(OutageShadowEpisodeReadModel::PAGE_STATE_LABELS));
        // the ticket evidence and the report group still use the old words (a separate change)
        $this->assertSame('LOS individual', OutageShadowEpisodeReadModel::TYPE_LABELS['single_los']);
        $this->assertSame('Terkonfirmasi', OutageShadowEpisodeReadModel::STATE_LABELS['confirmed']);
    }

    public function test_the_24_hour_preset_is_the_last_24_hours_not_yesterday_plus_today(): void
    {
        $now = Carbon::parse($this->base.' UTC');
        // yesterday 01:00 WIB = 30 h ago: inside "yesterday .. today", outside the last 24 hours
        $old = $this->signal(['state' => 'resolved', 'opened_at' => $now->copy()->subHours(30), 'resolved_at' => $now->copy()->subHours(29)]);
        $recent = $this->signal(['state' => 'resolved', 'opened_at' => $now->copy()->subHours(5), 'resolved_at' => $now->copy()->subHours(4)]);
        $admin = $this->superadmin();
        $url = route('api.monitoring.outage-shadow');
        $dates = ['view' => 'semua', 'from' => '2026-10-14', 'to' => '2026-10-15'];

        $day = $this->getJson2($admin, $url.'?'.http_build_query($dates))->assertOk()->json();
        $this->assertEqualsCanonicalizing([$old->id, $recent->id], array_column($day['data'], 'id'), 'two calendar days = about 48 hours');

        $hours = $this->getJson2($admin, $url.'?'.http_build_query($dates + ['hours' => 24]))->assertOk()->json();
        $this->assertSame([$recent->id], array_column($hours['data'], 'id'));
        $this->assertSame(1, $hours['counts']['semua'], 'counts follow the same window');
        $this->assertSame(24, $hours['filters']['hours']);

        // only 24 is accepted
        $this->getJson2($admin, $url.'?hours=48')->assertStatus(422);
        $this->assertNull(OutageShadowEpisodeReadModel::normalizeFilters(['hours' => 5])['hours']);
    }

    public function test_hash_search_of_an_unknown_episode_is_a_single_episode_answer_with_no_rows(): void
    {
        $row = $this->signal(['state' => 'open']);
        $admin = $this->superadmin();
        $url = route('api.monitoring.outage-shadow');

        $found = $this->getJson2($admin, $url.'?'.http_build_query(['q' => '#'.$row->id]))->assertOk()->json();
        $this->assertSame([$row->id], array_column($found['data'], 'id'));
        $this->assertSame($row->id, $found['filters']['episode']);

        $none = $this->getJson2($admin, $url.'?'.http_build_query(['q' => '#987654']))->assertOk()->json();
        $this->assertSame(0, $none['total']);
        $this->assertSame(987654, $none['filters']['episode'], 'the page answers "Kejadian #987654 tidak ditemukan." from this');
        $this->assertSame(0, $none['search']['customers_found']);
    }

    public function test_diagnosis_labels_use_one_plain_source_on_every_screen(): void
    {
        $expected = [
            'upstream' => 'Jalur internet pusat putus', 'drop_los' => 'Kabel ke rumah putus', 'power_off' => 'Listrik / adaptor mati',
            'cpe_dead' => 'Modem rusak', 'cpe_reboot_loop' => 'Modem restart terus', 'optical_degrade' => 'Sinyal kabel jelek',
            'pppoe_auth' => 'Modem gagal login', 'pppoe_flap' => 'Internet putus-sambung', 'pppoe_stuck' => 'Internet nyangkut',
            'wifi_client' => 'HP / laptop pelanggan', 'unknown' => 'Belum tahu',
            // unchanged
            'core_router' => 'Router mati', 'olt_down' => 'OLT mati', 'pon_cut' => 'Kabel PON putus', 'odp_cut' => 'Kabel ODP putus',
            'wlan_down' => 'WiFi mati', 'wlan_flap' => 'WiFi putus-putus', 'isolir_admin' => 'Diisolir / cuti', 'data_blind' => 'Data tidak terbaca',
            'not_fault' => 'Bukan gangguan', 'self_recovered' => 'Pulih sendiri', 'other' => 'Lainnya', 'auto_closed' => 'Ditutup otomatis',
        ];
        foreach ($expected as $code => $label) {
            $this->assertSame($label, OutageRootCause::labelFor($code), $code);
        }
        $this->assertCount(count(OutageRootCause::cases()), $expected);

        // the Deteksi row shows the same word as the ticket form's diagnosis (both read OutageRootCause::label)
        $this->signal(['state' => 'open', 'diagnosed_cause' => 'drop_los', 'cause_code' => 'cpe_dead']);
        $row = $this->getJson2($this->superadmin(), route('api.monitoring.outage-shadow'))->json('data.0');
        $this->assertSame('Kabel ke rumah putus', $row['diagnosed_label']);
        $this->assertSame('Modem rusak', $row['cause_label']);
    }

    public function test_controller_and_read_model_have_no_write_or_wa_code(): void
    {
        foreach (['app/Http/Controllers/OutageShadowController.php', 'app/Services/Outage/OutageShadowEpisodeReadModel.php'] as $file) {
            $src = (string) file_get_contents(base_path($file));
            foreach (['EvolutionApiService', 'dispatch(', 'Queue::', 'Http::', '->save(', '->update(', '->delete(', '::create(', 'Psb', 'Migration'] as $needle) {
                $this->assertStringNotContainsString($needle, $src, $file.' must stay read-only: '.$needle);
            }
        }
    }
}
