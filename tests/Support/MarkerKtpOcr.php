<?php

namespace Tests\Support;

use App\Services\KtpOcrService;
use App\Support\OcrBudget;
use Illuminate\Http\UploadedFile;

/**
 * KTP reader fake for PSB photo tests: an image whose bytes contain KTP_MARKER
 * reads as a fixed KTP, any other image reads as nothing. Pair with MarkerOcr.
 */
class MarkerKtpOcr extends KtpOcrService
{
    public function parseKtp(UploadedFile $file): array
    {
        return $this->read($file);
    }

    public function parseKtpFast(
        UploadedFile $file,
        ?string $localRawText = null,
        ?OcrBudget $budget = null,
        bool $localTried = true,
        bool $allowPaddle = true,
    ): array {
        return $this->read($file);
    }

    /** @return array<string, mixed> */
    private function read(UploadedFile $file): array
    {
        $bytes = (string) file_get_contents($file->getPathname());

        return str_contains($bytes, 'KTP_MARKER') ? [
            'nik' => '3507114510000002',
            'name' => 'PHOTO TEST',
            'raw_text' => 'NIK REPUBLIK INDONESIA PROVINSI JAWA TIMUR',
        ] : [];
    }
}
