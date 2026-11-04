<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\AreaAlias;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-core')]
class SeedAreaLocalityAliasesCommandTest extends TestCase
{
    use RefreshDatabase;

    private function seedAreas(): void
    {
        Area::create(['name' => 'SKYNET-KLAMPOK', 'code' => 'klampok']);
        Area::create(['name' => 'SKYNET-RANDUAGUNG2', 'code' => 'RDGNEW']);
        Area::create(['name' => 'JATISARI', 'code' => 'skynet-jatisari']);
        Area::create(['name' => 'BEDALI', 'code' => 'skynet-bedali']);
    }

    public function test_preview_command_is_read_only_and_lists_localities(): void
    {
        $this->seedAreas();
        $this->artisan('areas:seed-locality-aliases', ['--apply' => true])->assertSuccessful();
        $areas = Area::count();
        $aliases = AreaAlias::count();

        $this->artisan('psb:area-locality-preview')
            ->expectsOutputToContain('klampok')
            ->expectsOutputToContain('read-only')
            ->assertSuccessful();

        $this->assertSame($areas, Area::count());
        $this->assertSame($aliases, AreaAlias::count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->seedAreas();
        $klampokId = Area::where('code', 'klampok')->value('id');

        $this->artisan('areas:seed-locality-aliases')
            ->expectsOutputToContain('SKYNET-KLAMPOK (#'.$klampokId.')')
            ->expectsOutputToContain('DRY-RUN')
            ->assertSuccessful();

        $this->assertSame(0, AreaAlias::count());
    }

    public function test_apply_seeds_owner_mapping_and_is_idempotent(): void
    {
        $this->seedAreas();

        $this->artisan('areas:seed-locality-aliases', ['--apply' => true])->assertSuccessful();
        $count = AreaAlias::count();
        $this->assertSame(14, $count);

        $klampok = Area::where('code', 'klampok')->first();
        $rdg = Area::where('code', 'RDGNEW')->first();
        $this->assertEqualsCanonicalizing(['kelampok', 'klampok', 'kalmpok'], $klampok->aliases()->pluck('alias_key')->all());
        $this->assertSame($rdg->id, AreaAlias::where('alias_key', 'gondang')->value('area_id'));
        $this->assertSame(Area::where('name', 'BEDALI')->value('id'), AreaAlias::where('alias_key', 'slb')->value('area_id'));
        $this->assertSame(Area::where('name', 'JATISARI')->value('id'), AreaAlias::where('alias_key', 'gajahan')->value('area_id'));

        $this->artisan('areas:seed-locality-aliases', ['--apply' => true])->assertSuccessful();
        $this->assertSame($count, AreaAlias::count());
    }

    public function test_missing_target_area_warns_and_skips_others_still_apply(): void
    {
        Area::create(['name' => 'SKYNET-KLAMPOK', 'code' => 'klampok']);

        $this->artisan('areas:seed-locality-aliases', ['--apply' => true])
            ->expectsOutputToContain('tidak ditemukan')
            ->assertSuccessful();

        $this->assertSame(3, AreaAlias::count());
        $this->assertDatabaseMissing('area_aliases', ['alias_key' => 'gondang']);
    }

    public function test_alias_owned_by_another_area_is_skipped_with_warning(): void
    {
        $this->seedAreas();
        $other = Area::create(['name' => 'Other', 'code' => 'OTH']);
        AreaAlias::create(['area_id' => $other->id, 'alias' => 'slb', 'alias_key' => 'slb']);

        $this->artisan('areas:seed-locality-aliases', ['--apply' => true])
            ->expectsOutputToContain("Alias 'slb' sudah dipakai")
            ->assertSuccessful();

        $this->assertSame($other->id, AreaAlias::where('alias_key', 'slb')->value('area_id'));
    }
}
