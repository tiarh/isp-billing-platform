<?php

namespace Tests\Unit;

use App\Services\PsbMediaOcrOrchestrator;
use App\Support\TesseractRunner;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class PsbTesseractFallbackTest extends TestCase
{
    public function test_psb_sn_classifier_can_select_eng_without_ind_language(): void
    {
        $service = (new ReflectionClass(PsbMediaOcrOrchestrator::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(PsbMediaOcrOrchestrator::class, 'tesseractLanguageOption');
        $method->setAccessible(true);

        $this->assertSame('eng', $method->invoke($service, ['eng', 'osd']));
        $this->assertSame('ind+eng', $method->invoke($service, ['ind', 'eng', 'osd']));
        $this->assertNull($method->invoke($service, ['osd']));
    }

    public function test_tesseract_has_multi_psm_best_effort_helper(): void
    {
        // The best PSM varies per image; the media classifier must try several
        // and concatenate so keyword evidence from any mode is available.
        $this->assertTrue(method_exists(TesseractRunner::class, 'extractBest'));

        $media = file_get_contents(base_path('app/Services/Psb/PsbOcrEngine.php'));
        $orchestrator = file_get_contents(base_path('app/Services/Psb/PsbOcrEngine.php'));
        $ktp = file_get_contents(base_path('app/Services/KtpOcrService.php'));
        $this->assertStringContainsString('TesseractRunner::extractBest', $media);
        $this->assertStringContainsString('TesseractRunner::extractBest', $orchestrator);
        $this->assertStringContainsString('TesseractRunner::extractBest', $ktp);
    }

    public function test_extract_best_preprocesses_once_for_all_psms(): void
    {
        // Running ImageMagick once per PSM tripled CPU on a loaded host and
        // caused Tesseract timeouts (KTP read as empty). extractBest must
        // preprocess exactly once, then run each PSM on the prepared image.
        $source = file_get_contents(base_path('app/Support/TesseractRunner.php'));
        $body = substr($source, strpos($source, 'public static function extractBest'));
        $body = substr($body, 0, strpos($body, 'private static function extensionFor'));

        $this->assertSame(1, substr_count($body, 'self::preprocess('));
    }

    public function test_extract_best_no_longer_repeats_a_timed_out_psm(): void
    {
        // The old "retry an empty PSM once" re-ran a run that had just been
        // killed by its timeout (a killed run looks empty): under host load
        // that tripled the cost for the same empty result. A timeout is now
        // reported (timed_out) and stops the PSM loop; behaviour is covered
        // by TesseractRunnerTraceTest.
        $source = file_get_contents(base_path('app/Support/TesseractRunner.php'));
        $body = substr($source, strpos($source, 'public static function extractBest'));
        $body = substr($body, 0, strpos($body, 'private static function extensionFor'));

        $this->assertStringNotContainsString('for ($attempt = 0; $attempt < 2; $attempt++)', $body);
        $this->assertStringContainsString("\$trace['timed_out'] = true;", $body);
    }

    public function test_ocr_timeouts_tolerate_a_loaded_host(): void
    {
        // High host load makes a single PSM take 20s+; the per-PSM budget must
        // be generous enough to finish the main PSM.
        $this->assertGreaterThanOrEqual(20, (int) config('services.ocrspace.tesseract_timeout'));
        $this->assertGreaterThanOrEqual(90, (int) config('services.ocrspace.ocr_total_budget_seconds'));
    }

    public function test_tesseract_default_psm_is_auto_not_single_block(): void
    {
        // PSM 6 (single uniform block) returns empty/garbled text on KTP photos
        // and caused `ktp_missing`; the default must stay PSM 3 (auto).
        $params = (new ReflectionMethod(TesseractRunner::class, 'extract'))->getParameters();
        $psm = null;
        foreach ($params as $param) {
            if ($param->getName() === 'psm') {
                $psm = $param->getDefaultValue();
            }
        }

        $this->assertSame(3, $psm);
    }
}
