<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Router;
use App\Models\RouterStagedCustomer;
use App\Models\User;
use App\Services\MikrotikService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Daftar "secret belum terhubung" + Tambah pelanggan dari secret (hanya database).
 */
#[Group('blocking-core')]
class RouterUnlinkedSecretsTest extends TestCase
{
    use RefreshDatabase;

    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();

        $this->router = $this->makeRouter('Router Uji A', '198.18.22.1');
    }

    // ---- daftar ---------------------------------------------------------

    public function test_list_is_global_admin_only(): void
    {
        $url = route('api.routers.unlinked-secrets.index', $this->router);

        $this->actingAs($this->scopedAdmin())->getJson($url)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'teknisi']))->getJson($url)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'noc']))->getJson($url)->assertOk();
        $this->actingAs(User::factory()->create(['role' => 'superadmin']))->getJson($url)->assertOk();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->getJson($url)->assertOk();
    }

    public function test_list_paginates_25_per_page_and_page_two_reaches_the_backend(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->staged('useruji_rt01_rw01_odp.'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        }
        $this->actingAs($this->noc());

        $one = $this->getJson(route('api.routers.unlinked-secrets.index', $this->router))->assertOk();
        $this->assertSame(30, $one->json('total'));
        $this->assertSame(25, $one->json('per_page'));
        $this->assertCount(25, $one->json('data'));
        $this->assertSame('useruji_rt01_rw01_odp.01', $one->json('data.0.pppoe_user'));

        // per_page dari klien diabaikan; halaman 2 benar-benar sampai ke backend.
        $two = $this->getJson(route('api.routers.unlinked-secrets.index', $this->router).'?page=2&per_page=5&limit=5')->assertOk();
        $this->assertSame(2, $two->json('current_page'));
        $this->assertCount(5, $two->json('data'));
        $this->assertSame('useruji_rt01_rw01_odp.26', $two->json('data.0.pppoe_user'));

        $this->assertTrue($two->json('meta.router_active'));
        $this->assertTrue($two->json('meta.can_add'));
    }

    public function test_list_search_treats_underscore_as_literal_and_stays_on_this_router(): void
    {
        $this->staged('useruji_rt01_rw01_a');
        $this->staged('ujixrt01xrw01xb');
        $this->staged('uji%persen');
        $other = $this->makeRouter('Router Uji B', '198.18.22.2');
        $this->staged('useruji_rt01_rw01_lain', [], $other);
        $this->actingAs($this->noc());
        $url = route('api.routers.unlinked-secrets.index', $this->router);

        $this->assertSame(['useruji_rt01_rw01_a'], collect($this->getJson($url.'?search=uji_rt01')->json('data'))->pluck('pppoe_user')->all());
        $this->assertSame(['uji%persen'], collect($this->getJson($url.'?search=i%25p')->json('data'))->pluck('pppoe_user')->all());
        $this->getJson($url.'?search='.str_repeat('a', 65))->assertStatus(422);
    }

    public function test_list_only_has_unmatched_rows_without_a_customer(): void
    {
        $package = $this->package('P1', 'prof-a');
        $customer = $this->customer($package, 'sudah.cocok');
        $this->staged('masih.sendiri');
        $this->staged('sudah.cocok', ['status' => 'matched', 'matched_customer_id' => $customer->id]);
        $this->staged('sudah.hilang', ['status' => 'gone']);
        $this->actingAs($this->noc());

        $rows = $this->getJson(route('api.routers.unlinked-secrets.index', $this->router))->json('data');

        $this->assertSame(['masih.sendiri'], collect($rows)->pluck('pppoe_user')->all());
    }

    public function test_list_never_exposes_payload_comment_or_credentials_and_ip_must_be_valid(): void
    {
        $this->staged('ip.valid', ['comment' => 'CATATAN-NAMA-RAHASIA', 'raw_payload' => ['remote-address' => '198.18.7.7', 'comment' => 'CATATAN-NAMA-RAHASIA']]);
        $this->staged('ip.rusak', ['raw_payload' => ['remote-address' => 'bukan-ip']]);
        $this->staged('ip.kosong');
        $this->actingAs($this->noc());

        $response = $this->getJson(route('api.routers.unlinked-secrets.index', $this->router))->assertOk();
        $byUser = collect($response->json('data'))->keyBy('pppoe_user');

        $this->assertSame('198.18.7.7', $byUser['ip.valid']['ip']);
        $this->assertNull($byUser['ip.rusak']['ip']);
        $this->assertNull($byUser['ip.kosong']['ip']);

        $json = $response->getContent();
        $this->assertStringNotContainsString('CATATAN-NAMA-RAHASIA', $json);
        $this->assertStringNotContainsString('raw_payload', $json);
        $this->assertStringNotContainsString('comment', $json);
        $this->assertStringNotContainsString('password', $json);
        $this->assertSame(['id', 'pppoe_user', 'profile', 'disabled', 'last_seen_at', 'ip'], array_keys($response->json('data.0')));
    }

    public function test_archived_router_list_is_view_only(): void
    {
        $this->router->forceFill(['is_active' => false])->save();
        $this->staged('router.arsip');
        $this->actingAs($this->noc());

        $response = $this->getJson(route('api.routers.unlinked-secrets.index', $this->router))->assertOk();

        $this->assertFalse($response->json('meta.router_active'));
        $this->assertFalse($response->json('meta.can_add'));
    }

    // ---- isi awal form --------------------------------------------------

    public function test_prefill_selects_the_only_matching_package_and_sets_status_aktif(): void
    {
        $this->router->forceFill(['isolation_profile' => 'isolir-uji'])->save();
        $package = $this->package('Paket 10', 'prof-10');
        $this->package('Paket Draf', 'prof-10', ['price' => 0]);
        $this->package('Paket Mati', 'prof-10', ['is_active' => false]);
        $row = $this->staged('useruji_rt02_rw01_a', ['profile' => 'prof-10']);

        $prefill = $this->prefillFor($row);

        $this->assertSame($row->id, $prefill['router_staged_customer_id']);
        $this->assertSame($this->router->id, $prefill['router_id']);
        $this->assertSame('useruji_rt02_rw01_a', $prefill['pppoe_user']);
        $this->assertSame($package->id, $prefill['package_id']);
        $this->assertNull($prefill['package_hint']);
        $this->assertSame('active', $prefill['status']);
    }

    public function test_prefill_leaves_package_empty_when_profile_is_ambiguous_or_unknown(): void
    {
        $this->package('Paket X1', 'prof-dua');
        $this->package('Paket X2', 'prof-dua');
        $this->package('Paket Draf', 'prof-draf', ['price' => 0]);

        $ambiguous = $this->prefillFor($this->staged('uji.dua', ['profile' => 'prof-dua']));
        $this->assertNull($ambiguous['package_id']);
        $this->assertSame('Profil prof-dua dipakai 2 paket, pilih paket.', $ambiguous['package_hint']);

        $none = $this->prefillFor($this->staged('uji.nol', ['profile' => 'prof-tidak-ada']));
        $this->assertNull($none['package_id']);
        $this->assertSame('Belum ada paket untuk profil prof-tidak-ada.', $none['package_hint']);

        $draft = $this->prefillFor($this->staged('uji.draf', ['profile' => 'prof-draf']));
        $this->assertNull($draft['package_id']);
    }

    public function test_prefill_status_stays_pending_when_secret_is_disabled_or_on_isolir_profile(): void
    {
        $this->router->forceFill(['isolation_profile' => 'isolir-uji'])->save();
        $this->package('Paket Isolir', 'isolir-uji');
        $this->package('Paket Biasa', 'prof-biasa');

        $isolir = $this->prefillFor($this->staged('uji.isolir', ['profile' => 'isolir-uji']));
        $this->assertSame('pending_installation', $isolir['status']);
        $this->assertNull($isolir['package_id']);
        $this->assertSame('Secret sedang di profil isolir, pilih paket aslinya.', $isolir['package_hint']);

        $disabled = $this->prefillFor($this->staged('uji.mati', ['profile' => 'prof-biasa', 'disabled' => true]));
        $this->assertSame('pending_installation', $disabled['status']);
    }

    public function test_prefill_hints_at_a_lookalike_customer_but_never_blocks(): void
    {
        $package = $this->package('Paket 10', 'prof-10');
        $known = $this->customer($package, 'USERMIRIP_RT02_RW03_ODP.1A');
        $row = $this->staged('usermirip_rt02_rw03_odp.2b', ['profile' => 'prof-10']);

        $prefill = $this->prefillFor($row);
        $this->assertSame("Mirip pelanggan #{$known->id} (USERMIRIP_RT02_RW03_ODP.1A). Cek dulu, mungkin secret lama pelanggan itu.", $prefill['lookalike_hint']);

        // Hanya saran: menyimpan tetap jalan.
        $this->actingAs($this->noc())->post(route('customers.store'), $this->payload($row, $package))
            ->assertRedirect(route('routers.show', ['router' => $this->router->id, 'tab' => 'customers', 'section' => 'unlinked']));
        $this->assertSame('matched', $row->fresh()->status);

        // Tanpa pelanggan yang mirip tidak ada saran.
        $plain = $this->prefillFor($this->staged('userlain_rt09_rw09_odp.1a', ['profile' => 'prof-10']));
        $this->assertNull($plain['lookalike_hint']);
    }

    public function test_prefill_is_refused_for_linked_gone_or_archived_secrets(): void
    {
        $this->actingAs($this->noc());
        $matched = $this->staged('uji.cocok', ['status' => 'matched']);
        $gone = $this->staged('uji.hilang', ['status' => 'gone']);
        $open = $this->staged('uji.arsip');
        $this->router->forceFill(['is_active' => false])->save();

        foreach ([$matched, $gone, $open] as $row) {
            $this->get(route('customers.create', ['staged' => $row->id]))
                ->assertRedirect(route('routers.show', $this->router))
                ->assertSessionHas('error', 'Secret ini sudah terhubung atau sudah hilang dari router.');
        }
        $this->get(route('customers.create', ['staged' => 999999]))->assertRedirect(route('routers.index'));
    }

    public function test_prefill_is_ignored_for_scoped_admin_and_absent_without_param(): void
    {
        $row = $this->staged('uji.scoped');
        $page = $this->actingAs($this->scopedAdmin())->get(route('customers.create', ['staged' => $row->id]))->assertOk();
        $this->assertNull($page->viewData('page')['props']['prefill']);

        $plain = $this->actingAs($this->noc())->get(route('customers.create'))->assertOk();
        $this->assertNull($plain->viewData('page')['props']['prefill']);
    }

    // ---- simpan ---------------------------------------------------------

    public function test_store_links_the_secret_without_any_mikrotik_call_and_keeps_password_empty(): void
    {
        // Mock tanpa ekspektasi: panggilan apa pun ke MikroTik (termasuk addPPPSecret) membuat tes gagal.
        $this->app->instance(MikrotikService::class, Mockery::mock(MikrotikService::class));
        $this->router->forceFill(['radius_enabled' => true])->save();
        $package = $this->package('Paket 10', 'prof-10');
        $row = $this->staged('useruji_rt03_rw01_a', ['profile' => 'prof-10']);

        $response = $this->actingAs($this->noc())->post(route('customers.store'), $this->payload($row, $package));

        $response->assertRedirect(route('routers.show', ['router' => $this->router->id, 'tab' => 'customers', 'section' => 'unlinked']));
        $response->assertSessionHas('success', 'Pelanggan dibuat dan terhubung ke secret useruji_rt03_rw01_a.');
        $customer = Customer::where('pppoe_user', 'useruji_rt03_rw01_a')->firstOrFail();
        $this->assertSame($this->router->id, $customer->router_id);
        $this->assertNull($customer->pppoe_password);
        $row->refresh();
        $this->assertSame('matched', $row->status);
        $this->assertSame($customer->id, $row->matched_customer_id);
        // Tidak ada baris RADIUS kosong untuk jalur ini.
        $this->assertSame(0, DB::table('radcheck')->where('username', 'useruji_rt03_rw01_a')->count());
        // Daftar tidak memuatnya lagi.
        $this->assertSame([], $this->getJson(route('api.routers.unlinked-secrets.index', $this->router))->json('data'));
    }

    public function test_store_aktif_from_secret_bills_like_any_normal_aktif_create(): void
    {
        $this->app->instance(MikrotikService::class, Mockery::mock(MikrotikService::class));
        $package = $this->package('Paket 10', 'prof-10');
        $row = $this->staged('useruji_rt03_rw02_a', ['profile' => 'prof-10']);
        $this->actingAs($this->noc());

        Carbon::setTestNow(Carbon::parse('2026-11-10 10:00:00', 'Asia/Jakarta'));
        try {
            $this->post(route('customers.store'), ['pppoe_user' => 'uji.biasa', 'name' => 'SITI CONTOH', 'router_staged_customer_id' => null] + $this->payload($row, $package));
            $normal = Customer::where('pppoe_user', 'uji.biasa')->firstOrFail();
            $this->post(route('customers.store'), $this->payload($row, $package));
            $linked = Customer::where('pppoe_user', 'useruji_rt03_rw02_a')->firstOrFail();

            $this->assertSame('active', $normal->status);
            $this->assertSame('active', $linked->status);
            $this->assertSame($normal->due_day, $linked->due_day);

            // Bulan bergabung tidak ditagih: catch-up harian melewati keduanya sebagai join_month.
            Artisan::call('billing:generate', ['--catch-up' => true]);
            $this->assertStringContainsString('"join_month":2', Artisan::output());
            $this->assertSame(0, Invoice::whereIn('customer_id', [$normal->id, $linked->id])->count());

            // Tagihan pertama = run bulanan tanggal 1 bulan berikutnya, sama untuk keduanya.
            Carbon::setTestNow(Carbon::parse('2026-12-01 07:00:00', 'Asia/Jakarta'));
            Artisan::call('billing:generate');
            foreach ([$normal, $linked] as $customer) {
                $this->assertSame(1, Invoice::where('customer_id', $customer->id)->count());
                $this->assertSame('2026-12-01', Invoice::where('customer_id', $customer->id)->firstOrFail()->period->toDateString());
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_store_refuses_when_the_secret_gets_linked_between_validation_and_save(): void
    {
        $package = $this->package('Paket 10', 'prof-10');
        $row = $this->staged('useruji_rt03_rw06_a', ['profile' => 'prof-10']);
        $other = $this->customer($package, 'uji.pelanggan.lain');
        $this->linkAfterValidation($row, $other);

        $this->actingAs($this->noc())->post(route('customers.store'), $this->payload($row, $package))
            ->assertSessionHasErrors(['router_staged_customer_id' => 'Secret sudah berubah. Buka lagi dari halaman router.']);

        $this->assertSame(0, Customer::where('pppoe_user', 'useruji_rt03_rw06_a')->count());
        $this->assertSame($other->id, $row->fresh()->matched_customer_id);
    }

    public function test_store_refuses_when_the_router_was_archived_while_the_form_was_open(): void
    {
        $package = $this->package('Paket 10', 'prof-10');
        $row = $this->staged('useruji_rt03_rw07_a', ['profile' => 'prof-10']);
        $this->router->forceFill(['is_active' => false])->save();

        $this->actingAs($this->noc())->post(route('customers.store'), $this->payload($row, $package))
            ->assertSessionHasErrors(['router_staged_customer_id' => 'Router ini sudah diarsipkan.']);

        $this->assertSame(0, Customer::where('pppoe_user', 'useruji_rt03_rw07_a')->count());
        $this->assertSame('unmatched', $row->fresh()->status);
    }

    public function test_a_refused_save_leaves_no_ktp_file_behind(): void
    {
        Storage::fake('private');
        $package = $this->package('Paket 10', 'prof-10');
        $row = $this->staged('useruji_rt03_rw08_a', ['profile' => 'prof-10']);
        // Validasi lolos, lalu baris dicocokkan pihak lain sebelum transaksi: foto sudah tidak boleh tersimpan.
        $this->linkAfterValidation($row, $this->customer($package, 'uji.pelanggan.lain'));

        $this->actingAs($this->noc())->post(route('customers.store'), [
            'ktp_photo' => UploadedFile::fake()->image('ktp.jpg', 200, 200),
        ] + $this->payload($row, $package))->assertSessionHasErrors('router_staged_customer_id');

        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_a_valid_save_still_stores_the_ktp_photo(): void
    {
        Storage::fake('private');
        $package = $this->package('Paket 10', 'prof-10');
        $row = $this->staged('useruji_rt03_rw09_a', ['profile' => 'prof-10']);

        $this->actingAs($this->noc())->post(route('customers.store'), [
            'ktp_photo' => UploadedFile::fake()->image('ktp.jpg', 200, 200),
        ] + $this->payload($row, $package));

        $path = (string) Customer::where('pppoe_user', 'useruji_rt03_rw09_a')->firstOrFail()->getRawOriginal('ktp_photo_url');
        $this->assertNotEmpty($path);
        Storage::disk('private')->assertExists($path);
    }

    public function test_store_rejects_a_changed_secret_and_creates_nothing(): void
    {
        $package = $this->package('Paket 10', 'prof-10');
        $row = $this->staged('useruji_rt03_rw03_a', ['profile' => 'prof-10']);
        $other = $this->makeRouter('Router Uji C', '198.18.22.3');
        $this->actingAs($this->noc());

        $wrongUser = ['pppoe_user' => 'useruji_rt03_rw03_b'] + $this->payload($row, $package);
        $this->post(route('customers.store'), $wrongUser)->assertSessionHasErrors(['router_staged_customer_id' => 'Secret sudah berubah. Buka lagi dari halaman router.']);

        $wrongCase = ['pppoe_user' => 'USERUJI_RT03_RW03_A'] + $this->payload($row, $package);
        $this->post(route('customers.store'), $wrongCase)->assertSessionHasErrors('router_staged_customer_id');

        $wrongRouter = ['router_id' => $other->id] + $this->payload($row, $package);
        $this->post(route('customers.store'), $wrongRouter)->assertSessionHasErrors('router_staged_customer_id');

        $row->forceFill(['status' => 'gone'])->save();
        $this->post(route('customers.store'), $this->payload($row, $package))->assertSessionHasErrors('router_staged_customer_id');

        $this->assertSame(0, Customer::count());
    }

    public function test_store_link_is_refused_for_scoped_admin(): void
    {
        $package = $this->package('Paket 10', 'prof-10');
        $row = $this->staged('useruji_rt03_rw04_a', ['profile' => 'prof-10']);
        $scoped = $this->scopedAdmin();

        $this->actingAs($scoped)->post(route('customers.store'), ['area_id' => $scoped->areas()->value('areas.id')] + $this->payload($row, $package))
            ->assertSessionHasErrors('router_staged_customer_id');

        $this->assertSame(0, Customer::count());
        $this->assertSame('unmatched', $row->fresh()->status);
    }

    public function test_store_says_clearly_when_a_deleted_customer_owns_the_username(): void
    {
        $package = $this->package('Paket 10', 'prof-10');
        $old = $this->customer($package, 'useruji_rt03_rw05_a');
        $old->delete();
        $row = $this->staged('useruji_rt03_rw05_a', ['profile' => 'prof-10']);

        $this->actingAs($this->noc())->post(route('customers.store'), $this->payload($row, $package))
            ->assertSessionHasErrors(['pppoe_user' => 'Username ini masih dipakai pelanggan lama (terhapus). Pulihkan pelanggan itu.']);

        $this->assertSame('unmatched', $row->fresh()->status);
    }

    public function test_normal_create_without_a_secret_is_unchanged(): void
    {
        $package = $this->package('Paket 10', 'prof-10');
        $this->router->forceFill(['radius_enabled' => true])->save();

        $this->actingAs($this->noc())->post(route('customers.store'), [
            'name' => 'BUDI CONTOH',
            'address' => 'Alamat uji',
            'pppoe_user' => 'uji.manual',
            'package_id' => $package->id,
            'router_id' => $this->router->id,
            'status' => 'pending_installation',
        ])->assertRedirect(route('customers.index'));

        $this->assertDatabaseHas('customers', ['pppoe_user' => 'uji.manual']);
        $this->assertGreaterThan(0, DB::table('radcheck')->where('username', 'uji.manual')->count());
    }

    // ---- helper ---------------------------------------------------------

    /** Baca pertama baris = validasi. Sesudahnya baris dicocokkan pihak lain (admin kedua / sinkron). */
    private function linkAfterValidation(RouterStagedCustomer $row, Customer $other): void
    {
        $reads = 0;
        RouterStagedCustomer::retrieved(function (RouterStagedCustomer $model) use (&$reads, $row, $other): void {
            if ($model->id === $row->id && ++$reads === 1) {
                DB::table('router_staged_customers')->where('id', $row->id)
                    ->update(['status' => 'matched', 'matched_customer_id' => $other->id]);
            }
        });
    }

    private function noc(): User
    {
        return User::factory()->create(['role' => 'noc']);
    }

    /** Admin dengan batas area (bukan admin penuh). */
    private function scopedAdmin(): User
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->areas()->attach(Area::create(['code' => 'AREA-UJI', 'name' => 'Area Uji']));

        return $admin;
    }

    private function makeRouter(string $name, string $ip): Router
    {
        $router = new Router([
            'name' => $name,
            'ip_address' => $ip,
            'port' => 8728,
            'is_active' => true,
        ]);
        // Kredensial router hanya diisi supaya baris valid; tidak pernah dipakai (tanpa koneksi ke router).
        $router->forceFill(['username' => $name]);
        $router->forceFill(['password' => 'canary-pw']);
        $router->save();

        return $router;
    }

    /** @param  array<string, mixed>  $extra */
    private function package(string $name, string $profile, array $extra = []): Package
    {
        return Package::create($extra + [
            'name' => $name,
            'code' => 'PKG-'.uniqid(),
            'mikrotik_profile' => $profile,
            'router_id' => $this->router->id,
            'price' => 150000,
            'is_active' => true,
        ]);
    }

    private function customer(Package $package, string $pppoeUser): Customer
    {
        return Customer::create([
            'code' => 'CUST-'.uniqid(),
            'name' => 'SITI CONTOH',
            'phone' => '6281200000001',
            'address' => 'Alamat uji',
            'pppoe_user' => $pppoeUser,
            'router_id' => $this->router->id,
            'package_id' => $package->id,
            'status' => 'active',
            'join_date' => now(),
        ]);
    }

    /** @param  array<string, mixed>  $extra */
    private function staged(string $pppoeUser, array $extra = [], ?Router $router = null): RouterStagedCustomer
    {
        return RouterStagedCustomer::create($extra + [
            'router_id' => ($router ?? $this->router)->id,
            'pppoe_user' => $pppoeUser,
            'profile' => 'prof-10',
            'disabled' => false,
            'status' => 'unmatched',
            'first_seen_at' => now()->subDay(),
            'last_seen_at' => now()->subHour(),
        ]);
    }

    /** @return array<string, mixed> */
    private function prefillFor(RouterStagedCustomer $row): array
    {
        $page = $this->actingAs($this->noc())->get(route('customers.create', ['staged' => $row->id]))->assertOk();

        return $page->viewData('page')['props']['prefill'];
    }

    /** @return array<string, mixed> */
    private function payload(RouterStagedCustomer $row, Package $package): array
    {
        return [
            'name' => 'BUDI CONTOH',
            'address' => 'Alamat uji',
            'pppoe_user' => $row->pppoe_user,
            'package_id' => $package->id,
            'router_id' => $row->router_id,
            'status' => 'active',
            'router_staged_customer_id' => $row->id,
        ];
    }
}
