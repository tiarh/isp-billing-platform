import { expect, test, type Page } from '@playwright/test';

/**
 * Deteksi Gangguan controls: customer search, status tabs with counts, type chips, info toggle, date presets and the
 * empty states. Fixtures: OutageShadowE2ESeeder (fake customers "Pelanggan Uji N" / e2e.dt.N; 14 of them are inside
 * one mass episode, number 12 is beyond the ten shown inline; one ticketed row; one recent weak signal; one customer
 * with 26 closed episodes 26-29 days ago).
 *
 * The other Deteksi specs may have created tickets before this one runs (manual "Buat tiket"), so counts that depend on
 * those rows are read from the page and compared with each other, never hard-coded.
 */

function collectAppErrors(page: Page): string[] {
    const appErrors: string[] = [];
    page.on('pageerror', (error) => appErrors.push(error.message));
    page.on('console', (message) => {
        if (message.type() === 'error') appErrors.push(message.text());
    });

    return appErrors;
}

const API = /\/api\/monitoring\/outage-shadow\?/;

/** A URL param, polled: Inertia may put its own URL back after a scroll, the panel restores ours a moment later. */
const param = (page: Page, key: string) => () => new URL(page.url()).searchParams.get(key);

async function openDeteksi(page: Page, query = ''): Promise<void> {
    await page.goto(`/monitoring?tab=deteksi${query}`, { waitUntil: 'domcontentloaded' });
    await expect(page.getByText(/\d+ kejadian/)).toBeVisible({ timeout: 45_000 });
}

/** The page scrolls inside a container (fullPage does nothing): shoot the element that holds search, tabs, chips and list. */
async function shotControls(page: Page, path: string): Promise<void> {
    const main = page.getByTestId('deteksi-main');
    await main.scrollIntoViewIfNeeded();
    await page.waitForTimeout(500); // let button transitions and scroll restore settle
    await main.screenshot({ path });
}

async function tabCount(page: Page, view: string): Promise<number> {
    return Number((await page.getByTestId(`deteksi-tab-count-${view}`).innerText()).trim());
}

async function searchFlow(page: Page, tag: string): Promise<void> {
    const appErrors = collectAppErrors(page);
    await openDeteksi(page);
    const before = await tabCount(page, 'perlu');

    const listRequests: string[] = [];
    page.on('request', (r) => {
        if (API.test(r.url())) listRequests.push(r.url());
    });

    // one character is only a hint: no request
    const search = page.getByTestId('deteksi-search');
    await search.pressSequentially('U');
    await expect(page.getByTestId('deteksi-search-hint')).toHaveText('Ketik minimal 2 huruf');
    await page.waitForTimeout(700);
    expect(listRequests).toHaveLength(0);

    // six characters typed one after the other: exactly ONE list request (debounce), with q in the URL
    const answered = page.waitForResponse((r) => API.test(r.url()) && r.url().includes('q=Uji') && r.ok());
    await search.pressSequentially('ji 12', { delay: 30 });
    await answered;
    await page.waitForTimeout(700);
    expect(listRequests).toHaveLength(1);
    expect(new URL(listRequests[0]).searchParams.get('q')).toBe('Uji 12');
    await expect.poll(param(page, 'q')).toBe('Uji 12');
    await expect(page.getByTestId('deteksi-search-hint')).toHaveCount(0);

    // the mass episode with customer 12 (beyond the ten shown inline) is the answer, the match is shown first
    await expect(page.getByText('1 kejadian')).toBeVisible();
    const matched = page.getByTestId('matched-customer');
    await expect(matched).toHaveCount(1);
    await expect(matched).toContainText('Pelanggan Uji 12');
    await expect(matched).toContainText('Cocok');
    // a search without picked dates looks back 31 days
    await expect(page.getByRole('combobox', { name: 'Rentang waktu' })).toHaveText('31 hari');
    await shotControls(page, `test-results/deteksi-search-${tag}.png`);

    // clear: the list is back, the box is empty, q leaves the URL
    const cleared = page.waitForResponse((r) => API.test(r.url()) && !r.url().includes('q=') && r.ok());
    await page.getByRole('button', { name: 'Hapus pencarian' }).click();
    await cleared;
    await expect(search).toHaveValue('');
    await expect(page.getByText(`${before} kejadian`)).toBeVisible();
    await expect.poll(param(page, 'q')).toBeNull();

    expect(appErrors).toEqual([]);
}

test('deteksi search: typing waits, one request, matched customer inside a mass episode, clear', async ({ page }) => {
    await searchFlow(page, 'desktop');
});

