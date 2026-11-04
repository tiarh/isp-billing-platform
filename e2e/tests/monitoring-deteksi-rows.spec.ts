import { expect, test, type Page } from '@playwright/test';

/**
 * Deteksi Gangguan rows, detail panel and the small-screen cards (fixtures: OutageShadowE2ESeeder, OutageManualTicketE2ESeeder).
 * The old list is opened with "Semua" + info on so the pinned rows (five, open for more than 24 h) are all there.
 *
 * Screenshots: the page scrolls inside a container, so fullPage does nothing. These shots are the viewport itself
 * (the list must be visible WITHOUT scrolling) or the viewport after scrolling an element to the top.
 */

const ALL = '/monitoring?tab=deteksi&view=semua&info=1';
const API = /\/api\/monitoring\/outage-shadow\?/;
/** Seeded by OutageManualTicketE2ESeeder: confirmed single_los, 40 days old, never ticketed by this spec. */
const MOBILE_EPISODE = 9300003;

function collectAppErrors(page: Page): string[] {
    const appErrors: string[] = [];
    page.on('pageerror', (error) => appErrors.push(error.message));
    page.on('console', (message) => {
        if (message.type() === 'error') appErrors.push(message.text());
    });

    return appErrors;
}

async function openAll(page: Page): Promise<void> {
    await page.goto(ALL, { waitUntil: 'domcontentloaded' });
    await expect(page.getByTestId('deteksi-total')).toHaveText(/^\d+ kejadian$/, { timeout: 45_000 });
    await expect(page.getByTestId('deteksi-pinned-band')).toBeVisible();
}

/** Brings an element to the top of the scrolling area, lets transitions settle, then takes the viewport shot. */
async function shotAt(page: Page, target: ReturnType<Page['getByTestId']>, path: string): Promise<void> {
    await target.evaluate((el) => el.scrollIntoView({ block: 'start' }));
    await page.waitForTimeout(400);
    await page.screenshot({ path });
}

/** Sideways overflow in pixels (0 = none) on the document and on the app's own scrolling container (<main>). */
async function sidewaysOverflow(page: Page): Promise<{ page: number; inner: number }> {
    return page.evaluate(() => {
        const doc = document.scrollingElement!;
        const main = document.querySelector('main')!;

        return {
            page: Math.max(0, doc.scrollWidth - doc.clientWidth),
            inner: Math.max(0, main.scrollWidth - main.clientWidth),
        };
    });
}

const detailButtons = (page: Page) => page.getByRole('button', { name: /Buka detail kejadian/ });

test('deteksi list: the first rows are visible without scrolling and rows have four plain columns', async ({ page }) => {
    const appErrors = collectAppErrors(page);
    await openAll(page);

    const headers = page.getByRole('columnheader');
    await expect(headers).toHaveText(['Kejadian', 'Pelanggan', 'Status', 'Aksi']);
    await expect(page.getByRole('columnheader', { name: 'Seandainya aktif' })).toHaveCount(0);

    // list first: the first row ends inside the first screen (no scrolling); the precision card and the held summary
    // are collapsed lines BELOW the list
    const viewport = page.viewportSize();
    const first = await detailButtons(page).first().boundingBox();
    expect(first).not.toBeNull();
    expect(first!.y + first!.height).toBeLessThanOrEqual(viewport!.height);
    const list = await page.getByTestId('deteksi-pinned-band').boundingBox();
    const precision = await page.getByTestId('deteksi-precision-details').boundingBox();
    expect(list!.y).toBeLessThan(precision!.y);
    await expect(page.getByTestId('diagnosis-precision')).toBeHidden();
    await page.waitForTimeout(400);
    await page.screenshot({ path: 'test-results/deteksi-list-desktop.png' });

    expect(appErrors).toEqual([]);
});

