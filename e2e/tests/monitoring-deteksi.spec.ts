import { expect, test } from '@playwright/test';

function collectAppErrors(page: import('@playwright/test').Page): string[] {
    const appErrors: string[] = [];
    page.on('pageerror', (error) => appErrors.push(error.message));
    page.on('console', (message) => {
        if (message.type() === 'error') appErrors.push(message.text());
    });

    return appErrors;
}

/** Inertia visits to /monitoring (a filter change must NOT do one: it could dispatch the cache refresh job). */
function trackInertiaVisits(page: import('@playwright/test').Page): string[] {
    const visits: string[] = [];
    page.on('request', (r) => {
        if (r.headers()['x-inertia'] && new URL(r.url()).pathname === '/monitoring') visits.push(r.url());
    });

    return visits;
}

function addDays(ymd: string, days: number): string {
    const d = new Date(`${ymd}T00:00:00Z`);
    d.setUTCDate(d.getUTCDate() + days);

    return d.toISOString().slice(0, 10);
}

// The page opens on the tab "Perlu dicek" with the info group hidden. These older checks are about the whole list
// (pinned rows, paging, filters, dates), so they open "Semua" with the info group on: the list of before the tabs.
// 35 rows = 27 single + 1 mass + 3 manual-ticket + 2 evidence (TicketDiagnosis) + 1 ticketed + 1 recent weak signal.
// The search / tab / info controls have their own spec: monitoring-deteksi-search.spec.ts.
const ALL = '/monitoring?tab=deteksi&view=semua&info=1';
const ALL_TOTAL = '35 kejadian';

test('deteksi gangguan pagination moves to page 2 and back without errors', async ({ page }) => {
    const appErrors = collectAppErrors(page);

    await page.goto(ALL, { waitUntil: 'domcontentloaded' });

    await expect(page.getByText('Hal. 1 / 2')).toBeVisible({ timeout: 45_000 });
    await expect(page.getByText('Menampilkan 1–25')).toBeVisible();
    await page.screenshot({ path: 'test-results/monitoring-deteksi-page1.png', fullPage: true });

    const next = page.waitForResponse((r) => /\/api\/monitoring\/outage-shadow\?.*page=2/.test(r.url()) && r.ok());
    await page.getByRole('button', { name: 'Halaman berikutnya' }).click();
    await next;

    await expect(page.getByText('Hal. 2 / 2')).toBeVisible();
    await expect(page.getByText('Menampilkan 26–35')).toBeVisible();
    await page.screenshot({ path: 'test-results/monitoring-deteksi-page2.png', fullPage: true });

    await page.getByRole('button', { name: 'Halaman sebelumnya' }).click();
    await expect(page.getByText('Hal. 1 / 2')).toBeVisible();

    expect(appErrors).toEqual([]);
});

/** The page scrolls inside a container, so fullPage does nothing: bring the list to the top of the viewport first. */
async function shotList(page: import('@playwright/test').Page, path: string): Promise<void> {
    await page.getByText(/\d+ kejadian/).first().evaluate((el) => el.scrollIntoView({ block: 'start' }));
    await page.screenshot({ path });
}

