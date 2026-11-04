<?php

namespace Tests\Unit\Psb;

use App\Models\PsbDraft;
use App\Services\Psb\PsbPppoeNaming;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('blocking-psb')]
class PsbPppoeNamingTest extends TestCase
{
    public function test_username_uses_name_rt_rw_and_odp_suffix(): void
    {
        $draft = new PsbDraft(['full_name' => 'User Budicontoh', 'rt' => '03', 'rw' => 'RW 07', 'odp' => 'ODP-L2.15']);

        $this->assertSame('USERBUDICONTOH_RT03_RW07_L2.15', (new PsbPppoeNaming)->buildPppoeUsername($draft));
    }

    public function test_username_is_empty_when_a_part_is_missing(): void
    {
        $naming = new PsbPppoeNaming;

        $this->assertSame('', $naming->buildPppoeUsername(new PsbDraft(['full_name' => 'Siti Contoh', 'rt' => '1', 'rw' => ''])));
        $this->assertSame('', $naming->buildPppoeUsername(new PsbDraft(['full_name' => '', 'rt' => '1', 'rw' => '2'])));
    }

    public function test_username_is_cut_at_64_characters(): void
    {
        $draft = new PsbDraft(['full_name' => str_repeat('Abc', 30), 'rt' => '1', 'rw' => '2', 'odp' => 'ODP1']);

        $this->assertSame(64, strlen((new PsbPppoeNaming)->buildPppoeUsername($draft)));
    }

    public function test_odp_suffix_variants(): void
    {
        $naming = new PsbPppoeNaming;

        $this->assertSame('ODP12', $naming->odpUsernameSuffix('  odp12 '));
        $this->assertSame('L2.15', $naming->odpUsernameSuffix('ODP-L2.15'));
        $this->assertSame('ODP', $naming->odpUsernameSuffix('---'));
        $this->assertSame('ODP', $naming->odpUsernameSuffix(''));
    }

    public function test_short_error_collapses_whitespace_and_truncates(): void
    {
        $naming = new PsbPppoeNaming;

        $this->assertSame('a b c', $naming->shortError("a\n  b\t c"));

        $long = $naming->shortError(str_repeat('gagal sambung ', 40));
        $this->assertSame(160, mb_strlen($long));
        $this->assertStringEndsWith('...', $long);
    }
}
