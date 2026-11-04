<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\AreaAlias;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-core')]
class AreaAliasCrudTest extends TestCase
{
    use RefreshDatabase;

    private function globalAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_global_admin_creates_area_with_aliases_normalized(): void
    {
        $admin = $this->globalAdmin();

        $this->actingAs($admin)->post(route('areas.store'), [
            'name' => 'SKYNET-KLAMPOK',
            'code' => 'klampok',
            'aliases' => 'Kelampok, Desa Kalmpok RT 2 , kelampok,  ',
        ])->assertRedirect(route('areas.index'));

        $area = Area::where('code', 'klampok')->firstOrFail();
        $this->assertEqualsCanonicalizing(['kelampok', 'kalmpok'], $area->aliases()->pluck('alias_key')->all());
        $this->assertSame(2, $area->aliases()->count());
        $this->assertSame($admin->id, $area->aliases()->first()->created_by);
    }

    public function test_update_syncs_aliases_and_omitting_field_keeps_them(): void
    {
        $admin = $this->globalAdmin();
        $area = Area::create(['name' => 'Klampok', 'code' => 'KLP']);
        AreaAlias::create(['area_id' => $area->id, 'alias' => 'Kelampok', 'alias_key' => 'kelampok']);
        AreaAlias::create(['area_id' => $area->id, 'alias' => 'Kalmpok', 'alias_key' => 'kalmpok']);

        $this->actingAs($admin)->put(route('areas.update', $area), [
            'name' => 'Klampok', 'code' => 'KLP', 'aliases' => 'Kelampok, Klmpok',
        ])->assertRedirect(route('areas.index'));
        $this->assertEqualsCanonicalizing(['kelampok', 'klmpok'], $area->aliases()->pluck('alias_key')->all());

        $this->actingAs($admin)->put(route('areas.update', $area), [
            'name' => 'Klampok 2', 'code' => 'KLP',
        ])->assertRedirect(route('areas.index'));
        $this->assertSame(2, $area->aliases()->count());

        $this->actingAs($admin)->put(route('areas.update', $area), [
            'name' => 'Klampok 2', 'code' => 'KLP', 'aliases' => '',
        ])->assertRedirect(route('areas.index'));
        $this->assertSame(0, $area->aliases()->count());
    }

    public function test_scoped_admin_is_forbidden(): void
    {
        $scoped = User::factory()->create(['role' => 'admin']);
        $scoped->areas()->attach(Area::create(['name' => 'Scoped', 'code' => 'SCP']));

        $this->actingAs($scoped)->post(route('areas.store'), [
            'name' => 'Baru', 'code' => 'NEW', 'aliases' => 'baru',
        ])->assertForbidden();
        $this->assertSame(0, AreaAlias::count());
        $this->actingAs($scoped)->get(route('areas.create'))->assertForbidden();
    }

    public function test_duplicate_alias_across_areas_is_rejected_after_normalization(): void
    {
        $admin = $this->globalAdmin();
        $a = Area::create(['name' => 'Klampok', 'code' => 'KLP']);
        AreaAlias::create(['area_id' => $a->id, 'alias' => 'Kelampok', 'alias_key' => 'kelampok']);

        $this->actingAs($admin)->post(route('areas.store'), [
            'name' => 'Lain', 'code' => 'LAIN', 'aliases' => 'Desa KELAMPOK RT 1',
        ])->assertSessionHasErrors('aliases');

        $this->assertDatabaseMissing('areas', ['code' => 'LAIN']);
    }

    public function test_alias_equal_to_other_area_name_or_code_is_rejected(): void
    {
        $admin = $this->globalAdmin();
        Area::create(['name' => 'SKYNET-PURWODADI', 'code' => 'PWD']);
        $own = Area::create(['name' => 'Lain', 'code' => 'LAIN']);

        $this->actingAs($admin)->put(route('areas.update', $own), [
            'name' => 'Lain', 'code' => 'LAIN', 'aliases' => 'Purwodadi',
        ])->assertSessionHasErrors('aliases');

        $this->actingAs($admin)->put(route('areas.update', $own), [
            'name' => 'Lain', 'code' => 'LAIN', 'aliases' => 'pwd',
        ])->assertSessionHasErrors('aliases');

        $this->assertSame(0, $own->aliases()->count());
    }

