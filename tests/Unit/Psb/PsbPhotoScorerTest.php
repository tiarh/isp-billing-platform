<?php

namespace Tests\Unit\Psb;

use App\Services\KtpOcrService;
use App\Services\Psb\PsbPhotoScorer;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-psb')]
class PsbPhotoScorerTest extends TestCase
{
    private function scorer(): PsbPhotoScorer
    {
        return new PsbPhotoScorer(app(KtpOcrService::class));
    }

    /** @return \Closure(string): ?string */
    private function noTesseract(): \Closure
    {
        return function (string $bytes): ?string {
            $this->fail('tesseract must not run when text was handed in');
        };
    }

    public function test_photo_type_from_scores(): void
    {
        $s = $this->scorer();

        $this->assertSame('unknown', $s->photoTypeFromScores(null, 0, 25));
        $this->assertSame('sn', $s->photoTypeFromScores(null, 40, 25, false, 0, false, true));
        $this->assertSame('ktp', $s->photoTypeFromScores(null, 0, 50, false, 0, true, false));
        $this->assertSame('sn', $s->photoTypeFromScores('FHTT0000A03A', 90, 25, false, 0, false, true));
        $this->assertSame('odp', $s->photoTypeFromScores(null, 0, 0, false, 50));
        $this->assertSame('unknown', $s->photoTypeFromScores(null, 0, 90, true, 0, false, false));
        $this->assertSame('unknown', $s->photoTypeFromScores(null, 0, 40, true, 0, true, false));
        $this->assertSame('ktp', $s->photoTypeFromScores(null, 0, 60, true, 0, true, false));
    }

    public function test_normalize_ocr_signal_text(): void
    {
        $s = $this->scorer();

        $this->assertSame('', $s->normalizeOcrSignalText('  '));
        $this->assertSame('NIK 123', $s->normalizeOcrSignalText("n1k\r\n 123"));
        $this->assertSame('FHTT0000A03A', $s->normalizeOcrSignalText('fhtt - 0000a03a'));
    }

    public function test_extract_sn_from_text(): void
    {
        $s = $this->scorer();

        $this->assertSame('FHTT0000A03A', $s->extractSnFromText('SN: fhtt0000a03a ok'));
        $this->assertSame('ZTEGC1234ABCD', $s->extractSnFromText('x ZTEGC1234ABCD y'));
        $this->assertNull($s->extractSnFromText('REPUBLIK INDONESIA KABUPATEN'));
        $this->assertNull($s->extractSnFromText('abcd'));
    }

    public function test_text_evidence(): void
    {
        $s = $this->scorer();

        $this->assertTrue($s->hasKtpTextEvidence(null, ['nik' => '3300000000000001']));
        $this->assertTrue($s->hasKtpTextEvidence('Provinsi Jawa Tengah'));
        $this->assertTrue($s->hasKtpTextEvidence(null, ['raw_text' => 'NIK : 1']));
        $this->assertFalse($s->hasKtpTextEvidence('hello'));

        $this->assertTrue($s->hasSnTextEvidence(null, 'FHTT0000A03A'));
        $this->assertTrue($s->hasSnTextEvidence('GPON ONU label'));
        $this->assertFalse($s->hasSnTextEvidence('selamat pagi'));
    }

    public function test_odp_score(): void
    {
        $s = $this->scorer();

        $this->assertSame(0, $s->scoreOdpPhoto(''));
        $this->assertSame(60, $s->scoreOdpPhoto('ODP kotak'));
        $this->assertSame(85, $s->scoreOdpPhoto('ODP 1/2/3'));
    }

    public function test_ktp_score_uses_handed_in_text_and_does_not_run_tesseract(): void
    {
        $s = $this->scorer();
        $text = 'NIK NAMA PROVINSI KABUPATEN ALAMAT';

        // no image bytes (no aspect ratio points): 4+ signals = 45
        $this->assertSame(45, $s->scoreKtpPhoto('', [], $text, $this->noTesseract()));
        // sticker words cut 50
        $this->assertSame(0, $s->scoreKtpPhoto('', [], $text.' GPON', $this->noTesseract()));
        // empty text handed in ("read, found nothing") does not re-read
        $this->assertSame(0, $s->scoreKtpPhoto('', ['raw_text' => ''], null, $this->noTesseract()));
        $this->assertSame(0, $s->scoreKtpPhoto('', [], '', $this->noTesseract()));
    }

    public function test_ktp_and_sn_scores_call_the_tesseract_closure_only_without_text(): void
    {
        $s = $this->scorer();
        $calls = 0;
        $tess = function (string $bytes) use (&$calls): ?string {
            $calls++;

            return 'NIK NAMA REPUBLIK PROVINSI';
        };

        $this->assertSame(45, $s->scoreKtpPhoto('', [], null, $tess));
        $this->assertSame(1, $calls);

        $snTess = function (string $bytes) use (&$calls): ?string {
            $calls++;

            return 'FHTT0000A03A';
        };
        $this->assertSame(85, $s->scoreSnPhoto('', null, $snTess));
        $this->assertSame(2, $calls);
        $this->assertSame(0, $s->scoreSnPhoto('', 'NIK REPUBLIK INDONESIA', $this->noTesseract()));
    }

    public function test_image_ocr_status(): void
    {
        $s = $this->scorer();

        $this->assertSame(['no_text', false], $s->imageOcrStatus('', null, []));
        $this->assertSame(['timeout', true], $s->imageOcrStatus('', ['timed_out' => true], []));
        $this->assertSame(['deadline', true], $s->imageOcrStatus('  ', ['deadline_hit' => true], []));
        $this->assertSame(['paddle_busy', true], $s->imageOcrStatus('', null, ['ocr_degraded' => true]));
        $this->assertSame(['timeout', true], $s->imageOcrStatus('text', ['timed_out' => true], []));
        $this->assertSame(['paddle_busy', true], $s->imageOcrStatus('text', null, ['ocr_degraded' => true]));
        $this->assertSame(['ok', false], $s->imageOcrStatus('text', null, []));
    }

    public function test_terminal_classification_shape(): void
    {
        $this->assertSame([
            'type' => 'unknown',
            'sn' => null,
            'ktp_ocr' => [],
            'ktp_score' => 0,
            'sn_score' => 0,
            'classification_status' => 'terminal',
            'classification_error' => 'classifier_error',
        ], $this->scorer()->terminalPhotoClassification('classifier_error'));
    }

    public function test_strong_ktp_evidence_needs_plausible_nik_and_ktp_text(): void
    {
        $s = $this->scorer();

        $this->assertFalse($s->hasStrongKtpEvidence(['nik' => '123', 'raw_text' => 'NIK']));
        $this->assertFalse($s->hasStrongKtpEvidence(['nik' => '3301010110000001', 'raw_text' => 'hello']));
    }
}