async function pinnedFlow(page: import('@playwright/test').Page, tag: string): Promise<void> {
    const appErrors = collectAppErrors(page);

    await page.goto(ALL, { waitUntil: 'domcontentloaded' });
    await expect(page.getByText('Hal. 1 / 2')).toBeVisible({ timeout: 45_000 });

    // five seeded unresolved real faults are older than 24 h, oldest first: three manual-ticket rows
    // (40 days, outside the 7-day window but still shown), then 3 days and 30 h
    const labels = page.getByTestId('pinned-age');
    await expect(labels).toHaveCount(5);
    for (const i of [0, 1, 2]) await expect(labels.nth(i)).toHaveText('Terbuka 40 hari');
    await expect(labels.nth(3)).toHaveText('Terbuka 3 hari');
    await expect(labels.nth(4)).toHaveText('Terbuka 1 hari');
    // pinned rows sit above every other row: the "Terbaru" divider (the first recent row follows it) is below the last label
    const band = page.getByTestId('deteksi-pinned-band');
    await expect(band).toContainText('Belum selesai > 24 jam (5)');
    const divider = page.getByTestId('deteksi-pinned-divider');
    await expect(divider).toHaveText('Terbaru');
    const last = await labels.nth(4).boundingBox();
    const recent = await divider.boundingBox();
    expect(last).not.toBeNull();
    expect(recent).not.toBeNull();
    expect(last!.y).toBeLessThan(recent!.y);
    await shotList(page, `test-results/monitoring-deteksi-pinned-page1-${tag}.png`);
    await labels.nth(0).scrollIntoViewIfNeeded();
    await expect(labels.nth(0)).toBeInViewport();
    await page.screenshot({ path: `test-results/monitoring-deteksi-pinned-page1-label-${tag}.png` });

    // page 2 has no pinned rows (no repeat of the pinned ones)
    const next = page.waitForResponse((r) => /\/api\/monitoring\/outage-shadow\?.*page=2/.test(r.url()) && r.ok());
    await page.getByRole('button', { name: 'Halaman berikutnya' }).click();
    await next;
    await expect(page.getByText('Hal. 2 / 2')).toBeVisible();
    await expect(page.getByTestId('pinned-age')).toHaveCount(0);
    await expect(page.getByTestId('deteksi-pinned-band')).toHaveCount(0);
    await shotList(page, `test-results/monitoring-deteksi-pinned-page2-${tag}.png`);
    await page.getByRole('button', { name: 'Halaman sebelumnya' }).click();
    await expect(page.getByText('Hal. 1 / 2')).toBeVisible();

    // chip "Putus massal": the 40-day open mass row stays pinned (outside the date window), the fresh mass row is below it,
    // the single-house pinned rows drop out
    const mass = page.waitForResponse((r) => /\/api\/monitoring\/outage-shadow\?.*type=mass_los/.test(r.url()) && r.ok());
    await page.getByTestId('deteksi-chip-mass_los').click();
    await mass;
    await expect(page.getByTestId('pinned-age')).toHaveCount(1);
    await expect(page.getByTestId('pinned-age').nth(0)).toHaveText('Terbuka 40 hari');
    await expect(page.getByTestId('deteksi-pinned-band')).toContainText('Belum selesai > 24 jam (1)');
    await shotList(page, `test-results/monitoring-deteksi-pinned-filter-chip-${tag}.png`);

    expect(appErrors).toEqual([]);
}

test('deteksi gangguan pins long-open episodes on top of page 1 and keeps them out of page 2', async ({ page }) => {
    await pinnedFlow(page, 'desktop');
});

test('deteksi gangguan pinned label works on a 390px phone', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await pinnedFlow(page, 'mobile');
});

