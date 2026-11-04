<?php

namespace Tests\Unit;

use App\Services\CustomerMigrationService;
use App\Services\KtpOcrService;
use App\Services\Psb\PsbPhotoScorer;
use ReflectionMethod;
use Tests\TestCase;

class PsbPhotoClassificationTest extends TestCase
{
    public function test_ratio_only_score_is_not_a_ktp(): void
    {
        // Card aspect ratio gives ktpScore 25 with no text evidence.
        $type = $this->classify(null, 0, 25, ktpText: false, snText: false);

        self::assertSame('unknown', $type);
    }

    public function test_sn_sticker_with_keywords_wins_over_a_ratio_only_ktp(): void
    {
        // Router label: no serial hit, but sticker keywords present.
        $type = $this->classify(null, 40, 25, ktpText: false, snText: true);

        self::assertSame('sn', $type);
    }

    public function test_ktp_with_text_evidence_is_classified(): void
    {
        $type = $this->classify(null, 0, 50, ktpText: true, snText: false);

        self::assertSame('ktp', $type);
    }

    public function test_strong_serial_hit_is_sn(): void
    {
        $type = $this->classify('FHTT0000A03A', 90, 25, ktpText: false, snText: true);

        self::assertSame('sn', $type);
    }

    public function test_hard_guard_fails_closed_without_ktp_text(): void
    {
        $type = $this->classify(null, 0, 90, hardGuard: true, ktpText: false, snText: false);

        self::assertSame('unknown', $type);
    }

    public function test_ktp_text_evidence_recognises_id_card_keywords(): void
    {
        self::assertTrue($this->invoke('hasKtpTextEvidence', 'PROVINSI JAWA TIMUR KABUPATEN MALANG NIK 3507114510000001'));
        self::assertTrue($this->invoke('hasKtpTextEvidence', 'REPUBLIK INDONESIA'));
        self::assertFalse($this->invoke('hasKtpTextEvidence', 'FIBERHOME GPON ONU WLAN SSID fh_49c470'));
    }

    public function test_sn_text_evidence_recognises_sticker_keywords(): void
    {
        self::assertTrue($this->invoke('hasSnTextEvidence', 'FiberHome GPON ONU WLAN SSID(2.4GHz): fh_49c470 MODEL: HG6145F3'));
        self::assertTrue($this->invoke('hasSnTextEvidence', 'SN: FHTT0000A03A'));
        self::assertFalse($this->invoke('hasSnTextEvidence', 'PROVINSI JAWA TIMUR KABUPATEN MALANG'));
    }

    public function test_migration_photo_label_defaults_to_unknown_without_hint(): void
    {
        $service = app(CustomerMigrationService::class);
        $method = new ReflectionMethod($service, 'photoLabelFromHint');

        self::assertSame('unknown', $method->invoke($service, '', 'unknown'));
        self::assertSame('ktp', $method->invoke($service, '', 'ktp'));
        self::assertSame('sn', $method->invoke($service, '', 'sn'));
        // An explicit caption hint is trusted in soft mode (hard guard off).
        self::assertSame('ktp', $method->invoke($service, 'foto ktp', 'ktp'));
        self::assertSame('sn', $method->invoke($service, 'foto sn', 'sn'));
    }

    private function classify(
        ?string $sn,
        int $snScore,
        int $ktpScore,
        bool $hardGuard = false,
        int $odpScore = 0,
        bool $ktpText = false,
        bool $snText = false,
    ): string {
        $service = new PsbPhotoScorer(app(KtpOcrService::class));
        $method = new ReflectionMethod($service, 'photoTypeFromScores');

        return (string) $method->invoke($service, $sn, $snScore, $ktpScore, $hardGuard, $odpScore, $ktpText, $snText);
    }

    private function invoke(string $method, string $text): bool
    {
        $service = new PsbPhotoScorer(app(KtpOcrService::class));

        return (bool) (new ReflectionMethod($service, $method))->invoke($service, $text);
    }
}
