import { expect, test } from '@playwright/test';

test('finance per-area renders month filter and chart state', async ({ page }) => {
    const appErrors: string[] = [];
    page.on('pageerror', (error) => appErrors.push(error.message));
    page.on('console', (message) => {
        if (message.type() === 'error') appErrors.push(message.text());
    });

    const response = await page.goto('/finance/per-area?period=2026-08&tab=summary', {
        waitUntil: 'domcontentloaded',
    });

    await expect(page.getByText('Filter laporan')).toBeVisible({ timeout: 45_000 });
    await expect(page.getByLabel('Periode invoice')).toHaveValue('2026-08');
    await expect(page.getByLabel('Tanggal acuan laporan')).toBeVisible();
    await expect(page.getByText(/Tagihan dan outstanding per area|Belum ada data area untuk periode ini/).first()).toBeVisible({
        timeout: 45_000,
    });

    expect(response?.status() ?? 0).toBeLessThan(400);
    expect(appErrors).toEqual([]);
});
