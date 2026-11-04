<?php

namespace Tests\Feature;

use App\Console\Commands\ReconcileServiceAreaClassification;
use App\Models\Area;
use App\Models\CustomerMigration;
use App\Models\PsbDraft;
use App\Services\ServiceAreaClassificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceAreaClassificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_origin_is_not_changed_by_startlite_profile_or_project_area(): void
    {
        $projectArea = Area::create(['code' => 'randuagung-starlite', 'name' => 'Randuagung Starlite']);
        $migration = CustomerMigration::create([
            'status' => CustomerMigration::STATUS_SYNCED,
            'source' => 'guided_lookup_wa',
            'full_name' => 'Migrasi Suko',
            'pppoe_user' => 'MIGRASI_SUKO',
            'daerah' => 'SUKO',
            'area_id' => $projectArea->id,
            'new_sn' => 'FHTT-SUKO-001',
            'meta' => ['target_profile' => '200M+ STARTLITE'],
        ]);

        $classification = app(ServiceAreaClassificationService::class)->forMigration($migration);

        $this->assertSame('migration_acquisition', $classification['service_origin']);
        $this->assertSame('starlite-akuisisi', $classification['project']);
        $this->assertSame('suko', $classification['customer_area']);
        $this->assertSame('200M+ STARTLITE', $classification['service_profile']);
        $this->assertSame('high', $classification['confidence']);
    }

    public function test_randuagung_psb_keeps_physical_area_separate_from_project(): void
    {
        $draft = PsbDraft::create([
            'status' => PsbDraft::STATUS_OLT_OK,
            'site_profile_key' => 'ra',
            'group_jid' => 'activation@wa',
            'message_id' => 'area-004b-psb',
            'full_name' => 'PSB Gondang',
            'pppoe_user' => 'PSB_GONDANG',
            'daerah' => 'GONDANG',
            'sn' => 'FHTT-GONDANG-001',
            'mikrotik_profile' => '200M+ STARTLITE',
        ]);

        $classification = app(ServiceAreaClassificationService::class)->forPsbDraft($draft);

        $this->assertSame('psb_new', $classification['service_origin']);
        $this->assertSame('starlite-randuagung', $classification['project']);
        $this->assertSame('randuagung-starlite', $classification['operational_area']);
        $this->assertSame('gondang', $classification['customer_area']);
        $this->assertSame('200M+ STARTLITE', $classification['service_profile']);
    }

    public function test_apply_is_idempotent_and_never_updates_customer_or_device_data(): void
    {
        $migration = CustomerMigration::create([
            'status' => CustomerMigration::STATUS_RECEIVED,
            'source' => 'excel_master',
            'full_name' => 'Snapshot Randuagung',
            'pppoe_user' => 'SNAPSHOT_RA',
            'daerah' => 'Randuagung',
            'new_sn' => 'FHTT-SNAPSHOT-001',
        ]);

        $this->artisan(ReconcileServiceAreaClassification::class, [
            '--apply' => true,
            '--limit' => 10,
            '--actor' => 'test',
        ])->assertExitCode(0);

        $first = $migration->fresh();
        $this->assertSame('starlite-akuisisi', $first->meta['service_classification']['project']);
        $this->assertSame('test', $first->meta['service_classification']['actor']);

        $this->artisan(ReconcileServiceAreaClassification::class, [
            '--apply' => true,
            '--limit' => 10,
            '--actor' => 'test',
        ])->assertExitCode(0);

        $second = $migration->fresh();
        $this->assertSame($first->meta['service_classification']['idempotency_key'], $second->meta['service_classification']['idempotency_key']);
        $this->assertSame($first->meta['service_classification']['classified_at'], $second->meta['service_classification']['classified_at']);
    }
}