test('deteksi gangguan type filter updates the URL without an Inertia visit, and a mass row expands', async ({ page }) => {
    const appErrors = collectAppErrors(page);
    const visits = trackInertiaVisits(page);

    await page.goto(ALL, { waitUntil: 'domcontentloaded' });
    await expect(page.getByText(ALL_TOTAL)).toBeVisible({ timeout: 45_000 });
    const visitsAfterLoad = visits.length;

    const filtered = page.waitForResponse((r) => /\/api\/monitoring\/outage-shadow\?.*type=mass_los/.test(r.url()) && r.ok());
    await page.getByTestId('deteksi-chip-mass_los').click();
    await filtered;

    // the seeded 14-customer mass row plus the 40-day manual-ticket mass row (pinned, outside the date window)
    await expect(page.getByText('2 kejadian')).toBeVisible();
    await expect(page.getByText('Pelanggan Uji 1', { exact: true })).toBeVisible();
    await expect(page.getByText('Pelanggan Uji 14', { exact: true })).toHaveCount(0);
    // the filters are in the URL (Inertia's client-side replace: no request)
    await expect.poll(() => page.url()).toContain('tab=deteksi');
    await expect.poll(() => page.url()).toContain('type=mass_los');
    expect(visits.length).toBe(visitsAfterLoad);
    await page.screenshot({ path: 'test-results/monitoring-deteksi-filter-mass.png', fullPage: true });

    // the row shows three names and "+11 lainnya"; the whole list is in the detail panel
    const massRow = page.getByRole('row').filter({ hasText: 'Pelanggan Uji 1' });
    await expect(massRow).toContainText('+11 lainnya');
    await massRow.getByRole('button', { name: /Buka detail kejadian/ }).click();
    const drawer = page.getByTestId('deteksi-drawer');
    await expect(drawer).toBeVisible();
    await expect.poll(() => page.url()).toContain('open=');

    const expanded = page.waitForResponse((r) => /\/api\/monitoring\/outage-shadow\/\d+\/customers/.test(r.url()) && r.ok());
    await drawer.getByRole('button', { name: '+4 lainnya' }).click();
    await expanded;
    await expect(drawer.getByText('Pelanggan Uji 14', { exact: true })).toBeVisible();
    await expect(drawer.getByRole('button', { name: '+4 lainnya' })).toHaveCount(0);
    await page.screenshot({ path: 'test-results/monitoring-deteksi-expanded.png', fullPage: true });

    // element shot of the expanded customer list (customers 11-14 visible together)
    const expandedList = drawer.getByTestId('deteksi-drawer-customers').getByRole('list');
    const row11 = expandedList.getByText('Pelanggan Uji 11', { exact: true });
    const row14 = expandedList.getByText('Pelanggan Uji 14', { exact: true });
    await row14.scrollIntoViewIfNeeded();
    await expect(row14).toBeInViewport();
    await expect(row11).toBeInViewport();
    await expandedList.screenshot({ path: 'test-results/monitoring-deteksi-expanded-customers.png' });

    // the in-list filter box narrows the long list
    await drawer.getByLabel('Cari di daftar pelanggan').fill('Uji 13');
    await expect(expandedList.getByRole('listitem')).toHaveCount(1);
    await drawer.getByLabel('Cari di daftar pelanggan').fill('');

    // Escape closes the panel and takes `open` out of the URL
    await page.keyboard.press('Escape');
    await expect(drawer).toHaveCount(0);
    await expect.poll(() => page.url()).not.toContain('open=');

    // tab "Selesai": no mass episode is closed in the last 7 days, so the list is empty (and the chip resets)
    const resolved = page.waitForResponse((r) => /\/api\/monitoring\/outage-shadow\?.*view=selesai/.test(r.url()) && r.ok());
    await page.getByTestId('deteksi-tab-selesai').click();
    await resolved;
    await expect(page.getByText('0 kejadian')).toBeVisible();
    await expect.poll(() => page.url()).toContain('view=selesai');
    expect(visits.length).toBe(visitsAfterLoad);

    expect(appErrors).toEqual([]);
});

test('deteksi gangguan shows the cause text and opens the Riwayat of one ONU', async ({ page }) => {
    const appErrors = collectAppErrors(page);
    const visits = trackInertiaVisits(page);

    await page.goto(ALL, { waitUntil: 'domcontentloaded' });
    await expect(page.getByText(ALL_TOTAL)).toBeVisible({ timeout: 45_000 });
    const visitsAfterLoad = visits.length;

    // The seeded single_los of "Pelanggan Riwayat" was a power-off read from the OLT.
    const cause = page.getByTestId('outage-cause-text').filter({ hasText: 'Listrik mati' });
    await expect(cause).toHaveCount(1);
    await expect(cause).toContainText('Dugaan: Listrik mati');
    await page.screenshot({ path: 'test-results/monitoring-deteksi-cause.png', fullPage: true });

    // other seeded rows have a customer too (the ticketed one): open the detail panel of this customer's row
    const riwayatRow = page.getByRole('row').filter({ hasText: 'Pelanggan Riwayat' });
    await riwayatRow.getByRole('button', { name: /Buka detail kejadian/ }).click();
    const drawer = page.getByTestId('deteksi-drawer');
    await expect(drawer).toBeVisible();
    const riwayatButton = drawer.getByRole('button', { name: 'Riwayat', exact: true });
    const loaded = page.waitForResponse((r) => /\/api\/monitoring\/outage-shadow\/\d+\/history/.test(r.url()) && r.ok());
    await riwayatButton.click();
    await loaded;

    const history = page.getByTestId('outage-history');
    await expect(history).toBeVisible();
    await expect(history.getByRole('listitem')).toHaveCount(3);
    await expect(history).toContainText('PPPoE putus — perangkat pelanggan tidak merespons');
    await expect(history).toContainText('Router/ONU restart');
    await expect(history).toContainText('ONU mati (listrik)');
    await page.screenshot({ path: 'test-results/monitoring-deteksi-riwayat.png', fullPage: true });

    // closing and re-opening reuses the loaded list (no second request)
    await drawer.getByRole('button', { name: 'Tutup riwayat' }).click();
    await expect(history).toHaveCount(0);
    await drawer.getByRole('button', { name: 'Riwayat', exact: true }).click();
    await expect(page.getByTestId('outage-history').getByRole('listitem')).toHaveCount(3);

    expect(visits.length).toBe(visitsAfterLoad);
    expect(appErrors).toEqual([]);
});

