<?php

namespace Tests\Feature;

use App\Models\SalesLead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SalesLeadTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::factory()->create([
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }

    private function seedCustomer(string $phone, string $name = 'CALON PELANGGAN'): int
    {
        $areaId = DB::table('areas')->insertGetId([
            'code' => 'AREA-'.uniqid(),
            'name' => 'AREA TEST',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $packageId = DB::table('packages')->insertGetId([
            'code' => 'PKG-'.uniqid(),
            'name' => 'Paket Test',
            'price' => 100000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('customers')->insertGetId([
            'code' => 'CUST-'.uniqid(),
            'name' => $name,
            'phone' => $phone,
            'address' => 'Alamat test',
            'area_id' => $areaId,
            'package_id' => $packageId,
            'pppoe_user' => 'user_'.uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_teknisi_can_add_a_lead(): void
    {
        $this->actingAs($this->user('teknisi'));

        $this->post(route('saleskit.store'), [
            'name' => 'Budi Contoh',
            'daerah' => 'GONDANG',
            'phone' => '081234567890',
            'rt' => '05',
            'rw' => '06',
        ])->assertRedirect();

        $lead = SalesLead::first();
        $this->assertNotNull($lead);
        $this->assertSame('calon', $lead->status);
        $this->assertNotEmpty($lead->code);
    }

    public function test_name_and_daerah_are_required(): void
    {
        $this->actingAs($this->user('teknisi'));

        $this->post(route('saleskit.store'), ['name' => '', 'daerah' => ''])
            ->assertSessionHasErrors(['name', 'daerah']);
    }

    public function test_lead_is_auto_marked_installed_when_phone_matches_a_customer(): void
    {
        $customerId = $this->seedCustomer('081234567890', 'PELANGGAN LAMA');

        $this->actingAs($this->user('teknisi'));
        $this->post(route('saleskit.store'), [
            'name' => 'Nama Beda',
            'daerah' => 'GONDANG',
            'phone' => '081234567890',
        ])->assertRedirect();

        $lead = SalesLead::first();
        $this->assertSame('terpasang', $lead->status);
        $this->assertSame($customerId, $lead->converted_customer_id);
        $this->assertNotNull($lead->installed_at);
    }

    public function test_import_skips_rows_without_name_or_daerah(): void
    {
        $this->actingAs($this->user('admin'));

        $csv = "nama,daerah,hp,rt,rw\nBudi,GONDANG,08123,01,02\n,GONDANG,,\nSiti,SUKO,08999,,\n";
        $file = UploadedFile::fake()->createWithContent('leads.csv', $csv);

        $this->post(route('saleskit.import'), ['file' => $file])->assertRedirect();

        $this->assertSame(2, SalesLead::count());
        $this->assertSame('excel', SalesLead::first()->source);
    }

    public function test_teknisi_cannot_import(): void
    {
        $this->actingAs($this->user('teknisi'));

        $file = UploadedFile::fake()->createWithContent('leads.csv', "nama,daerah\nBudi,GONDANG\n");

        $this->post(route('saleskit.import'), ['file' => $file])->assertForbidden();
    }

    public function test_index_reports_summary_counts(): void
    {
        SalesLead::create(['name' => 'A', 'daerah' => 'GONDANG', 'status' => SalesLead::STATUS_CALON, 'reason_code' => 'odp_penuh']);
        SalesLead::create(['name' => 'B', 'daerah' => 'GONDANG', 'status' => SalesLead::STATUS_TERPASANG]);
        SalesLead::create(['name' => 'C', 'daerah' => 'SUKO', 'status' => SalesLead::STATUS_BATAL]);

        $this->actingAs($this->user('admin'));
        $response = $this->get(route('saleskit.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Saleskit/Index')
            ->where('summary.total', 3)
            ->where('summary.installed', 1)
            ->where('summary.open', 1)
            ->where('summary.cancelled', 1));
    }
}
