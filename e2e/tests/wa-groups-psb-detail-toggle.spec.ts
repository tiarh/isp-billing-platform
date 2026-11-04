import { expect, test } from '@playwright/test';

// Pengaturan > Grup WhatsApp > Skynet Report: saklar "Draft PSB dan kendala" di laporan harian.
// Mulai dalam keadaan mati. Nyalakan, simpan, muat ulang (harus tetap nyala), lalu matikan lagi.
const SWITCH = '#report-psb_detail_daily_enabled';

async function saveReportCard(page: import('@playwright/test').Page) {
    // Only the Report card is dirty, so only its Simpan button is enabled.
    await page.locator('button:not([disabled])', { hasText: /^Simpan$/ }).first().click();
    const dialog = page.getByRole('alertdialog').or(page.getByRole('dialog'));
    await expect(dialog.getByText('Simpan pengaturan grup Report?')).toBeVisible();
    await dialog.getByRole('button', { name: 'Simpan', exact: true }).click();
    await expect(page.getByText('Pengaturan grup WhatsApp disimpan.').first()).toBeVisible({ timeout: 30_000 });
}

test('saklar "Draft PSB dan kendala" bisa dinyalakan, disimpan, dan dimatikan lagi', async ({ page }) => {
    const appErrors: string[] = [];
    page.on('pageerror', (error) => appErrors.push(error.message));

    await page.goto('/settings/wa-groups', { waitUntil: 'domcontentloaded' });
    const toggle = page.locator(SWITCH);
    await expect(toggle).toBeVisible({ timeout: 45_000 });
    await expect(page.getByText('Bagian "Draft PSB dan kendala" di laporan harian')).toBeVisible();
    await expect(page.getByText('Jumlah draft belum selesai, macet, dan gagal.')).toBeVisible();

    // Mati secara default.
    await expect(toggle).toHaveAttribute('aria-checked', 'false');
    await toggle.scrollIntoViewIfNeeded();
    await page.screenshot({ path: 'test-results/wa-groups-psb-detail-1-off.png', fullPage: true });

    // Nyalakan: tombol Simpan aktif, simpan lewat dialog.
    await toggle.click();
    await expect(toggle).toHaveAttribute('aria-checked', 'true');
    await page.screenshot({ path: 'test-results/wa-groups-psb-detail-2-on-unsaved.png', fullPage: true });
    await saveReportCard(page);

    // Muat ulang: tetap nyala.
    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(page.locator(SWITCH)).toHaveAttribute('aria-checked', 'true', { timeout: 45_000 });
    await page.locator(SWITCH).scrollIntoViewIfNeeded();
    await page.screenshot({ path: 'test-results/wa-groups-psb-detail-3-on-saved.png', fullPage: true });

    // Matikan lagi dan simpan (bersihkan keadaan sandbox).
    await page.locator(SWITCH).click();
    await expect(page.locator(SWITCH)).toHaveAttribute('aria-checked', 'false');
    await saveReportCard(page);
    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(page.locator(SWITCH)).toHaveAttribute('aria-checked', 'false', { timeout: 45_000 });
    await page.screenshot({ path: 'test-results/wa-groups-psb-detail-4-off-again.png', fullPage: true });

    expect(appErrors).toEqual([]);
});