test('deteksi gangguan date inputs reflect the server-applied range and widen to older episodes', async ({ page }) => {
    const appErrors = collectAppErrors(page);
    const visits = trackInertiaVisits(page);

    await page.goto(ALL, { waitUntil: 'domcontentloaded' });
    await expect(page.getByText(ALL_TOTAL)).toBeVisible({ timeout: 45_000 });
    const visitsAfterLoad = visits.length;

    // the range select shows "7 hari"; "Pilih tanggal…" opens the two date inputs
    await expect(page.getByRole('combobox', { name: 'Rentang waktu' })).toHaveText('7 hari');
    await page.getByRole('combobox', { name: 'Rentang waktu' }).click();
    await page.getByRole('option', { name: 'Pilih tanggal…' }).click();
    const to = await page.getByLabel('Sampai tanggal').inputValue();
    expect(to).toMatch(/^\d{4}-\d{2}-\d{2}$/);

    // A range of 60 days is clamped to 31 days ending at "to": the input must show the clamped value.
    const tooOld = addDays(to, -60);
    const clamped = addDays(to, -30);
    const first = page.waitForResponse((r) => r.url().includes('/api/monitoring/outage-shadow?') && r.url().includes(`from=${tooOld}`) && r.ok());
    await page.getByLabel('Dari tanggal').fill(tooOld);
    await first;
    await expect(page.getByLabel('Dari tanggal')).toHaveValue(clamped);
    await expect.poll(() => page.url()).toContain(`from=${clamped}`);
    // 35 + the 20-day-old weak signal + the 26 closed episodes of the "often" customer (26-29 days old, seeded for the search spec)
    await expect(page.getByText('62 kejadian')).toBeVisible();
    await page.screenshot({ path: 'test-results/monitoring-deteksi-clamped-range.png', fullPage: true });

    // 25 days back: the 20-day-old episode is inside, the 31-day clamp is not needed (the 26-29 day ones are out)
    const wide = addDays(to, -25);
    const second = page.waitForResponse((r) => r.url().includes('/api/monitoring/outage-shadow?') && r.url().includes(`from=${wide}`) && r.ok());
    await page.getByLabel('Dari tanggal').fill(wide);
    await second;
    await expect(page.getByLabel('Dari tanggal')).toHaveValue(wide);
    await expect(page.getByText('36 kejadian')).toBeVisible();
    // the 20-day-old weak_rx episode is the oldest row, so it is on page 2 of 36
    const nextPage = page.waitForResponse((r) => /\/api\/monitoring\/outage-shadow\?.*page=2/.test(r.url()) && r.ok());
    await page.getByRole('button', { name: 'Halaman berikutnya' }).click();
    await nextPage;
    await expect(page.getByText('Sinyal redup').first()).toBeVisible();
    await page.screenshot({ path: 'test-results/monitoring-deteksi-old-episode.png', fullPage: true });
    await page.getByRole('button', { name: 'Halaman sebelumnya' }).click();

    // back to a 7 day window: the old episode drops out again
    const recent = addDays(to, -6);
    const third = page.waitForResponse((r) => r.url().includes('/api/monitoring/outage-shadow?') && r.url().includes(`from=${recent}`) && r.ok());
    await page.getByLabel('Dari tanggal').fill(recent);
    await third;
    await expect(page.getByText(ALL_TOTAL)).toBeVisible();
    expect(visits.length).toBe(visitsAfterLoad);

    expect(appErrors).toEqual([]);
});