test('deteksi search, tabs and chips work on a 390px phone', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await searchFlow(page, 'mobile-390');

    // the search box and the tab strip stay inside the phone width
    const box = await page.getByTestId('deteksi-search').boundingBox();
    expect(box).not.toBeNull();
    expect(box!.x).toBeGreaterThanOrEqual(0);
    expect(box!.x + box!.width).toBeLessThanOrEqual(390);

    // the tab strip scrolls sideways by itself; a tab further right can still be tapped
    const sedang = page.waitForResponse((r) => API.test(r.url()) && r.url().includes('view=sedang') && r.ok());
    await page.getByTestId('deteksi-tab-sedang').click();
    await sedang;
    await expect(page.getByTestId('deteksi-tab-sedang')).toHaveAttribute('aria-selected', 'true');
    await shotControls(page, 'test-results/deteksi-tabs-mobile-390.png');
});

test('deteksi search finds a customer with many closed episodes: 31 days, tab Selesai, page 2', async ({ page }) => {
    const appErrors = collectAppErrors(page);
    await openDeteksi(page);

    await page.getByTestId('deteksi-search').fill('Uji Sering');
    // their episodes are 26-29 days old and closed: the automatic 31-day window finds them, but not in "Perlu dicek"
    await expect(page.getByRole('combobox', { name: 'Rentang waktu' })).toHaveText('31 hari');
    await expect(page.getByTestId('deteksi-empty')).toContainText('Tidak ada kejadian di tab ini. Cek tab lain.');
    await expect(page.getByTestId('deteksi-tab-count-selesai')).toHaveText('26');

    const selesai = page.waitForResponse((r) => API.test(r.url()) && r.url().includes('view=selesai') && r.url().includes('q=') && r.ok());
    await page.getByTestId('deteksi-tab-selesai').click();
    await selesai;
    await expect(page.getByText('26 kejadian')).toBeVisible();
    await expect(page.getByText('Hal. 1 / 2')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Pelanggan Uji Sering' })).toHaveCount(25);

    // page 2 keeps the search: the last episode
    const next = page.waitForResponse((r) => API.test(r.url()) && r.url().includes('page=2') && r.url().includes('q=') && r.ok());
    await page.getByRole('button', { name: 'Halaman berikutnya' }).click();
    await next;
    await expect(page.getByText('Hal. 2 / 2')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Pelanggan Uji Sering' })).toHaveCount(1);
    await shotControls(page, 'test-results/deteksi-search-page2.png');

    expect(appErrors).toEqual([]);
});

test('deteksi search empty states: unknown customer, and "Cari 31 hari" after picking a short range', async ({ page }) => {
    const appErrors = collectAppErrors(page);
    await openDeteksi(page);

    await page.getByTestId('deteksi-search').fill('zzzzqx');
    await expect(page.getByTestId('deteksi-empty')).toContainText('Pelanggan "zzzzqx" tidak ditemukan. Coba nama lain, PPPoE atau no HP.');
    await shotControls(page, 'test-results/deteksi-empty-search.png');

    // the customer exists, the user picked "24 jam": nothing in that range, one click widens it to 31 days
    await page.getByTestId('deteksi-search').fill('e2e.dt.sering');
    await page.getByRole('combobox', { name: 'Rentang waktu' }).click();
    await page.getByRole('option', { name: '24 jam' }).click();
    await expect(page.getByTestId('deteksi-empty')).toContainText('Pelanggan ditemukan (1), tapi tidak ada kejadian di');
    const wider = page.waitForResponse((r) => API.test(r.url()) && r.ok());
    await page.getByRole('button', { name: 'Cari 31 hari' }).click();
    await wider;
    await expect(page.getByTestId('deteksi-tab-count-selesai')).toHaveText('26');
    await expect(page.getByRole('combobox', { name: 'Rentang waktu' })).toHaveText('31 hari');

    expect(appErrors).toEqual([]);
});

test('deteksi tabs: counts come from the server and add up, "Sedang dicek" lists the ticketed row, chip filters', async ({ page }) => {
    const appErrors = collectAppErrors(page);
    await openDeteksi(page);

    const perlu = await tabCount(page, 'perlu');
    const sedang = await tabCount(page, 'sedang');
    const selesai = await tabCount(page, 'selesai');
    const semua = await tabCount(page, 'semua');
    expect(perlu + sedang + selesai).toBe(semua);
    expect(perlu).toBeGreaterThan(20);
    expect(sedang).toBeGreaterThanOrEqual(1);
    await expect(page.getByText(`${perlu} kejadian`)).toBeVisible();
    await expect(page.getByTestId('deteksi-tab-perlu')).toHaveAttribute('aria-selected', 'true');

    // "Sedang dicek": rows that already have an open ticket
    const sedangResponse = page.waitForResponse((r) => API.test(r.url()) && r.url().includes('view=sedang') && r.ok());
    await page.getByTestId('deteksi-tab-sedang').click();
    await sedangResponse;
    await expect(page.getByText(`${sedang} kejadian`)).toBeVisible();
    await expect(page.getByRole('link', { name: 'Pelanggan Uji Tiket' }).first()).toBeVisible();
    await expect.poll(param(page, 'view')).toBe('sedang');
    await shotControls(page, 'test-results/deteksi-tab-sedang.png');

    // "Selesai"
    const selesaiResponse = page.waitForResponse((r) => API.test(r.url()) && r.url().includes('view=selesai') && r.ok());
    await page.getByTestId('deteksi-tab-selesai').click();
    await selesaiResponse;
    await expect(page.getByText(`${selesai} kejadian`)).toBeVisible();

    // back to "Perlu dicek": the default tab leaves the URL; chip "Putus massal" filters inside the tab
    const back = page.waitForResponse((r) => API.test(r.url()) && r.url().includes('view=perlu') && r.ok());
    await page.getByTestId('deteksi-tab-perlu').click();
    await back;
    await expect.poll(param(page, 'view')).toBeNull();
    const chip = page.getByTestId('deteksi-chip-mass_los');
    const chipCount = Number(((await chip.innerText()).match(/(\d+)\s*$/) ?? [])[1]);
    expect(chipCount).toBeGreaterThanOrEqual(1);
    const filtered = page.waitForResponse((r) => API.test(r.url()) && r.url().includes('type=mass_los') && r.ok());
    await chip.click();
    await filtered;
    await expect(chip).toHaveAttribute('aria-pressed', 'true');
    await expect(page.getByText(`${chipCount} kejadian`)).toBeVisible();
    // the other tabs keep their own numbers while a chip is on
    expect(await tabCount(page, 'perlu')).toBe(perlu);
    await shotControls(page, 'test-results/deteksi-chip-filter.png');

    // clicking the chip again clears it
    await chip.click();
    await expect(page.getByText(`${perlu} kejadian`)).toBeVisible();
    await expect.poll(param(page, 'type')).toBeNull();

    expect(appErrors).toEqual([]);
});

test('deteksi info toggle: weak signals and held rows are hidden until it is switched on', async ({ page }) => {
    const appErrors = collectAppErrors(page);
    await openDeteksi(page);

    const toggle = page.getByTestId('deteksi-info-toggle');
    await expect(toggle).toHaveAttribute('aria-checked', 'false');
    await expect(page.getByTestId('deteksi-chip-weak_rx')).toHaveCount(0);
    await expect(page.getByText('Ditahan: Listrik mati (bukan tiket)')).toHaveCount(0);
    const hidden = Number(((await page.getByText(/Tampilkan info \(\d+\)/).innerText()).match(/\((\d+)\)/) ?? [])[1]);
    expect(hidden).toBeGreaterThanOrEqual(2);
    const perluOff = await tabCount(page, 'perlu');

    const on = page.waitForResponse((r) => API.test(r.url()) && r.url().includes('info=1') && r.ok());
    await toggle.click();
    await on;
    await expect(toggle).toHaveAttribute('aria-checked', 'true');
    await expect.poll(param(page, 'info')).toBe('1');
    await expect(page.getByTestId('deteksi-chip-weak_rx')).toBeVisible();
    await expect(page.getByText('Ditahan: Listrik mati (bukan tiket)')).toBeVisible();
    await expect(page.getByText('Sinyal redup').first()).toBeVisible();
    expect(await tabCount(page, 'perlu')).toBeGreaterThan(perluOff);
    await shotControls(page, 'test-results/deteksi-info-on.png');

    // off again: gone from the list and from the URL
    const off = page.waitForResponse((r) => API.test(r.url()) && !r.url().includes('info=1') && r.ok());
    await toggle.click();
    await off;
    await expect(page.getByTestId('deteksi-chip-weak_rx')).toHaveCount(0);
    await expect.poll(param(page, 'info')).toBeNull();
    expect(await tabCount(page, 'perlu')).toBe(perluOff);

    expect(appErrors).toEqual([]);
});

test('deteksi old links keep working: state= shows every tab with a removable chip', async ({ page }) => {
    const appErrors = collectAppErrors(page);
    const ymd = (daysAgo: number): string => {
        const d = new Date();
        d.setUTCDate(d.getUTCDate() - daysAgo);

        return d.toISOString().slice(0, 10);
    };

    await openDeteksi(page, `&state=resolved&from=${ymd(42)}&to=${ymd(38)}`);
    await expect(page.getByTestId('deteksi-tab-semua')).toHaveAttribute('aria-selected', 'true');
    await expect(page.getByTestId('deteksi-state-chip')).toContainText('Status: Sudah normal');
    // the closed evidence rows (seeded 40 days ago) are listed
    await expect(page.getByText('Banyak putus bersamaan').first()).toBeVisible();

    const removed = page.waitForResponse((r) => API.test(r.url()) && !r.url().includes('state=') && r.ok());
    await page.getByTestId('deteksi-state-chip').click();
    await removed;
    await expect.poll(param(page, 'state')).toBeNull();

    expect(appErrors).toEqual([]);
});
