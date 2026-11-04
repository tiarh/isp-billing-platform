<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Olt;
use App\Models\Package;
use App\Models\Router;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Canary;
use Tests\TestCase;

#[Group('blocking-core')]
class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    private Area $areaA;

    private Area $areaB;

    private Package $package;

    protected function setUp(): void
    {
        parent::setUp();
        $this->areaA = Area::create(['name' => 'Wilayah Alfa', 'code' => 'ALFA']);
        $this->areaB = Area::create(['name' => 'Wilayah Bravo', 'code' => 'BRAVO']);
        $this->package = Package::create(['name' => 'Paket Uji', 'code' => 'PKG-GS-'.uniqid(), 'price' => 100000]);
    }

    private function customer(string $code, string $name, Area $area, array $extra = []): Customer
    {
        return Customer::create(array_merge([
            'code' => $code,
            'name' => $name,
            'phone' => '081200000'.random_int(100, 999),
            'address' => 'Alamat Rahasia Uji',
            'nik' => '3200000000000001',
            'pppoe_user' => strtolower($code).'.ppp',
            'package_id' => $this->package->id,
            'area_id' => $area->id,
            'status' => 'active',
        ], $extra));
    }

    private function invoice(Customer $c, string $suffix = 'AAAA'): Invoice
    {
        return Invoice::create([
            'code' => 'INV-202610-'.$c->code.'-'.$suffix,
            'customer_id' => $c->id,
            'period' => '2026-10-01',
            'amount' => 150000,
            'status' => 'unpaid',
            'due_date' => '2026-10-20',
        ]);
    }

    private function ticket(Customer $c, string $subject, ?User $assignee = null): SupportTicket
    {
        return SupportTicket::create([
            'customer_id' => $c->id,
            'assigned_to' => $assignee?->id,
            'subject' => $subject,
            'gangguan' => $subject,
            'status' => 'open',
            'priority' => 'normal',
            'type' => 'incident',
        ]);
    }

    private function scopedAdmin(Area $area): User
    {
        $u = User::factory()->create(['role' => 'admin']);
        $u->areas()->attach($area->id);

        return $u->fresh();
    }

    private function search(User $user, string $q)
    {
        return $this->actingAs($user)->postJson('/api/global-search', ['q' => $q]);
    }

    private function items($response, string $group): array
    {
        $g = collect($response->json('groups'))->firstWhere('key', $group);

        return $g['items'] ?? [];
    }

    public function test_guest_is_rejected(): void
    {
        $this->postJson('/api/global-search', ['q' => 'abc'])->assertUnauthorized();
    }

    public function test_superadmin_finds_customer_by_code_name_pppoe_sn_and_phone_and_sees_phone(): void
    {
        $su = User::factory()->create(['role' => 'superadmin']);
        $c = $this->customer('CUST-GS1', 'Budi Fiktif', $this->areaA, ['onu_serial' => 'ZTEG0000A005', 'phone' => '081200000027']);

        foreach (['CUST-GS1', 'budi fik', 'cust-gs1.ppp', 'ZTEG0000A0', '08120000'] as $q) {
            $items = $this->items($this->search($su, $q)->assertOk(), 'customers');
            $this->assertCount(1, $items, $q);
            $this->assertSame('Budi Fiktif', $items[0]['title']);
            $this->assertSame('/customers/'.$c->id, $items[0]['href']);
        }

        // Phone is intentionally shown (owner Q15) as text, but never as a dedicated/other-PII field.
        $items = $this->items($this->search($su, 'CUST-GS1'), 'customers');
        $this->assertStringContainsString('081200000027', $items[0]['subtitle']);
        $this->assertStringContainsString('Wilayah Alfa', $items[0]['subtitle']);
        $this->assertSame('Aktif', $items[0]['badge']['label']);
    }

    public function test_phone_with_international_prefix_matches(): void
    {
        $su = User::factory()->create(['role' => 'superadmin']);
        $this->customer('CUST-GS2', 'Sari Fiktif', $this->areaA, ['phone' => '081200000028']);

        $this->assertCount(1, $this->items($this->search($su, '+6281200000028'), 'customers'));
    }

    public function test_invoice_ticket_and_network_groups_for_superadmin(): void
    {
        $su = User::factory()->create(['role' => 'superadmin']);
        $c = $this->customer('CUST-GS3', 'Dewi Fiktif', $this->areaA);
        $inv = $this->invoice($c);
        $t = $this->ticket($c, 'Internet putus total');
        Router::create(['name' => 'Router Uji Alfa', 'ip_address' => '198.18.26.9', 'username' => 'u', 'password' => Canary::of('p'), 'port' => 8728, 'is_active' => true]);
        Olt::create(['name' => 'OLT Uji Alfa', 'code' => 'OLT-GS', 'management_ip' => '198.18.26.10']);

        $this->assertSame($inv->code, $this->items($this->search($su, 'INV-202610-CUST-GS3'), 'invoices')[0]['title']);
        $this->assertSame($t->code, $this->items($this->search($su, $t->code), 'tickets')[0]['title']);
        $this->assertCount(1, $this->items($this->search($su, 'putus'), 'tickets'));

        $network = $this->items($this->search($su, 'Uji Alfa'), 'network');
        $this->assertCount(2, $network);
        $json = json_encode($this->search($su, 'Uji Alfa')->json());
        $this->assertStringNotContainsString('198.18.26', $json);
        $this->assertStringNotContainsString('password', $json);
    }

    public function test_scoped_admin_never_sees_other_area_data_and_no_network(): void
    {
        $scoped = $this->scopedAdmin($this->areaA);
        $mine = $this->customer('CUST-MINE', 'Pelanggan Sendiri', $this->areaA);
        $other = $this->customer('CUST-OTH', 'Pelanggan Sendiri Lain', $this->areaB);
        $this->invoice($mine, 'MINE');
        $this->invoice($other, 'OTHR');
        $this->ticket($mine, 'Tiket sendiri uji');
        $this->ticket($other, 'Tiket sendiri uji lain');
        Router::create(['name' => 'Pelanggan Router', 'ip_address' => '198.18.26.11', 'username' => 'u', 'password' => Canary::of('p'), 'port' => 8728, 'is_active' => true]);

        $res = $this->search($scoped, 'Pelanggan Sendiri')->assertOk();
        $this->assertCount(1, $this->items($res, 'customers'));
        $this->assertSame('Pelanggan Sendiri', $this->items($res, 'customers')[0]['title']);

        $this->assertCount(0, $this->items($this->search($scoped, 'CUST-OTH'), 'customers'));
        $this->assertCount(1, $this->items($this->search($scoped, 'INV-202610'), 'invoices'));
        $this->assertCount(0, $this->items($this->search($scoped, 'INV-202610-CUST-OTH'), 'invoices'));
        $this->assertCount(1, $this->items($this->search($scoped, 'Tiket sendiri'), 'tickets'));
        $this->assertCount(0, $this->items($this->search($scoped, 'Router'), 'network'));
        $this->assertCount(0, $this->items($this->search($scoped, 'Wilayah'), 'network'));
    }

    public function test_scoped_admin_without_matching_area_phone_search_is_scoped_too(): void
    {
        $scoped = $this->scopedAdmin($this->areaA);
        $this->customer('CUST-PH', 'Orang Lain', $this->areaB, ['phone' => '081200000045']);

        $this->assertCount(0, $this->items($this->search($scoped, '08129990'), 'customers'));
    }

    public function test_teknisi_sees_only_own_tickets_and_no_network(): void
    {
        $tech = User::factory()->create(['role' => 'teknisi']);
        $other = User::factory()->create(['role' => 'teknisi']);
        $c = $this->customer('CUST-TK', 'Pelanggan Teknisi', $this->areaA);
        $this->ticket($c, 'Gangguan milik saya', $tech);
        $this->ticket($c, 'Gangguan milik orang', $other);
        $this->ticket($c, 'Gangguan tanpa teknisi');
        Router::create(['name' => 'Gangguan Router', 'ip_address' => '198.18.26.12', 'username' => 'u', 'password' => Canary::of('p'), 'port' => 8728, 'is_active' => true]);

        $res = $this->search($tech, 'Gangguan')->assertOk();
        $this->assertCount(1, $this->items($res, 'tickets'));
        $this->assertSame([], $this->items($res, 'network'));

        // Mirrors the existing Pelanggan page access (Q14).
        $this->assertCount(1, $this->items($this->search($tech, 'CUST-TK'), 'customers'));
    }

    public function test_noc_gets_network_group(): void
    {
        $noc = User::factory()->create(['role' => 'noc']);
        Area::create(['name' => 'Zona Noc', 'code' => 'ZNOC']);

        $this->assertCount(1, $this->items($this->search($noc, 'Zona Noc'), 'network'));
    }

    public function test_archived_customers_are_included_and_labelled_arsip(): void
    {
        $su = User::factory()->create(['role' => 'superadmin']);
        $c = $this->customer('CUST-ARS', 'Pelanggan Arsip', $this->areaA);
        $c->delete();

        $items = $this->items($this->search($su, 'CUST-ARS'), 'customers');
        $this->assertCount(1, $items);
        $this->assertSame('Arsip', $items[0]['badge']['label']);
        $this->assertStringContainsString('archive=1', $items[0]['href']);
    }

    public function test_archived_customer_in_other_area_stays_hidden_from_scoped_admin(): void
    {
        $scoped = $this->scopedAdmin($this->areaA);
        $this->customer('CUST-ARS2', 'Arsip Bravo', $this->areaB)->delete();

        $this->assertCount(0, $this->items($this->search($scoped, 'CUST-ARS2'), 'customers'));
    }

    public function test_mikrotik_imported_customers_are_excluded_like_the_list(): void
    {
        $su = User::factory()->create(['role' => 'superadmin']);
        $this->customer('IMP-9001', 'Impor Mikrotik Uji', $this->areaA);
        $this->customer('IMP-9002', 'Impor Mikrotik Arsip', $this->areaA)->delete();

        $this->assertCount(0, $this->items($this->search($su, 'Impor Mikrotik'), 'customers'));
        $this->assertCount(0, $this->items($this->search($su, 'IMP-9001'), 'customers'));
    }

    public function test_archived_link_falls_back_to_pppoe_user_when_code_is_empty(): void
    {
        $su = User::factory()->create(['role' => 'superadmin']);
        $c = $this->customer('CUST-NOCODE', 'Tanpa Kode Uji', $this->areaA, ['pppoe_user' => 'nocode.ppp']);
        $c->forceFill(['code' => null])->save();
        $c->delete();

        $items = $this->items($this->search($su, 'Tanpa Kode'), 'customers');
        $this->assertCount(1, $items);
        $this->assertStringContainsString('search=nocode.ppp', $items[0]['href']);
    }

    public function test_status_badges_use_indonesian_labels(): void
    {
        $su = User::factory()->create(['role' => 'superadmin']);
        $c = $this->customer('CUST-LBL', 'Label Uji', $this->areaA, ['status' => 'pending_installation']);
        $t = $this->ticket($c, 'Label tiket uji');
        $t->forceFill(['status' => 'in_progress'])->save();

        $this->assertSame('Menunggu Instalasi', $this->items($this->search($su, 'CUST-LBL'), 'customers')[0]['badge']['label']);
        $this->assertSame('Proses', $this->items($this->search($su, 'Label tiket'), 'tickets')[0]['badge']['label']);
    }

    public function test_validation_rejects_short_long_and_bad_characters(): void
    {
        $su = User::factory()->create(['role' => 'superadmin']);

        $this->search($su, 'a')->assertStatus(422);
        $this->search($su, str_repeat('a', 65))->assertStatus(422);
        $this->search($su, "x'; DROP TABLE customers;--")->assertStatus(422);
        $this->search($su, '<script>')->assertStatus(422);
    }

    public function test_like_wildcards_are_escaped(): void
    {
        $su = User::factory()->create(['role' => 'superadmin']);
        $this->customer('CUST-WC', 'Wildcard Uji', $this->areaA);

        $this->search($su, '%%')->assertStatus(422);
        $this->assertSame([], $this->search($su, '__')->json('groups'));
    }

    public function test_results_never_contain_sensitive_keys_or_values(): void
    {
        $su = User::factory()->create(['role' => 'superadmin']);
        $c = $this->customer('CUST-SEC', 'Rahasia Uji', $this->areaA, ['geo_lat' => '-6.2', 'geo_long' => '106.8']);
        $this->invoice($c, 'SECA');
        $this->ticket($c, 'Rahasia tiket uji');

        $body = $this->search($su, 'Rahasia')->assertOk()->getContent();
        foreach (['nik', 'address', 'geo_lat', 'geo_long', 'password', 'ip_address', 'host', 'Alamat Rahasia Uji', '3200000000000001'] as $needle) {
            $this->assertStringNotContainsString($needle, $body, $needle);
        }
    }

    public function test_max_five_items_per_group(): void
    {
        $su = User::factory()->create(['role' => 'superadmin']);
        for ($i = 1; $i <= 8; $i++) {
            $this->customer('CUST-MX'.$i, 'Massal Uji '.$i, $this->areaA);
        }

        $this->assertCount(5, $this->items($this->search($su, 'Massal Uji'), 'customers'));
    }

    public function test_throttle_limits_to_60_per_minute(): void
    {
        $su = User::factory()->create(['role' => 'superadmin']);

        for ($i = 0; $i < 60; $i++) {
            $this->search($su, 'nomatch')->assertOk();
        }
        $this->search($su, 'nomatch')->assertStatus(429);
    }
}