test('deteksi list: pinned band on page 1 with its count, "Terbaru" divider, nothing on page 2', async ({ page }) => {
    const appErrors = collectAppErrors(page);
    await openAll(page);

    const band = page.getByTestId('deteksi-pinned-band');
    await expect(band).toContainText('Belum selesai > 24 jam (5)');
    await expect(page.getByTestId('pinned-age')).toHaveCount(5);
    await expect(page.getByTestId('deteksi-pinned-divider')).toHaveText('Terbaru');
    await shotAt(page, band, 'test-results/deteksi-pinned-band.png');

    const next = page.waitForResponse((r) => API.test(r.url()) && r.url().includes('page=2') && r.ok());
    await page.getByRole('button', { name: 'Halaman berikutnya' }).click();
    await next;
    await expect(page.getByText('Hal. 2 / 2')).toBeVisible();
    await expect(page.getByTestId('deteksi-pinned-band')).toHaveCount(0);
    await expect(page.getByTestId('deteksi-pinned-divider')).toHaveCount(0);
    await expect(page.getByTestId('pinned-age')).toHaveCount(0);
    await shotAt(page, page.getByTestId('deteksi-total'), 'test-results/deteksi-page2-desktop.png');

    expect(appErrors).toEqual([]);
});

test('deteksi detail panel: opens by click and by open= link, closes with the button, shows the sections', async ({ page }) => {
    const appErrors = collectAppErrors(page);
    await openAll(page);

    // click on the row (not on a control) opens it too: use the Detail button here, the row click below
    const row = page.getByRole('row').filter({ hasText: 'Pelanggan Riwayat' });
    await detailButtons(row).click();
    const drawer = page.getByTestId('deteksi-drawer');
    await expect(drawer).toBeVisible();
    for (const section of ['Waktu (WIB)', 'Dugaan', 'Lokasi', 'Pelanggan (1)', 'Riwayat', 'Tiket', 'Mode uji']) {
        await expect(drawer.locator('h3').filter({ hasText: section })).toHaveCount(1);
    }
    await expect(drawer).toContainText('Seandainya aktif:');
    await expect(drawer).toContainText('Dugaan sebab:');
    await expect.poll(() => new URL(page.url()).searchParams.get('open')).toMatch(/^\d+$/);
    const id = new URL(page.url()).searchParams.get('open');
    await page.waitForTimeout(400);
    await page.screenshot({ path: 'test-results/deteksi-drawer-desktop.png' });

    // close with the button: the panel goes, `open` leaves the URL, the filters stay
    await drawer.getByRole('button', { name: 'Tutup', exact: true }).click();
    await expect(drawer).toHaveCount(0);
    await expect.poll(() => new URL(page.url()).searchParams.get('open')).toBeNull();
    await expect.poll(() => new URL(page.url()).searchParams.get('view')).toBe('semua');

    // a click on the row itself (outside any link or button) opens the panel
    await row.getByTestId('outage-cause-text').click();
    await expect(page.getByTestId('deteksi-drawer')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByTestId('deteksi-drawer')).toHaveCount(0);

    // the shared link opens the list and the panel
    await page.goto(`${ALL}&open=${id}`, { waitUntil: 'domcontentloaded' });
    await expect(page.getByTestId('deteksi-drawer')).toBeVisible({ timeout: 45_000 });
    await expect(page.getByTestId('deteksi-drawer')).toContainText(`#${id}`);

    expect(appErrors).toEqual([]);
});

test('deteksi detail panel: an open= link to a row that is not on page 1 still opens it', async ({ page }) => {
    const appErrors = collectAppErrors(page);
    // take the id of a row of page 2, then open it by link (page 1 does not hold it: the panel fetches that one episode)
    await openAll(page);
    const next = page.waitForResponse((r) => API.test(r.url()) && r.url().includes('page=2') && r.ok());
    await page.getByRole('button', { name: 'Halaman berikutnya' }).click();
    await next;
    await expect(page.getByText('Hal. 2 / 2')).toBeVisible();
    const label = await detailButtons(page).first().getAttribute('aria-label');
    const onPage2 = (label ?? '').match(/(\d+)$/)?.[1];
    expect(onPage2).toBeDefined();

    await page.goto(`${ALL}&open=${onPage2}`, { waitUntil: 'domcontentloaded' });
    const drawer = page.getByTestId('deteksi-drawer');
    await expect(drawer).toBeVisible({ timeout: 45_000 });
    await expect(drawer).toContainText(`#${onPage2}`);
    await drawer.getByRole('button', { name: 'Tutup', exact: true }).click();
    await expect(drawer).toHaveCount(0);
    await expect(page.getByText('Hal. 1 / 2')).toBeVisible();

    // an unknown id opens nothing and leaves the URL clean
    await page.goto('/monitoring?tab=deteksi&open=987654', { waitUntil: 'domcontentloaded' });
    await expect(page.getByTestId('deteksi-total')).toHaveText(/^\d+ kejadian$/, { timeout: 45_000 });
    await expect.poll(() => new URL(page.url()).searchParams.get('open')).toBeNull();
    await expect(page.getByTestId('deteksi-drawer')).toHaveCount(0);

    expect(appErrors).toEqual([]);
});

