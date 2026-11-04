<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Package;
use App\Models\SalesLead;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\WaCampaign;
use App\Support\PerPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * "Baris per halaman" must change the page size on every list that shows the select,
 * keep the choice on page 2 and fall back to the page default for unknown sizes.
 */
#[Group('blocking-core')]
class PerPageListsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'superadmin', 'email_verified_at' => now()]);
    }

    public function test_helper_accepts_allowed_sizes_and_falls_back_for_the_rest(): void
    {
        $from = fn (array $query, int $default = 20) => PerPage::from(Request::create('/x', 'GET', $query), $default);

        $this->assertSame(50, $from(['limit' => '50']));
        $this->assertSame(20, $from([]));
        $this->assertSame(20, $from(['limit' => '7']));
        $this->assertSame(20, $from(['limit' => '1000']));
        $this->assertSame(20, $from(['limit' => '-5']));
        $this->assertSame(20, $from(['limit' => 'abc']));
        $this->assertSame(20, $from(['limit' => ['50']]));
        $this->assertSame(30, $from(['limit' => '30'], 30));
        $this->assertSame(30, $from(['limit' => 'x'], 30));
    }

    public function test_tickets_index_honours_limit_and_keeps_it_on_page_two(): void
    {
        for ($i = 1; $i <= 60; $i++) {
            SupportTicket::create([
                'created_by' => $this->admin->id,
                'gangguan' => 'Uji baris '.$i,
                'status' => 'open',
                'priority' => 'normal',
                'type' => 'incident',
            ]);
        }

        $this->actingAs($this->admin)->get(route('tickets.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tickets.per_page', 20)->where('filters.limit', 20)->has('tickets.data', 20));

        $this->actingAs($this->admin)->get(route('tickets.index', ['limit' => 50]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tickets.per_page', 50)->where('filters.limit', 50)->has('tickets.data', 50));

        $this->actingAs($this->admin)->get(route('tickets.index', ['limit' => 999]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('tickets.per_page', 20)->where('filters.limit', 20));

        $this->actingAs($this->admin)->get(route('tickets.index', ['limit' => 50, 'page' => 2, 'search' => 'Uji baris']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tickets.per_page', 50)->where('tickets.current_page', 2)->has('tickets.data', 10)
                ->where('tickets.prev_page_url', fn ($url) => str_contains($url, 'limit=50') && str_contains($url, 'search=Uji'))
                ->where('filters.limit', 50));
    }

    public function test_saleskit_index_honours_limit_and_keeps_it_on_page_two(): void
    {
        for ($i = 1; $i <= 60; $i++) {
            SalesLead::create(['name' => 'Calon '.$i, 'daerah' => 'UJI', 'status' => SalesLead::STATUS_CALON]);
        }

        $this->actingAs($this->admin)->get(route('saleskit.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('leads.per_page', 50)->where('filters.limit', 50)->has('leads.data', 50));

        $this->actingAs($this->admin)->get(route('saleskit.index', ['limit' => 25]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('leads.per_page', 25)->where('filters.limit', 25)->has('leads.data', 25));

        $this->actingAs($this->admin)->get(route('saleskit.index', ['limit' => 3]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('leads.per_page', 50));

        $this->actingAs($this->admin)->get(route('saleskit.index', ['limit' => 25, 'page' => 2]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('leads.per_page', 25)->where('leads.current_page', 2)
                ->where('leads.next_page_url', fn ($url) => str_contains($url, 'limit=25')));
    }

    public function test_broadcast_list_and_recipient_log_honour_limit(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            WaCampaign::create(['name' => 'Kampanye '.$i, 'message_template' => 'Tes', 'target_type' => 'all', 'status' => 'draft']);
        }

        $this->actingAs($this->admin)->get(route('broadcasts.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('campaigns.per_page', 10)->where('filters.limit', 10)->has('campaigns.data', 10));

        $this->actingAs($this->admin)->get(route('broadcasts.index', ['limit' => 25]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('campaigns.per_page', 25)->where('filters.limit', 25)->has('campaigns.data', 25));

        $this->actingAs($this->admin)->get(route('broadcasts.index', ['limit' => 25, 'page' => 2]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('campaigns.per_page', 25)->has('campaigns.data', 5));

        $this->actingAs($this->admin)->get(route('broadcasts.index', ['limit' => 'zzz']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('campaigns.per_page', 10));

        WaCampaign::create(['name' => 'Promo Lebaran', 'message_template' => 'Tes', 'target_type' => 'all', 'status' => 'completed']);

        $this->actingAs($this->admin)->get(route('broadcasts.index', ['search' => 'Lebaran']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('campaigns.total', 1)->where('filters.search', 'Lebaran'));

        $this->actingAs($this->admin)->get(route('broadcasts.index', ['status' => 'completed', 'limit' => 25]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('campaigns.total', 1)->where('filters.status', 'completed'));

        $campaign = WaCampaign::query()->where('name', 'Kampanye 1')->firstOrFail();
        $rows = [];
        for ($i = 1; $i <= 30; $i++) {
            $rows[] = [
                'wa_campaign_id' => $campaign->id,
                'phone_number' => '0800000'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('wa_campaign_recipients')->insert($rows);

        $this->actingAs($this->admin)->get(route('broadcasts.show', $campaign))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('recipients.per_page', 20)->has('recipients.data', 20));

        $this->actingAs($this->admin)->get(route('broadcasts.show', [$campaign, 'limit' => 25]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('recipients.per_page', 25)->has('recipients.data', 25));

        $this->actingAs($this->admin)->get(route('broadcasts.show', [$campaign, 'limit' => 10, 'page' => 2]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('recipients.per_page', 10)->where('recipients.current_page', 2)
                ->where('recipients.next_page_url', fn ($url) => str_contains($url, 'limit=10')));
    }

    /** Contract: the search box on Broadcasts/Show must filter the recipient log (it was ignored before). */
    public function test_broadcast_recipient_log_search_filters_and_survives_page_two(): void
    {
        $campaign = WaCampaign::create(['name' => 'Kampanye cari', 'message_template' => 'Tes', 'target_type' => 'all', 'status' => 'completed']);
        $package = Package::create(['code' => 'PP-PKG', 'name' => 'Uji', 'price' => 100000]);
        $named = Customer::create([
            'code' => 'PP-01', 'name' => 'Pelanggan Uji Cari', 'phone' => '0815000001', 'address' => 'RW 1',
            'package_id' => $package->id, 'status' => 'active', 'pppoe_user' => 'u-pp-01',
        ]);

        $rows = [];
        for ($i = 1; $i <= 30; $i++) {
            $rows[] = [
                'wa_campaign_id' => $campaign->id,
                'customer_id' => $i === 1 ? $named->id : null,
                'phone_number' => '0899'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('wa_campaign_recipients')->insert($rows);

        $this->actingAs($this->admin)->get(route('broadcasts.show', [$campaign, 'search' => '0899000007']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('recipients.total', 1)->where('filters.search', '0899000007'));

        $this->actingAs($this->admin)->get(route('broadcasts.show', [$campaign, 'search' => 'Uji Cari']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('recipients.total', 1));

        $this->actingAs($this->admin)->get(route('broadcasts.show', [$campaign, 'search' => 'zzz-tidak-ada']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('recipients.total', 0));

        // Array input (?search[]=x) must not break the page, in the log or in the list.
        $this->actingAs($this->admin)->get(route('broadcasts.show', $campaign).'?search[]=x')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('recipients.total', 30)->where('filters.search', ''));
        $this->actingAs($this->admin)->get(route('broadcasts.index').'?search[]=x')->assertOk();

        // A LIKE wildcard typed by the user is a literal, not "match everything".
        $this->actingAs($this->admin)->get(route('broadcasts.show', [$campaign, 'search' => '%']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('recipients.total', 0));

        $this->actingAs($this->admin)->get(route('broadcasts.show', [$campaign, 'search' => '0899', 'limit' => 10, 'page' => 2]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('recipients.total', 30)->where('recipients.current_page', 2)->has('recipients.data', 10)
                ->where('recipients.next_page_url', fn ($url) => str_contains($url, 'limit=10') && str_contains($url, 'search=0899')));
    }
}
