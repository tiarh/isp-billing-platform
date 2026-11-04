<?php

namespace Tests\Feature;

use App\Services\PsbOrchestratorService;
use App\Support\WaGroupJid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * WaGroupJid is a copy of PsbOrchestratorService::normalizeGroupJid (kept separate so the PSB
 * service stays untouched). Both must always agree, otherwise the Report/PSB overlap check
 * could disagree with the webhook allowlist.
 */
#[Group('blocking-core')]
class WaGroupJidParityTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function samples(): array
    {
        return [
            'empty' => [''],
            'spaces only' => ['   '],
            'bare numeric' => ['120363123456789012'],
            'short numeric' => ['1234'],
            'with suffix' => ['120363123456789012@g.us'],
            'upper domain' => ['120363123456789012@G.US'],
            'padded and newline' => [" 1203631234\n56789012@g.us \n"],
            'wrong suffix' => ['120363123456789012@s.whatsapp.net'],
            'wrong suffix non numeric' => ['abc@s.whatsapp.net'],
            'mixed case local' => ['Group-Local@g.us'],
            'plain text' => ['not a jid'],
        ];
    }

    #[DataProvider('samples')]
    public function test_normalisation_matches_the_psb_orchestrator(string $jid): void
    {
        $this->assertSame(
            app(PsbOrchestratorService::class)->normalizeGroupJid($jid),
            WaGroupJid::normalize($jid),
        );
    }
}