    public function test_same_area_may_keep_its_own_alias_on_resubmit(): void
    {
        $admin = $this->globalAdmin();
        $area = Area::create(['name' => 'Klampok', 'code' => 'KLP']);
        AreaAlias::create(['area_id' => $area->id, 'alias' => 'Kelampok', 'alias_key' => 'kelampok']);

        $this->actingAs($admin)->put(route('areas.update', $area), [
            'name' => 'Klampok', 'code' => 'KLP', 'aliases' => 'kelampok',
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, $area->aliases()->count());
    }

    public function test_edit_and_show_expose_aliases(): void
    {
        $admin = $this->globalAdmin();
        $area = Area::create(['name' => 'Klampok', 'code' => 'KLP']);
        AreaAlias::create(['area_id' => $area->id, 'alias' => 'Kelampok', 'alias_key' => 'kelampok']);

        $this->actingAs($admin)->get(route('areas.edit', $area))
            ->assertOk()->assertInertia(fn ($page) => $page->where('aliases', ['Kelampok']));
        $this->actingAs($admin)->get(route('areas.show', $area))
            ->assertOk()->assertInertia(fn ($page) => $page->where('aliases', ['Kelampok']));
    }

    public function test_parse_list_drops_aliases_that_normalize_to_empty(): void
    {
        $this->assertSame(['kelampok' => 'Kelampok'], AreaAlias::parseList('RT 03, Desa, RW.5/7, Kelampok, '));
    }

    public function test_alias_that_normalizes_to_empty_or_is_too_long_is_rejected(): void
    {
        $admin = $this->globalAdmin();

        $this->actingAs($admin)->post(route('areas.store'), [
            'name' => 'A1', 'code' => 'a1', 'aliases' => 'Kelampok, RT 03',
        ])->assertSessionHasErrors('aliases');
        $this->actingAs($admin)->post(route('areas.store'), [
            'name' => 'A1', 'code' => 'a1', 'aliases' => 'Desa',
        ])->assertSessionHasErrors('aliases');
        $this->actingAs($admin)->post(route('areas.store'), [
            'name' => 'A1', 'code' => 'a1', 'aliases' => str_repeat('a', 256),
        ])->assertSessionHasErrors('aliases');
        $this->assertSame(0, Area::count());

        $this->actingAs($admin)->post(route('areas.store'), [
            'name' => 'A1', 'code' => 'a1', 'aliases' => str_repeat('a', 255),
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, AreaAlias::count());
    }

    public function test_area_name_or_code_equal_to_another_areas_alias_is_rejected(): void
    {
        $admin = $this->globalAdmin();
        $klampok = Area::create(['name' => 'Klampok', 'code' => 'KLP']);
        AreaAlias::create(['area_id' => $klampok->id, 'alias' => 'Kelampok', 'alias_key' => 'kelampok']);

        $this->actingAs($admin)->post(route('areas.store'), [
            'name' => 'SKYNET-KELAMPOK', 'code' => 'zz1',
        ])->assertSessionHasErrors('name');
        $this->actingAs($admin)->post(route('areas.store'), [
            'name' => 'Lain', 'code' => 'Kelampok',
        ])->assertSessionHasErrors('code');

        $other = Area::create(['name' => 'Lain', 'code' => 'LN']);
        $this->actingAs($admin)->put(route('areas.update', $other), [
            'name' => 'Kelampok', 'code' => 'LN',
        ])->assertSessionHasErrors('name');

        // The owning area itself may use its own alias as name.
        $this->actingAs($admin)->put(route('areas.update', $klampok), [
            'name' => 'Kelampok', 'code' => 'KLP', 'aliases' => 'Kelampok',
        ])->assertSessionHasNoErrors();
        $this->assertSame(2, Area::count());
    }

    public function test_deleting_area_cascades_aliases(): void
    {
        $admin = $this->globalAdmin();
        $area = Area::create(['name' => 'Klampok', 'code' => 'KLP']);
        AreaAlias::create(['area_id' => $area->id, 'alias' => 'Kelampok', 'alias_key' => 'kelampok']);

        $this->actingAs($admin)->delete(route('areas.destroy', $area))->assertRedirect(route('areas.index'));

        $this->assertSame(0, AreaAlias::count());
    }
}
