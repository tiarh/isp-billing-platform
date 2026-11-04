<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-psb')]
class OltHuaweiAutofindFrontendContractTest extends TestCase
{
    private function source(): string
    {
        return (string) file_get_contents(base_path('resources/js/Pages/OltC300/Index.tsx'));
    }

    public function test_huawei_tab_calls_the_autofind_endpoint_and_sends_the_pon_hint(): void
    {
        $source = $this->source();

        $this->assertStringContainsString("fetch('/api/olt-huawei/autofind'", $source);
        $this->assertStringContainsString("fetch('/api/olt-huawei/detect'", $source);
        $this->assertStringContainsString('pon_port: ponPort', $source);
        $this->assertStringContainsString('refresh', $source);
        // Picking a row fills the SN and detects at once with that PON.
        $this->assertStringContainsString('handleHuaweiDetect(entry.sn, entry.pon_port)', $source);
    }

    public function test_huawei_tab_uses_the_agreed_plain_texts(): void
    {
        $source = $this->source();

        foreach ([
            'Lihat ONT baru',
            'ONT baru di OLT',
            'Belum terdaftar. Klik salah satu.',
            'Muat ulang',
            'Tidak ada ONT baru. Pastikan ONT menyala, tunggu 1 menit, lalu muat ulang.',
            'Sebagian ONT tidak terbaca. Muat ulang.',
            'Pilih ONT baru, cek hasil deteksi, lalu provision. Akun PPPoE tetap lewat PSB WA.',
            'Boleh 12 huruf (HWTC…) atau 16 angka-huruf (4857…).',
            'Sudah terdaftar di OLT',
        ] as $text) {
            $this->assertStringContainsString($text, $source, $text);
        }
        $this->assertStringNotContainsString('Jalur Huawei terpisah dari C300', $source);
    }

    public function test_autofind_route_is_web_only_read_only_and_not_used_by_jobs_or_wa(): void
    {
        $routes = (string) file_get_contents(base_path('routes/web.php'));
        $this->assertMatchesRegularExpression("/Route::post\('\/autofind', \[OltHuaweiController::class, 'autofind'\]\)->name\('autofind'\);/", $routes);
        $this->assertStringNotContainsString("'autofind'])->middleware('olt-provisioning')", $routes);

        foreach (['app/Jobs', 'app/Console'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir)));
            foreach ($files as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $code = (string) file_get_contents($file->getPathname());
                $this->assertStringNotContainsString('listAutofindForUi', $code, $file->getPathname());
                $this->assertStringNotContainsString('uiPlacementRead', $code, $file->getPathname());
                $this->assertStringNotContainsString('api/olt-huawei/autofind', $code, $file->getPathname());
            }
        }
    }
}
