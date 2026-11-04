<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Package;
use App\Models\User;
use App\Services\CustomerMigrationImportPreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-psb')]
class CustomerMigrationImportPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_import_dry_run_classifies_starlite_rows_and_does_not_commit_live_data(): void
    {
        $package = Package::create(['code' => 'BASIC', 'name' => 'Basic', 'price' => 100000]);

        Customer::create([
            'code' => 'C-OLD',
            'name' => 'Pelanggan Lama',
            'address' => 'Randuagung',
            'pppoe_user' => 'OLDUSER',
            'onu_serial' => 'ZTEGOLD123',
            'package_id' => $package->id,
            'status' => 'active',
        ]);

        DB::table('psb_drafts')->insert([
            'status' => 'olt_ok',
            'message_id' => 'kartono-wa',
            'full_name' => 'Kartono',
            'pppoe_user' => 'KARTONO',
            'daerah' => 'Randuagung',
            'rw' => '04',
            'sn' => 'FHTTKARTONO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (! Schema::hasTable('olt_onus')) {
            Schema::create('olt_onus', function ($table) {
                $table->id();
                $table->unsignedBigInteger('olt_id')->nullable();
                $table->string('onu_ref')->nullable();
                $table->string('pon_port')->nullable();
                $table->string('serial_number')->nullable();
                $table->string('onu_name')->nullable();
                $table->timestamps();
            });
        }
        DB::table('olt_onus')->insert([
            'onu_ref' => '1/1/1:22',
            'pon_port' => '1/1/1',
            'serial_number' => 'FHTTNEW123',
            'onu_name' => 'OLDUSER',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $path = storage_path('app/test-migrasi-import.csv');
        file_put_contents($path, implode("\n", [
            'project,area,rw,nama pelanggan,pppoe,sn lama,sn baru,jenis layanan,vendor',
            'starlite-akuisisi,Randuagung,10,Pelanggan Lama,OLDUSER,ZTEGOLD123,,Migrasi,FiberHome',
            'randuagung-starlite,Randuagung,,Kartono,KARTONO,,FHTTKARTONO,PSB,C300',
            'starlite-randuagung,Randuagung,RW 10,Pelanggan RW10,RW10USER,,FHTTRW10,PSB,HiOSO',
            'psb,Krian,03,Krian Baru,KRIAN001,,FHTTKRIAN,PSB,HTB',
        ]));

        $result = app(CustomerMigrationImportPreviewService::class)->importDryRunFromPath($path, 7, 'snapshot-2026-07-24');

        $this->assertSame(4, $result['imported']);
        $this->assertDatabaseCount('customer_migrations', 0);

        $old = DB::table('customer_migration_import_rows')->where('pppoe_user', 'OLDUSER')->first();
        $this->assertSame('migration', $old->classification);
        $this->assertSame('starlite-akuisisi', $old->project);
        $this->assertSame('FHTTNEW123', $old->sn_baru);
        $this->assertSame('migrated', $old->match_status);

        $kartono = DB::table('customer_migration_import_rows')->where('pppoe_user', 'KARTONO')->first();
        $this->assertSame('psb_new_candidate', $kartono->classification);
        $this->assertSame('starlite-randuagung', $kartono->project);
        $this->assertSame('randuagung-starlite', $kartono->area);
        $this->assertSame('RW 4', $kartono->rw);
        $this->assertSame(0, (int) $kartono->rw_needs_review);
        $this->assertSame('psb_new_candidate', $kartono->match_status);

        $rw10 = DB::table('customer_migration_import_rows')->where('pppoe_user', 'RW10USER')->first();
        $this->assertSame('randuagung-starlite', $rw10->area);
        $this->assertSame('RW 4', $rw10->rw);
        $this->assertSame(0, (int) $rw10->rw_needs_review);
        $this->assertStringContainsString('RW 10', $rw10->match_notes);

        $krian = DB::table('customer_migration_import_rows')->where('pppoe_user', 'KRIAN001')->first();
        $this->assertSame('krian', $krian->area);
        $this->assertSame('krian', $krian->project);
    }

    public function test_manual_override_can_move_starlite_randuagung_psb_to_non_default_rw_with_reason(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $file = UploadedFile::fake()->createWithContent('migrasi.csv', "project,area,rw,nama pelanggan,pppoe,jenis layanan\nstarlite-randuagung,Randuagung,RW 11,Pelanggan Domisili Khusus,RW11USER,PSB\n");

        $this->actingAs($user)
            ->post(route('customer-migrations.import-preview'), ['file' => $file])
            ->assertRedirect()
            // Kontrak flash H1: feedback sukses HANYA lewat session 'success'
            // (di-share sebagai flash.success oleh HandleInertiaRequests), bukan 'status'.
            ->assertSessionHas('success')
            ->assertSessionMissing('status');

        $rowId = DB::table('customer_migration_import_rows')->value('id');
        $this->assertDatabaseHas('customer_migration_import_rows', [
            'id' => $rowId,
            'rw' => 'RW 4',
            'rw_needs_review' => false,
        ]);

        $this->actingAs($user)
            ->patch(route('customer-migrations.import-preview.update', $rowId), [
                'classification' => 'psb_new_candidate',
                'rw' => 'RW 11',
                'reason' => 'Domisili pelanggan benar RW 11, bukan hanya RW KTP.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('customer_migration_import_rows', [
            'id' => $rowId,
            'rw' => 'RW 11',
            'classification_source' => 'manual_override',
        ]);
    }

    public function test_import_preview_route_and_correction_requires_reason_and_writes_audit(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $file = UploadedFile::fake()->createWithContent('migrasi.csv', "project,area,rw,nama pelanggan,pppoe,jenis layanan\nstarlite-akuisisi,Randuagung,,Poniti,PONITI,Migrasi\n");

        $this->actingAs($user)
            ->post(route('customer-migrations.import-preview'), ['file' => $file])
            ->assertRedirect();

        $rowId = DB::table('customer_migration_import_rows')->value('id');

        $this->actingAs($user)
            ->patch(route('customer-migrations.import-preview.update', $rowId), [
                'classification' => 'psb_new_candidate',
                'rw' => 'RW 4',
            ])
            ->assertSessionHasErrors('reason');

        $this->actingAs($user)
            ->patch(route('customer-migrations.import-preview.update', $rowId), [
                'classification' => 'psb_new_candidate',
                'rw' => 'RW 4',
                'reason' => 'Poniti pelanggan baru dari PSB WA manual.',
            ])
            ->assertRedirect();

        $row = DB::table('customer_migration_import_rows')->where('id', $rowId)->first();
        $this->assertSame('manual_override', $row->classification_source);
        $this->assertSame((string) $user->id, (string) $row->corrected_by);
        $this->assertStringContainsString('Poniti pelanggan baru', $row->override_reason);
    }

    public function test_bulk_correction_and_apply_stage_rows_to_application_tables_only(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $file = UploadedFile::fake()->createWithContent('migrasi.csv', implode("\n", [
            'project,area,rw,nama pelanggan,pppoe,sn lama,sn baru,jenis layanan',
            'starlite-akuisisi,Randuagung,RW 4,Pelanggan Lama,OLDUSER,ZTEGOLD123,FHTTNEW123,Migrasi',
            'starlite-randuagung,Randuagung,,Kartono,KARTONO,,FHTTKARTONO,PSB',
        ]));

        $this->actingAs($user)
            ->post(route('customer-migrations.import-preview'), ['file' => $file])
            ->assertRedirect();

        $ids = DB::table('customer_migration_import_rows')->pluck('id')->all();
        $kartonoId = DB::table('customer_migration_import_rows')->where('pppoe_user', 'KARTONO')->value('id');

        $this->actingAs($user)
            ->patch(route('customer-migrations.import-preview.bulk-correct'), [
                'ids' => [$kartonoId],
                'classification' => 'psb_new_candidate',
                'rw' => 'RW 4',
                'reason' => 'Koreksi bulk PSB baru Starlite Randuagung.',
            ])
            ->assertRedirect();

        $this->actingAs($user)
            ->post(route('customer-migrations.import-preview.apply'), [
                'ids' => $ids,
                'reason' => 'Apply staging hasil verifikasi snapshot.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success')
            ->assertSessionMissing('status');

        $this->assertDatabaseHas('customer_migrations', [
            'source' => 'excel_import',
            'message_id' => 'staging-import:migration:'.DB::table('customer_migration_import_rows')->where('pppoe_user', 'OLDUSER')->value('batch_key').':'.DB::table('customer_migration_import_rows')->where('pppoe_user', 'OLDUSER')->value('id'),
            'pppoe_user' => 'OLDUSER',
            'new_sn' => 'FHTTNEW123',
        ]);
        $this->assertDatabaseHas('psb_starlite_entries', [
            'source' => 'migration_staging',
            'pppoe_user' => 'KARTONO',
            'rw' => 'RW 4',
            'sn' => 'FHTTKARTONO',
        ]);
        $this->assertSame(2, DB::table('customer_migration_import_rows')->where('status', 'applied')->count());

        $this->actingAs($user)
            ->post(route('customer-migrations.import-preview.apply'), [
                'ids' => $ids,
                'reason' => 'Klik ulang tidak boleh dobel.',
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('customer_migrations', 1);
        $this->assertDatabaseCount('psb_starlite_entries', 1);
    }

    public function test_apply_rejects_rows_that_still_need_rw_review(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $file = UploadedFile::fake()->createWithContent('migrasi.csv', "project,area,rw,nama pelanggan,pppoe,jenis layanan\npsb,Krian,,Krian Baru,KRIAN001,PSB\n");

        $this->actingAs($user)
            ->post(route('customer-migrations.import-preview'), ['file' => $file])
            ->assertRedirect();

        $rowId = DB::table('customer_migration_import_rows')->value('id');

        $this->actingAs($user)
            ->post(route('customer-migrations.import-preview.apply'), [
                'ids' => [$rowId],
                'reason' => 'Apply harus tertahan kalau RW belum valid.',
            ])
            ->assertRedirect()
            // Error path kontrak flash H1: row tertahan -> flash 'error' (bukan 'status' / bukan diam).
            ->assertSessionHas('error')
            ->assertSessionMissing('success');

        $this->assertDatabaseCount('customer_migrations', 0);
        $this->assertDatabaseCount('psb_starlite_entries', 0);
        $this->assertDatabaseHas('customer_migration_import_rows', [
            'id' => $rowId,
            'status' => 'staged',
        ]);
        $this->assertNotNull(DB::table('customer_migration_import_rows')->where('id', $rowId)->value('applied_error'));
    }
}