test('deteksi WA link: episode=N shows that row and opens the detail panel, without tabs', async ({ page }) => {
    const appErrors = collectAppErrors(page);
    await page.goto(`/monitoring?tab=deteksi&episode=${MOBILE_EPISODE}`, { waitUntil: 'domcontentloaded' });
    await expect(page.getByText('1 kejadian')).toBeVisible({ timeout: 45_000 });
    const drawer = page.getByTestId('deteksi-drawer');
    await expect(drawer).toBeVisible();
    await expect(drawer.getByText(`#${MOBILE_EPISODE}`, { exact: true })).toBeVisible();
    await expect(page.getByTestId('deteksi-tab-perlu')).toHaveCount(0);
    // the ticket control lives in the panel once (not twice: the row hides its own while the panel is open)
    await expect(page.getByTestId('outage-ticket-button').or(page.getByTestId('outage-ticket-link'))).toHaveCount(1);
    expect(appErrors).toEqual([]);
});

test('deteksi search: "#id" shows that one episode without 0/0/0/0 tabs, an unknown id says so', async ({ page }) => {
    const appErrors = collectAppErrors(page);
    await page.goto('/monitoring?tab=deteksi', { waitUntil: 'domcontentloaded' });
    await expect(page.getByTestId('deteksi-total')).toHaveText(/^\d+ kejadian$/, { timeout: 45_000 });

    await page.getByTestId('deteksi-search').fill(`#${MOBILE_EPISODE}`);
    await expect(page.getByTestId('deteksi-total')).toHaveText('1 kejadian');
    await expect(page.getByTestId('deteksi-tab-perlu')).toHaveCount(0);
    await expect(page.getByTestId('deteksi-tab-count-semua')).toHaveCount(0);
    await expect(page.getByRole('button', { name: new RegExp(`Kejadian #${MOBILE_EPISODE} — tampilkan semua`) })).toBeVisible();

    await page.getByTestId('deteksi-search').fill('#987654');
    await expect(page.getByTestId('deteksi-empty')).toContainText('Kejadian #987654 tidak ditemukan.');
    await expect(page.getByTestId('deteksi-empty')).not.toContainText('Pelanggan');
    await expect(page.getByTestId('deteksi-tab-perlu')).toHaveCount(0);

    // "tampilkan semua" brings the normal list back
    await page.getByRole('button', { name: /tampilkan semua/ }).click();
    await expect(page.getByTestId('deteksi-tab-perlu')).toBeVisible();
    await expect(page.getByTestId('deteksi-search')).toHaveValue('');

    expect(appErrors).toEqual([]);
});

test('deteksi "24 jam" means the last 24 hours (hours=24) and the URL keeps it', async ({ page }) => {
    const appErrors = collectAppErrors(page);
    await page.goto('/monitoring?tab=deteksi', { waitUntil: 'domcontentloaded' });
    await expect(page.getByTestId('deteksi-total')).toHaveText(/^\d+ kejadian$/, { timeout: 45_000 });

    const answered = page.waitForResponse((r) => API.test(r.url()) && new URL(r.url()).searchParams.get('hours') === '24' && r.ok());
    await page.getByRole('combobox', { name: 'Rentang waktu' }).click();
    await page.getByRole('option', { name: '24 jam' }).click();
    await answered;
    await expect(page.getByRole('combobox', { name: 'Rentang waktu' })).toHaveText('24 jam');
    await expect.poll(() => new URL(page.url()).searchParams.get('hours')).toBe('24');

    // a reload keeps the preset
    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(page.getByTestId('deteksi-total')).toHaveText(/^\d+ kejadian$/, { timeout: 45_000 });
    await expect(page.getByRole('combobox', { name: 'Rentang waktu' })).toHaveText('24 jam');

    // choosing 7 days takes it out again
    await page.getByRole('combobox', { name: 'Rentang waktu' }).click();
    await page.getByRole('option', { name: '7 hari' }).click();
    await expect.poll(() => new URL(page.url()).searchParams.get('hours')).toBeNull();
    expect(appErrors).toEqual([]);
});

