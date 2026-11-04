<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\AreaAlias;
use App\Services\AreaLocalityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-psb')]
class AreaLocalityResolverTest extends TestCase
{
    use RefreshDatabase;

    private function area(string $name, string $code): Area
    {
        return Area::create(['name' => $name, 'code' => $code]);
    }

    private function alias(Area $area, string $alias): AreaAlias
    {
        return AreaAlias::create([
            'area_id' => $area->id,
            'alias' => $alias,
            'alias_key' => AreaLocalityResolver::normalize($alias),
        ]);
    }

    private function resolver(): AreaLocalityResolver
    {
        return new AreaLocalityResolver;
    }

    public function test_klampok_spellings_resolve_via_alias(): void
    {
        $klampok = $this->area('SKYNET-KLAMPOK', 'klampok');
        $this->alias($klampok, 'Kelampok');
        $this->alias($klampok, 'kalmpok');

        foreach (['Kelampok', 'KLAMPOK', 'kalmpok', 'Desa Kelampok RT 02 RW 01'] as $label) {
            $result = $this->resolver()->resolve($label);
            $this->assertSame('matched', $result['status'], $label);
            $this->assertSame($klampok->id, $result['area_id'], $label);
        }

        $this->assertSame('alias', $this->resolver()->resolve('Kelampok')['via']);
        $this->assertSame('kelampok', $this->resolver()->resolve('Kelampok')['matched_key']);
        // "Klampok" has no alias row but matches the area name (SKYNET- prefix stripped)
        $this->assertSame('name', $this->resolver()->resolve('Klampok')['via']);
    }

    public function test_label_with_prefix_and_rt_rw_matches_by_name(): void
    {
        $purwodadi = $this->area('SKYNET-PURWODADI', 'skynet-purwodadi');

        $result = $this->resolver()->resolve('Desa Purwodadi RW 3');

        $this->assertSame('matched', $result['status']);
        $this->assertSame($purwodadi->id, $result['area_id']);
        $this->assertSame('name', $result['via']);
        $this->assertSame('purwodadi', $result['matched_key']);
    }

    public function test_dusun_gondang_resolves_to_randuagung2_via_alias(): void
    {
        $rdg = $this->area('SKYNET-RANDUAGUNG2', 'RDGNEW');
        $this->alias($rdg, 'gondang');

        $result = $this->resolver()->resolve('Dsn Gondang');

        $this->assertSame('matched', $result['status']);
        $this->assertSame($rdg->id, $result['area_id']);
        $this->assertSame('alias', $result['via']);
    }

    public function test_skynet_and_subnet_prefix_is_ignored_for_names_but_code_matches_too(): void
    {
        $a = $this->area('SUBNET-BEDALI', 'skynet-bedali');
        $b = $this->area('Kertosari Utara', 'KRT');

        $this->assertSame($a->id, $this->resolver()->resolve('Bedali')['area_id']);
        $this->assertSame($b->id, $this->resolver()->resolve('krt')['area_id']);
        $this->assertSame('code', $this->resolver()->resolve('krt')['via']);
    }

    public function test_alias_beats_name_and_name_beats_code(): void
    {
        $nameArea = $this->area('Lawang', 'LWG');
        $aliasArea = $this->area('Lawang Baru', 'LWB');
        $this->alias($aliasArea, 'Lawang');

        $result = $this->resolver()->resolve('Lawang');
        $this->assertSame('matched', $result['status']);
        $this->assertSame($aliasArea->id, $result['area_id']);
        $this->assertSame('alias', $result['via']);

        $codeArea = $this->area('Gajahan Timur', 'songsong');
        $nameArea2 = $this->area('Songsong', 'SGS');
        $result = $this->resolver()->resolve('songsong');
        $this->assertSame($nameArea2->id, $result['area_id']);
        $this->assertSame('name', $result['via']);
        $this->assertNotSame($codeArea->id, $result['area_id']);
        $this->assertNotNull($nameArea);
    }

