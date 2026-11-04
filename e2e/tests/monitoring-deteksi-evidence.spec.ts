import { expect, test } from '@playwright/test';

// Rows from database/seeders/OutageEvidenceE2ESeeder.php: 27 session_burst + 1 area_power, opened 40 days ago (outside the
// default 7 day window, so the other Deteksi spec keeps its 28 rows).

function ymd(daysAgo: number): string {
    const d = new Date();
    d.setUTCDate(d.getUTCDate() - daysAgo);

    return d.toISOString().slice(0, 10);
}

test('deteksi gangguan: session_burst rows, filter, page 2 and the area_power row', async ({ page }) => {
    const appErrors: string[] = [];
    page.on('pageerror', (error) => appErrors.push(error.message));
    page.on('console', (message) => {
        if (message.type() === 'error') appErrors.push(message.text());
    });

    // these rows are closed: open the tab "Semua" (the default tab is "Perlu dicek")
    await page.goto(`/monitoring?tab=deteksi&view=semua&from=${ymd(42)}&to=${ymd(38)}`, { waitUntil: 'domcontentloaded' });
    // other seeders may also have rows this old, so the unfiltered count is not pinned: the type chip below is
    await expect(page.getByText(/\d+ kejadian/)).toBeVisible({ timeout: 45_000 });

    // the chips offer both new types, with their plain names
    await expect(page.getByTestId('deteksi-chip-session_burst')).toContainText('Banyak putus bersamaan');
    await expect(page.getByTestId('deteksi-chip-area_power')).toContainText('Dugaan listrik padam');
    const filtered = page.waitForResponse((r) => /\/api\/monitoring\/outage-shadow\?.*type=session_burst/.test(r.url()) && r.ok());
    await page.getByTestId('deteksi-chip-session_burst').click();
    await filtered;

    await expect(page.getByText('27 kejadian')).toBeVisible();
    await expect(page.getByText('Hal. 1 / 2')).toBeVisible();
    await expect(page.getByText('11 pelanggan putus bersamaan, ONU tetap menyala. 4 sudah tersambung lagi.')).toBeVisible();
    await expect(page.getByText('12 pelanggan putus bersamaan, ONU ikut mati. 4 sudah tersambung lagi.')).toBeVisible();
    await page.screenshot({ path: 'test-results/prd-deteksi-burst-page1.png', fullPage: true });

    // page 2 holds the last two rows (newest first: the 26th and 27th burst)
    const next = page.waitForResponse((r) => /\/api\/monitoring\/outage-shadow\?.*page=2/.test(r.url()) && r.ok());
    await page.getByRole('button', { name: 'Halaman berikutnya' }).click();
    await next;
    await expect(page.getByText('Hal. 2 / 2')).toBeVisible();
    await expect(page.getByText('Menampilkan 26–27')).toBeVisible();
    await expect(page.getByText('36 pelanggan putus bersamaan, ONU ikut mati. 4 sudah tersambung lagi.')).toBeVisible();
    await expect(page.getByText('37 pelanggan putus bersamaan, ONU tetap menyala. 4 sudah tersambung lagi.')).toBeVisible();
    await page.screenshot({ path: 'test-results/prd-deteksi-burst-page2.png', fullPage: true });

    // the area_power row
    const area = page.waitForResponse((r) => /\/api\/monitoring\/outage-shadow\?.*type=area_power/.test(r.url()) && r.ok());
    await page.getByTestId('deteksi-chip-area_power').click();
    await area;
    await expect(page.getByText('1 kejadian')).toBeVisible();
    await expect(page.getByText('Kemungkinan listrik padam di area Area Bukti: 12 ONU mati bersamaan.')).toBeVisible();
    await page.screenshot({ path: 'test-results/prd-deteksi-area-power.png', fullPage: true });

    expect(appErrors).toEqual([]);
});
