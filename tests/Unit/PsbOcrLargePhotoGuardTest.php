<?php

namespace Tests\Unit;

use App\Jobs\ProcessPsbMediaOcrJob;
use App\Services\KtpOcrService;
use App\Support\GdImageMemoryGuard;
use App\Support\OcrBudget;
use App\Support\TesseractRunner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-psb')]
class PsbOcrLargePhotoGuardTest extends TestCase
{
    private string $originalMemoryLimit = '';

    private int $lastMagickTimeout = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalMemoryLimit = (string) ini_get('memory_limit');
    }

    protected function tearDown(): void
    {
        @ini_set('memory_limit', $this->originalMemoryLimit);
        parent::tearDown();
    }

    /** PNG header only (no pixel data): enough for getimagesizefromstring. */
    private function pngHeader(int $w, int $h): string
    {
        $ihdr = pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr));
    }

    private function smallJpeg(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, 0x808080);
        ob_start();
        imagejpeg($img, null, 80);
        $out = (string) ob_get_clean();
        imagedestroy($img);

        return $out;
    }

    private function service(): object
    {
        return new class extends KtpOcrService
        {
            /** @return array{bytes:string,filename:string} */
            public ?OcrBudget $budget = null;

            public function prepare(UploadedFile $file): array
            {
                $this->activeBudget = $this->budget;

                return $this->prepareImageBytes($file);
            }
        };
    }

    public function test_estimate_for_12mp_phone_photo_exceeds_default_128m_limit(): void
    {
        $need = GdImageMemoryGuard::estimateBytes(3024, 4032) + GdImageMemoryGuard::SAFE_MARGIN_BYTES;

        $this->assertGreaterThan(100 * 1024 * 1024, $need);
        $this->assertLessThan(400 * 1024 * 1024, $need);
    }

    public function test_guard_says_no_when_injected_budget_is_too_small_and_logs_once(): void
    {
        Log::shouldReceive('warning')->once()->withArgs(fn (string $m, array $ctx): bool => ($ctx['width'] ?? 0) === 3024
            && ($ctx['height'] ?? 0) === 4032
            && ! array_key_exists('draft', $ctx));

        $fits = GdImageMemoryGuard::decodeFits($this->pngHeader(3024, 4032), 128 * 1024 * 1024, 100 * 1024 * 1024);

        $this->assertFalse($fits);
    }

    public function test_guard_says_yes_with_enough_budget_or_no_limit(): void
    {
        $bytes = $this->pngHeader(3024, 4032);

        $this->assertTrue(GdImageMemoryGuard::decodeFits($bytes, 512 * 1024 * 1024, 40 * 1024 * 1024));
        $this->assertTrue(GdImageMemoryGuard::decodeFits($bytes, -1, 40 * 1024 * 1024));
    }

    public function test_guard_rejects_bytes_without_readable_dimensions(): void
    {
        $this->assertFalse(GdImageMemoryGuard::decodeFits('not an image', 512 * 1024 * 1024, 0));
    }

    public function test_png_is_estimated_higher_than_jpeg(): void
    {
        $this->assertGreaterThan(
            GdImageMemoryGuard::estimateBytes(1440, 10000, true),
            GdImageMemoryGuard::estimateBytes(1440, 10000, false),
        );
        // The review case: tall PNG at 128M with ~25 MB used must be refused.
        Log::shouldReceive('warning')->once();
        $this->assertFalse(GdImageMemoryGuard::decodeFits($this->pngHeader(1440, 10000), 128 * 1024 * 1024, 25 * 1024 * 1024));
    }

    public function test_hard_pixel_cap_refuses_even_when_memory_would_allow_it(): void
    {
        Log::shouldReceive('warning')->once()->withArgs(fn (string $m, array $ctx): bool => ($ctx['over_pixel_cap'] ?? false) === true);

        $this->assertFalse(GdImageMemoryGuard::decodeFits($this->pngHeader(6000, 5000), -1, 0));
    }

    /** Tight memory: ~16 MB headroom, far below the estimate even for a small photo. */
    private function squeezeMemory(): void
    {
        $limit = memory_get_usage(true) + 16 * 1024 * 1024;
        $this->assertNotFalse(@ini_set('memory_limit', (string) $limit));
    }

    public function test_prepare_returns_original_bytes_only_when_guard_refuses_and_magick_fails(): void
    {
        // A real JPEG: without the guard it would be upscaled to 1800 px, so
        // getting the original bytes back proves the guard ran.
        $bytes = $this->smallJpeg(300, 200);
        $file = UploadedFile::fake()->createWithContent('ktp.jpg', $bytes);
        TesseractRunner::fakeRunner(fn (array $cmd, int $t): array => ['text' => null, 'timed_out' => false, 'ms' => 1]);
        $this->squeezeMemory();
        Log::shouldReceive('warning')->once();

        try {
            $out = $this->service()->prepare($file);
        } finally {
            TesseractRunner::fakeRunner(null);
        }

        $this->assertSame($bytes, $out['bytes']);
        $this->assertSame('ktp.jpg', $out['filename']);
    }

    /**
     * Fake magick the way the real binary behaves: stdout is empty (text null)
     * even on success; the result is the output file.
     *
     * @param  callable(string):void  $writeOutput  gets the output path
     * @param  list<list<string>>  $commands
     */
    private function fakeMagick(callable $writeOutput, bool $timedOut, array &$commands): void
    {
        TesseractRunner::fakeRunner(function (array $cmd, int $t) use ($writeOutput, $timedOut, &$commands): array {
            if (in_array('-version', $cmd, true)) {
                return ['text' => 'ImageMagick 7', 'timed_out' => false, 'ms' => 1];
            }
            $commands[] = $cmd;
            $this->lastMagickTimeout = $t;
            $writeOutput((string) end($cmd));

            return ['text' => null, 'timed_out' => $timedOut, 'ms' => 1];
        });
    }

    /** @return array{bytes:string,filename:string} */
    private function prepareSqueezed(string $bytes, bool $expectLog = true, ?OcrBudget $budget = null): array
    {
        $file = UploadedFile::fake()->createWithContent('ktp.jpg', $bytes);
        $this->squeezeMemory();
        if ($expectLog) {
            Log::shouldReceive('warning')->once();
        }

        try {
            $service = $this->service();
            $service->budget = $budget;

            return $service->prepare($file);
        } finally {
            TesseractRunner::fakeRunner(null);
        }
    }

    public function test_downscale_timeout_follows_the_ocr_budget_minus_paddle_cap(): void
    {
        config(['services.ocrspace.paddle_timeout' => 8]);
        $bytes = $this->smallJpeg(300, 200);
        $commands = [];
        Log::shouldReceive('warning')->times(3);
        $this->fakeMagick(fn (string $out) => file_put_contents($out, $this->smallJpeg(40, 30)), false, $commands);
        $this->prepareSqueezed($bytes, false, OcrBudget::start(25, fn (): float => 1000.0));
        $this->assertSame(17, $this->lastMagickTimeout); // 25 s left - 8 s Paddle cap

        $this->fakeMagick(fn (string $out) => file_put_contents($out, $this->smallJpeg(40, 30)), false, $commands);
        $this->prepareSqueezed($bytes, false, OcrBudget::start(500, fn (): float => 1000.0));
        $this->assertSame(20, $this->lastMagickTimeout); // clamped to 20 s

        $this->fakeMagick(fn (string $out) => file_put_contents($out, $this->smallJpeg(40, 30)), false, $commands);
        $this->prepareSqueezed($bytes, false, OcrBudget::start(5, fn (): float => 1000.0));
        $this->assertSame(3, $this->lastMagickTimeout); // floor of 3 s
    }

    public function test_prepare_uses_out_of_process_downscale_when_guard_refuses(): void
    {
        $bytes = $this->smallJpeg(300, 200);
        $small = $this->smallJpeg(40, 30);
        $commands = [];
        $this->fakeMagick(fn (string $out) => file_put_contents($out, $small), false, $commands);

        $out = $this->prepareSqueezed($bytes);

        $this->assertSame($small, $out['bytes']);
        $this->assertSame('ktp.jpg', $out['filename']);
        $resize = $commands[0];
        $this->assertContains('2200x2200>', $resize);
        $this->assertContains('85', $resize);
        $this->assertContains('-limit', $resize);
        foreach (['memory', 'map', 'disk'] as $limit) {
            $this->assertContains($limit, $resize);
        }
        $this->assertContains('jpeg:size=2200x2200', $resize);
    }

    public function test_downscale_timeout_means_original_bytes(): void
    {
        $bytes = $this->smallJpeg(300, 200);
        $commands = [];
        $this->fakeMagick(fn (string $out) => file_put_contents($out, $this->smallJpeg(40, 30)), true, $commands);

        $out = $this->prepareSqueezed($bytes);

        $this->assertCount(1, $commands);
        $this->assertSame($bytes, $out['bytes']);
    }

    public function test_downscale_with_empty_missing_or_broken_output_means_original_bytes(): void
    {
        $bytes = $this->smallJpeg(300, 200);
        $cases = [
            'empty' => fn (string $out) => file_put_contents($out, ''),
            'missing' => fn (string $out) => null,
            'not an image' => fn (string $out) => file_put_contents($out, 'truncated-garbage'),
        ];
        Log::shouldReceive('warning')->times(3);
        foreach ($cases as $name => $write) {
            $commands = [];
            $this->fakeMagick($write, false, $commands);

            $out = $this->prepareSqueezed($bytes, false);

            $this->assertCount(1, $commands, $name);
            $this->assertSame($bytes, $out['bytes'], $name);
        }
    }

    public function test_normal_small_photo_still_goes_through_preprocessing(): void
    {
        $bytes = $this->smallJpeg(300, 200);
        $file = UploadedFile::fake()->createWithContent('ktp.jpg', $bytes);

        $out = $this->service()->prepare($file);

        $this->assertNotSame($bytes, $out['bytes']);
        $this->assertSame('ktp.jpg', $out['filename']);
        $info = getimagesizefromstring($out['bytes']);
        $this->assertSame(1800, $info[0]); // upscaled as before
    }

    public function test_job_raises_memory_limit_only_when_lower(): void
    {
        // Limits are relative to the memory this process already uses, so the
        // test does not depend on which tests ran before it (shard layout).
        $original = (string) ini_get('memory_limit');
        $mb = 1024 * 1024;
        $base = intdiv(memory_get_usage(true), $mb) + 64;

        try {
            ini_set('memory_limit', $base.'M');

            ProcessPsbMediaOcrJob::raiseMemoryLimit(($base + 128).'M');
            $this->assertSame(($base + 128) * $mb, GdImageMemoryGuard::parseBytes((string) ini_get('memory_limit')));

            // Never lowers.
            ProcessPsbMediaOcrJob::raiseMemoryLimit($base.'M');
            $this->assertSame(($base + 128) * $mb, GdImageMemoryGuard::parseBytes((string) ini_get('memory_limit')));

            // Reads the config value by default.
            config(['services.ocrspace.media_memory_limit' => ($base + 192).'M']);
            ProcessPsbMediaOcrJob::raiseMemoryLimit();
            $this->assertSame(($base + 192) * $mb, GdImageMemoryGuard::parseBytes((string) ini_get('memory_limit')));
        } finally {
            ini_set('memory_limit', $original);
        }
    }

    public function test_job_default_memory_limit_config_is_512m(): void
    {
        $this->assertSame('512M', config('services.ocrspace.media_memory_limit'));
    }

    public function test_fatal_releases_own_lock_so_a_resend_is_not_blocked(): void
    {
        $key = 'psb-media-ocr-draft:9001';
        $lock = Cache::lock($key, 660);
        $this->assertTrue($lock->get());
        $state = (object) ['done' => false];
        $fatal = ['type' => E_ERROR, 'message' => 'Allowed memory size exhausted'];

        $released = ProcessPsbMediaOcrJob::releaseLockOnFatal($key, $lock->owner(), $state, $fatal);

        $this->assertTrue($released);
        $second = Cache::lock($key, 660);
        $this->assertTrue($second->get());
        $second->release();
    }

    public function test_oom_fatal_raises_memory_limit_before_releasing_the_lock(): void
    {
        $key = 'psb-media-ocr-draft:9004';
        $lock = Cache::lock($key, 660);
        $this->assertTrue($lock->get());
        ini_set('memory_limit', (string) (memory_get_usage(true) + 200 * 1024 * 1024));
        $before = GdImageMemoryGuard::parseBytes((string) ini_get('memory_limit'));

        $released = ProcessPsbMediaOcrJob::releaseLockOnFatal(
            $key,
            $lock->owner(),
            (object) ['done' => false],
            ['type' => E_ERROR, 'message' => 'Allowed memory size of 134217728 bytes exhausted'],
        );

        $this->assertTrue($released);
        $this->assertSame($before + 32 * 1024 * 1024, GdImageMemoryGuard::parseBytes((string) ini_get('memory_limit')));
    }

    public function test_shutdown_handler_is_noop_after_normal_release_or_without_fatal(): void
    {
        $key = 'psb-media-ocr-draft:9002';
        $lock = Cache::lock($key, 660);
        $this->assertTrue($lock->get());
        $fatal = ['type' => E_ERROR, 'message' => 'x'];

        // Clean exit: no error.
        $this->assertFalse(ProcessPsbMediaOcrJob::releaseLockOnFatal($key, $lock->owner(), (object) ['done' => false], null));
        // Non-fatal last error (notice/warning).
        $this->assertFalse(ProcessPsbMediaOcrJob::releaseLockOnFatal($key, $lock->owner(), (object) ['done' => false], ['type' => E_WARNING, 'message' => 'x']));
        // Normal finally already ran.
        $this->assertFalse(ProcessPsbMediaOcrJob::releaseLockOnFatal($key, $lock->owner(), (object) ['done' => true], $fatal));

        $this->assertFalse(Cache::lock($key, 660)->get(), 'lock must still be held');
        $lock->release();
    }

    public function test_fatal_does_not_release_a_lock_held_by_another_owner(): void
    {
        $key = 'psb-media-ocr-draft:9003';
        $other = Cache::lock($key, 660);
        $this->assertTrue($other->get());

        $released = ProcessPsbMediaOcrJob::releaseLockOnFatal(
            $key,
            'some-other-owner-token',
            (object) ['done' => false],
            ['type' => E_ERROR, 'message' => 'x'],
        );

        $this->assertFalse($released);
        $this->assertFalse(Cache::lock($key, 660)->get(), 'other owner keeps the lock');
        $other->release();
    }
}
