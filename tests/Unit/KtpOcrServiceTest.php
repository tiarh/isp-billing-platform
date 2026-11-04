<?php

namespace Tests\Unit;

use App\Services\KtpOcrService;
use Illuminate\Http\UploadedFile;
use Tests\Fixtures\SyntheticOcrFixtures;
use Tests\TestCase;

class KtpOcrServiceTest extends TestCase
{
    public function test_extracts_nik_name_rt_rw_from_noisy_ktp_text(): void
    {
        $raw = <<<'TXT'
PROVINSI JAWA TIMUR
KABUPATEN MALANG
NIK : 3 5 0 7 1 1 4 5 1 0 0 0 0 0 0 2
Nama : SITI MARIYA ULFA
Tempat/Tgl Lahir : MALANG, 05-01-1990
Jenis Kelamin : PEREMPUAN
Alamat : JL MERDEKA NO 12
RT/RW : 016/004
Kel/Desa : KRIAN
Kecamatan : KRIAN
TXT;

        $svc = new KtpOcrService;
        $parsed = $svc->extractFields($raw);

        $this->assertSame('3507114510000002', $parsed['nik']);
        $this->assertTrue($svc->isPlausibleNik($parsed['nik']));
        $this->assertStringContainsString('SITI', (string) $parsed['name']);
        $this->assertSame('16', $parsed['rt']);
        $this->assertSame('4', $parsed['rw']);
        $this->assertGreaterThanOrEqual(0.8, $parsed['confidence']);
    }

    public function test_extracts_fields_from_anonymized_synthetic_fixture(): void
    {
        $parsed = (new KtpOcrService)->extractFields(SyntheticOcrFixtures::ktpText());

        $this->assertSame('9999994510000001', $parsed['nik']);
        $this->assertSame('TEST PELANGGAN', $parsed['name']);
        $this->assertSame('1', $parsed['rt']);
        $this->assertSame('2', $parsed['rw']);
        $this->assertTrue($parsed['success']);
    }

    public function test_rejects_implausible_nik_date(): void
    {
        $svc = new KtpOcrService;
        $this->assertFalse($svc->isPlausibleNik('3507119910000001')); // day 99
        $this->assertTrue($svc->isPlausibleNik('3507114510000002')); // female day 45 → 05
    }

    public function test_tesseract_falls_back_to_eng_when_ind_language_is_missing(): void
    {
        $svc = new class extends KtpOcrService
        {
            /** @param list<string> $languages */
            public function languageFor(array $languages): ?string
            {
                return $this->tesseractLanguageOption($languages);
            }
        };

        $this->assertSame('eng', $svc->languageFor(['eng', 'osd']));
        $this->assertSame('ind+eng', $svc->languageFor(['ind', 'eng', 'osd']));
        $this->assertNull($svc->languageFor(['osd']));
    }

    public function test_fast_parse_uses_local_text_without_waiting_for_paddle(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ktp-fast-');
        file_put_contents($path, 'not-an-image-but-enough-for-uploaded-file');

        $svc = new class extends KtpOcrService
        {
            public int $paddleCalls = 0;

            protected function callPaddleOcr(string $bytes, string $filename): ?string
            {
                $this->paddleCalls++;

                return null;
            }
        };

        $file = new UploadedFile($path, 'ktp.jpg', 'image/jpeg', null, true);
        $parsed = $svc->parseKtpFast($file, "NIK: 3507114510000002\nNama: SITI MARIYA ULFA\nRT/RW: 016/004");

        @unlink($path);

        $this->assertSame(0, $svc->paddleCalls);
        $this->assertSame('3507114510000002', $parsed['nik']);
        $this->assertSame('tesseract_local', $parsed['engine']);
    }

    public function test_fast_parse_uses_bounded_paddle_fallback_for_partial_local_text(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ktp-fast-fallback-');
        file_put_contents($path, 'not-an-image-but-enough-for-uploaded-file');

        $svc = new class extends KtpOcrService
        {
            public int $paddleCalls = 0;

            protected function callPaddleOcr(string $bytes, string $filename): ?string
            {
                $this->paddleCalls++;

                return 'NIK: 3507114510000002\nNama: SITI MARIYA ULFA\nRT/RW: 016/004';
            }
        };

        $file = new UploadedFile($path, 'ktp.jpg', 'image/jpeg', null, true);
        $parsed = $svc->parseKtpFast($file, 'NIK: 3507114510000002');

        @unlink($path);

        $this->assertSame(1, $svc->paddleCalls);
        $this->assertSame('3507114510000002', $parsed['nik']);
        $this->assertSame('paddleocr', $parsed['engine']);
    }
}