test('deteksi filters survive scrolling, add no history entry, and Back / Forward keep working', async ({ page }) => {
    const appErrors = collectAppErrors(page);
    await page.goto('/monitoring?tab=gangguan', { waitUntil: 'domcontentloaded' });
    await page.goto(ALL, { waitUntil: 'domcontentloaded' });
    await expect(page.getByTestId('deteksi-total')).toHaveText(/^\d+ kejadian$/, { timeout: 45_000 });
    // let the page's own deferred props arrive first (their answer carries the URL the visit started with)
    await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => undefined);
    await page.waitForTimeout(1500);
    const entries = await page.evaluate(() => window.history.length);

    const filtered = page.waitForResponse((r) => API.test(r.url()) && r.url().includes('type=mass_los') && r.ok());
    await page.getByTestId('deteksi-chip-mass_los').click();
    await filtered;
    await expect.poll(() => new URL(page.url()).searchParams.get('type')).toBe('mass_los');
    // Inertia's client-side replace: no new history entry
    expect(await page.evaluate(() => window.history.length)).toBe(entries);

    // scroll the page (inside its scrolling area) and wait: the filters must still be in the URL
    await page.getByTestId('deteksi-precision-details').scrollIntoViewIfNeeded();
    await page.mouse.move(640, 400);
    await page.mouse.wheel(0, 500);
    await page.waitForTimeout(800);
    expect(new URL(page.url()).searchParams.get('type')).toBe('mass_los');
    expect(new URL(page.url()).searchParams.get('view')).toBe('semua');
    expect(new URL(page.url()).searchParams.get('tab')).toBe('deteksi');
    expect(await page.evaluate(() => window.history.length)).toBe(entries);

    // Real behaviour: Inertia's first deferred answer pushes an extra history entry, so the first Back stays on the
    // same filtered Deteksi view and a second Back leaves Deteksi (same two presses as on main).
    await page.goBack({ waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1500);
    await expect(page).toHaveURL(/\/monitoring\?.*tab=deteksi/);
    expect(new URL(page.url()).searchParams.get('type')).toBe('mass_los');
    await page.goBack({ waitUntil: 'domcontentloaded' });
    await expect.poll(() => new URL(page.url()).searchParams.get('tab'), { timeout: 15_000 }).toBe('gangguan');
    // Forward brings the filtered Deteksi view back
    await page.goForward({ waitUntil: 'domcontentloaded' });
    await expect.poll(() => new URL(page.url()).searchParams.get('type'), { timeout: 15_000 }).toBe('mass_los');
    await expect(page.getByTestId('deteksi-chip-mass_los')).toHaveAttribute('aria-pressed', 'true', { timeout: 45_000 });
    expect(appErrors).toEqual([]);
});

test.describe('phone 390', () => {
    test.use({ viewport: { width: 390, height: 844 } });

    test('deteksi cards: no sideways scroll, card tap opens the detail as a full-screen sheet', async ({ page }) => {
        const appErrors = collectAppErrors(page);
        await openAll(page);

        // cards, not the table
        await expect(page.getByTestId('deteksi-card').first()).toBeVisible();
        await expect(page.getByRole('columnheader')).toHaveCount(0);
        expect(await sidewaysOverflow(page)).toEqual({ page: 0, inner: 0 });
        await expect(page.getByTestId('deteksi-pinned-band')).toBeVisible();
        // the controls fill the first phone screen: bring the pinned band to the top, then shoot the cards
        await shotAt(page, page.getByTestId('deteksi-pinned-band'), 'test-results/deteksi-list-mobile-390.png');

        // tap the card itself (its padding, not a link or a button)
        const card = page.getByTestId('deteksi-card').nth(1);
        await card.scrollIntoViewIfNeeded();
        await card.click({ position: { x: 6, y: 6 } });
        const drawer = page.getByTestId('deteksi-drawer');
        await expect(drawer).toBeVisible();
        const box = await drawer.boundingBox();
        expect(box).not.toBeNull();
        expect(box!.x).toBeLessThanOrEqual(1);
        expect(box!.width).toBeGreaterThanOrEqual(389);
        expect(box!.height).toBeGreaterThanOrEqual(843);
        await page.waitForTimeout(400);
        await page.screenshot({ path: 'test-results/deteksi-drawer-mobile-390.png' });
        expect(await sidewaysOverflow(page)).toEqual({ page: 0, inner: 0 });

        await drawer.getByRole('button', { name: 'Tutup', exact: true }).click();
        await expect(drawer).toHaveCount(0);
        expect(appErrors).toEqual([]);
    });
});
