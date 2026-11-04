<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\AreaAlias;
use App\Services\PsbAreaRecapService;
use App\Services\PsbRecapCommandParser;
use App\Services\PsbWaMessages;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-psb')]
class PsbRecapCommandAreaResolutionTest extends TestCase
{
    use RefreshDatabase;

    private Area $klampok;

    protected function setUp(): void
    {
        parent::setUp();

        $this->klampok = Area::create(['name' => 'SKYNET-KLAMPOK', 'code' => 'klampok']);
        AreaAlias::create(['area_id' => $this->klampok->id, 'alias' => 'Kelampok', 'alias_key' => 'kelampok']);

        // Same name key ("gondang") on two areas at the same priority = ambiguous.
        Area::create(['name' => 'SKYNET-GONDANG', 'code' => 'gdg1']);
        Area::create(['name' => 'SUBNET-GONDANG', 'code' => 'gdg2']);
    }

    private function resolve(string $command): array
    {
        $request = (new PsbRecapCommandParser)->parse($command, CarbonImmutable::parse('2026-10-14 12:00', 'Asia/Jakarta'), 92);
        $this->assertNotNull($request);

        return app(PsbAreaRecapService::class)->resolveArea($request);
    }

    public function test_klampok_and_the_kelampok_alias_resolve_to_the_same_area(): void
    {
        foreach (['#rekap psb klampok 1-14 okt', '#rekap psb Kelampok 1-14 okt', '#rekap psb SKYNET-KLAMPOK'] as $command) {
            $result = $this->resolve($command);

            $this->assertSame('matched', $result['status'], $command);
            $this->assertSame($this->klampok->id, $result['area_id'], $command);
            $this->assertSame('KLAMPOK', $result['area_name'], $command);
        }
    }

    public function test_semua_and_all_skip_area_resolution(): void
    {
        foreach (['#rekap psb semua', '#rekap psb all 1-14 okt'] as $command) {
            $result = $this->resolve($command);

            $this->assertSame('all', $result['status']);
            $this->assertNull($result['area_id']);
            $this->assertSame('SEMUA AREA', $result['area_name']);
        }
    }

    public function test_an_ambiguous_area_lists_candidates_and_the_reply_asks_for_a_clearer_name(): void
    {
        $result = $this->resolve('#rekap psb gondang 1-14 okt');

        $this->assertSame('ambiguous', $result['status']);
        $this->assertNull($result['area_id']);
        $this->assertEqualsCanonicalizing(['SKYNET-GONDANG', 'SUBNET-GONDANG'], $result['candidates']);

        $text = app(PsbWaMessages::class)->recapAreaAmbiguous('gondang', $result['candidates']);
        $this->assertStringContainsString('SUBNET-GONDANG', $text);
        $this->assertStringContainsString('lebih dari satu area', $text);
    }

    public function test_an_unmatched_area_suggests_near_misses_and_hints_at_aliases(): void
    {
        $result = $this->resolve('#rekap psb klampokk 1-14 okt');

        $this->assertSame('unmatched', $result['status']);
        $this->assertNull($result['area_id']);
        $this->assertContains('SKYNET-KLAMPOK', $result['candidates']);

        $text = app(PsbWaMessages::class)->recapAreaUnmatched('klampokk', $result['candidates']);
        $this->assertStringContainsString('tidak ditemukan', $text);
        $this->assertStringContainsString('SKYNET-KLAMPOK', $text);
        $this->assertStringContainsString('alias', $text);
    }

    public function test_an_unmatched_area_without_suggestions_has_no_maksud_anda_line(): void
    {
        $result = $this->resolve('#rekap psb zzzzzzzz');

        $this->assertSame('unmatched', $result['status']);
        $this->assertSame([], $result['candidates']);
        $this->assertStringNotContainsString('Maksud Anda', app(PsbWaMessages::class)->recapAreaUnmatched('zzzzzzzz', []));
    }
}