    public function test_tie_at_same_priority_is_ambiguous(): void
    {
        $a = $this->area('Kunci Barat', 'KB');
        $b = $this->area('Alamhijau', 'AH');
        $this->alias($a, 'dusun x');
        $this->alias($b, 'wetan');

        // two labels each hitting a different area by alias
        $result = $this->resolver()->resolve('Dsn X', 'Wetan');

        $this->assertSame('ambiguous', $result['status']);
        $this->assertNull($result['area_id']);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], array_column($result['candidates'], 'area_id'));
    }

    public function test_unmatched_with_near_miss_candidate_never_auto_matches(): void
    {
        $klampok = $this->area('SKYNET-KLAMPOK', 'klampok');

        $result = $this->resolver()->resolve('Klampk');

        $this->assertSame('unmatched', $result['status']);
        $this->assertNull($result['area_id']);
        $this->assertNull($result['via']);
        $this->assertSame([$klampok->id], array_column($result['candidates'], 'area_id'));

        $far = $this->resolver()->resolve('Wonosari');
        $this->assertSame('unmatched', $far['status']);
        $this->assertSame([], $far['candidates']);
    }

    public function test_empty_and_null_labels_are_unmatched(): void
    {
        $this->area('Klampok', 'KLP');

        $this->assertSame('unmatched', $this->resolver()->resolve(null, '', '  ')['status']);
        $this->assertSame('unmatched', $this->resolver()->resolve()['status']);
    }

    public function test_normalize_strips_prefixes_and_rt_rw(): void
    {
        $this->assertSame('purwodadi', AreaLocalityResolver::normalize('Desa Purwodadi RT 03/RW 05'));
        $this->assertSame('gondang', AreaLocalityResolver::normalize('Dsn. Gondang'));
        $this->assertSame('randuagung2', AreaLocalityResolver::normalize('Randuagung 2'));
        $this->assertSame('kertosari', AreaLocalityResolver::normalize('Kertosari'));
        $this->assertSame('randuagung2', AreaLocalityResolver::nameKey('SKYNET-RANDUAGUNG2'));
    }

    public function test_rt_rw_with_slash_numbers_and_digit_only_tokens_are_ignored(): void
    {
        $this->assertSame(['klampok'], AreaLocalityResolver::tokens('Klampok RT 03/05'));
        $this->assertSame(['klampok'], AreaLocalityResolver::tokens('Klampok RT.3 / 5'));
        $this->assertSame(['klampok'], AreaLocalityResolver::tokens('Klampok RT03/RW05'));
        // Digits glued to a name are part of the name.
        $this->assertSame(['randuagung2'], AreaLocalityResolver::tokens('Randuagung2'));

        $klampok = $this->area('SKYNET-KLAMPOK', 'klampok');
        $rdg2 = $this->area('SKYNET-RANDUAGUNG2', 'RDGNEW');
        $this->area('Area Dua', '12');

        $result = $this->resolver()->resolve('Klampok 12');
        $this->assertSame('matched', $result['status']);
        $this->assertSame($klampok->id, $result['area_id']);
        // A bare number never matches an area by itself via the token probe...
        $this->assertSame('klampok', $this->resolver()->resolve('Desa Klampok 7 RT 03/05')['matched_key']);
        // ...but "Randuagung 2" still matches RANDUAGUNG2 through the whole-label key.
        $this->assertSame($rdg2->id, $this->resolver()->resolve('Randuagung 2')['area_id']);
    }

    public function test_index_is_cached_per_instance_until_flushed(): void
    {
        $resolver = $this->resolver();
        $this->assertSame('unmatched', $resolver->resolve('Purwodadi')['status']);

        $area = $this->area('SKYNET-PURWODADI', 'purwodadi');
        $this->assertSame('unmatched', $resolver->resolve('Purwodadi')['status']);

        $resolver->flush();
        $this->assertSame($area->id, $resolver->resolve('Purwodadi')['area_id']);
        // A fresh instance always sees the current data.
        $this->assertSame($area->id, $this->resolver()->resolve('Purwodadi')['area_id']);
    }
}
