import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

// Fixture from database/seeders/PerPageE2ESeeder.php: 55 fake tickets ("Uji Baris Tiket NNN") and 55 fake saleskit leads,
// plus the broadcast "Uji Baris Siaran" (55 fake recipients, numbers 08120000001-055) and the campaign "Promo Lain E2E".

function collectAppErrors(page: Page): string[] {
    const appErrors: string[] = [];
    page.on('pageerror', (error) => appErrors.push(error.message));
    page.on('console', (message) => {
        if (message.type() === 'error' && !/Failed to load resource/.test(message.text())) appErrors.push(message.text());
    });

    return appErrors;
}

async function chooseRows(page: Page, size: string, urlPart: RegExp) {
    const reloaded = page.waitForResponse((r) => urlPart.test(r.url()) && r.ok());
    await page.getByRole('combobox', { name: 'Jumlah baris per halaman' }).click();
    await page.getByRole('option', { name: size, exact: true }).click();
    await reloaded;
}

test('tickets: rows per page 50 applies and survives page 2', async ({ page }) => {
    const appErrors = collectAppErrors(page);

    await page.goto('/tickets?search=Uji%20Baris%20Tiket', { waitUntil: 'domcontentloaded' });
    await expect(page.getByText('Hal. 1 / 3')).toBeVisible({ timeout: 45_000 });
    await expect(page.getByText('dari 55 data')).toBeVisible();
    await page.screenshot({ path: 'test-results/rows-per-page-tickets-20.png', fullPage: true });

    await chooseRows(page, '50', /\/tickets\?.*limit=50/);
    await expect(page.getByText('Hal. 1 / 2')).toBeVisible();
    await expect(page.getByText('Menampilkan 1–50 dari 55 data')).toBeVisible();
    await expect(page.getByRole('combobox', { name: 'Jumlah baris per halaman' })).toContainText('50');
    await page.getByRole('button', { name: 'Halaman berikutnya' }).scrollIntoViewIfNeeded();
    await page.screenshot({ path: 'test-results/rows-per-page-tickets-50.png', fullPage: true });

    const next = page.waitForResponse((r) => /\/tickets\?.*page=2/.test(r.url()) && r.ok());
    await page.getByRole('button', { name: 'Halaman berikutnya' }).click();
    await next;
    await expect(page.getByText('Hal. 2 / 2')).toBeVisible();
    await expect(page.getByText('Menampilkan 51–55 dari 55 data')).toBeVisible();
    await expect(page.getByRole('combobox', { name: 'Jumlah baris per halaman' })).toContainText('50');
    expect(page.url()).toContain('limit=50');
    expect(page.url()).toContain('search=');
    await page.screenshot({ path: 'test-results/rows-per-page-tickets-50-page2.png', fullPage: true });

    expect(appErrors).toEqual([]);
});

test('saleskit: rows per page 25 applies and survives page 2', async ({ page }) => {
    const appErrors = collectAppErrors(page);

    await page.goto('/saleskit?search=Calon%20Uji%20Baris', { waitUntil: 'domcontentloaded' });
    await expect(page.getByText('dari 55 data')).toBeVisible({ timeout: 45_000 });
    await expect(page.getByText('Hal. 1 / 2')).toBeVisible();
    await page.screenshot({ path: 'test-results/rows-per-page-saleskit-50.png', fullPage: true });

    await chooseRows(page, '25', /\/saleskit\?.*limit=25/);
    await expect(page.getByText('Hal. 1 / 3')).toBeVisible();
    await expect(page.getByText('Menampilkan 1–25 dari 55 data')).toBeVisible();

    const next = page.waitForResponse((r) => /\/saleskit\?.*page=2/.test(r.url()) && r.ok());
    await page.getByRole('button', { name: 'Halaman berikutnya' }).click();
    await next;
    await expect(page.getByText('Hal. 2 / 3')).toBeVisible();
    await expect(page.getByText('Menampilkan 26–50 dari 55 data')).toBeVisible();
    await expect(page.getByRole('combobox', { name: 'Jumlah baris per halaman' })).toContainText('25');
    expect(page.url()).toContain('limit=25');
    await page.screenshot({ path: 'test-results/rows-per-page-saleskit-25-page2.png', fullPage: true });

    expect(appErrors).toEqual([]);
});

test('device recovery: rows per page 10 applies and keeps the search', async ({ page }) => {
    const appErrors = collectAppErrors(page);

    // Cases come from RecoveryE2ESeeder (tickets TCK-E2E-*).
    await page.goto('/device-recovery?search=TCK-E2E', { waitUntil: 'domcontentloaded' });
    const select = page.getByRole('combobox', { name: 'Jumlah baris per halaman' });
    await expect(select).toBeVisible({ timeout: 45_000 });
    await expect(select).toContainText('20');

    const reloaded = page.waitForResponse((r) => /\/device-recovery\?.*limit=10/.test(r.url()) && r.ok());
    await select.click();
    await page.getByRole('option', { name: '10', exact: true }).click();
    await reloaded;

    await expect(select).toContainText('10');
    expect(page.url()).toContain('limit=10');
    expect(page.url()).toContain('search=TCK-E2E');
    await select.scrollIntoViewIfNeeded();
    await page.screenshot({ path: 'test-results/rows-per-page-recovery-10.png', fullPage: true });

    expect(appErrors).toEqual([]);
});

test('broadcast: list search, then recipient log search + rows per page + page 2', async ({ page }) => {
    const appErrors = collectAppErrors(page);

    // List: the search box must filter the campaigns (seeded: "Uji Baris Siaran" and "Promo Lain E2E").
    await page.goto('/broadcasts', { waitUntil: 'domcontentloaded' });
    await expect(page.getByText('Promo Lain E2E')).toBeVisible({ timeout: 45_000 });
    await expect(page.getByText('Uji Baris Siaran')).toBeVisible();
    const filtered = page.waitForResponse((r) => /\/broadcasts\?.*search=Uji/.test(r.url()) && r.ok());
    await page.getByPlaceholder('Cari kampanye...').fill('Uji Baris');
    await filtered;
    await expect(page.getByText('Promo Lain E2E')).toHaveCount(0);
    await expect(page.getByText('Uji Baris Siaran')).toBeVisible();
    await page.screenshot({ path: 'test-results/rows-per-page-broadcast-list-search.png', fullPage: true });

    // Recipient log (55 fake numbers, default 20 per page).
    await page.getByText('Uji Baris Siaran').click();
    await expect(page.getByText('dari 55 data')).toBeVisible({ timeout: 45_000 });
    await expect(page.getByText('Hal. 1 / 3')).toBeVisible();

    // Search narrows the log: 0812000005 matches numbers 050-055 (6 rows).
    const searched = page.waitForResponse((r) => /\/broadcasts\/\d+\?.*search=0812000005/.test(r.url()) && r.ok());
    await page.getByPlaceholder('Cari nama atau nomor...').fill('0812000005');
    await searched;
    await expect(page.getByText('dari 6 data')).toBeVisible();
    await expect(page.getByText('08120000050')).toBeVisible();
    await expect(page.getByText('08120000001')).toHaveCount(0);
    await page.getByText('dari 6 data').scrollIntoViewIfNeeded();
    await page.screenshot({ path: 'test-results/rows-per-page-broadcast-log-search.png', fullPage: true });

    // Search everything again, 25 per page, then page 2: the search and size stay in the URL.
    const all = page.waitForResponse((r) => /\/broadcasts\/\d+\?.*search=08120000(&|$)/.test(r.url()) && r.ok());
    await page.getByPlaceholder('Cari nama atau nomor...').fill('08120000');
    await all;
    await expect(page.getByText('dari 55 data')).toBeVisible();
    await chooseRows(page, '25', /\/broadcasts\/\d+\?.*limit=25/);
    await expect(page.getByText('Hal. 1 / 3')).toBeVisible();
    await expect(page.getByText('Menampilkan 1–25 dari 55 data')).toBeVisible();

    const next = page.waitForResponse((r) => /\/broadcasts\/\d+\?.*page=2/.test(r.url()) && r.ok());
    await page.getByRole('button', { name: 'Halaman berikutnya' }).click();
    await next;
    await expect(page.getByText('Hal. 2 / 3')).toBeVisible();
    await expect(page.getByText('Menampilkan 26–50 dari 55 data')).toBeVisible();
    await expect(page.getByRole('combobox', { name: 'Jumlah baris per halaman' })).toContainText('25');
    expect(page.url()).toContain('limit=25');
    expect(page.url()).toContain('search=08120000');
    await page.getByRole('button', { name: 'Halaman berikutnya' }).scrollIntoViewIfNeeded();
    await page.screenshot({ path: 'test-results/rows-per-page-broadcast-log-25-page2.png', fullPage: true });

    expect(appErrors).toEqual([]);
});
