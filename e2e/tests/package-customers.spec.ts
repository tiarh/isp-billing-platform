import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

// Fixture from database/seeders/PackageCustomersE2ESeeder.php: 25 customers (22 Aktif, 3 Isolir) on one package.

function collectAppErrors(page: Page): string[] {
    const appErrors: string[] = [];
    page.on('pageerror', (error) => appErrors.push(error.message));
    page.on('console', (message) => {
        if (message.type() === 'error' && !/Failed to load resource/.test(message.text())) appErrors.push(message.text());
    });

    return appErrors;
}

async function openFixturePackage(page: Page) {
    await page.goto('/packages?search=Paket%20Uji%20Daftar%20Pelanggan', { waitUntil: 'domcontentloaded' });
    await page.getByRole('row', { name: /Paket Uji Daftar Pelanggan/ }).first().click();
    await expect(page.getByText('Pelanggan dengan paket ini')).toBeVisible({ timeout: 45_000 });
}

test('package detail lists customers, moves to page 2 and filters by status', async ({ page }) => {
    const appErrors = collectAppErrors(page);

    await openFixturePackage(page);

    await expect(page.getByText('Hal. 1 / 2')).toBeVisible();
    await expect(page.getByText('Pelanggan Paket 001', { exact: true })).toBeVisible();
    await expect(page.getByText('Pelanggan Paket 021', { exact: true })).toHaveCount(0);
    await page.screenshot({ path: 'test-results/package-customers-page1.png', fullPage: true });

    const next = page.waitForResponse((r) => /\/packages\/\d+\/customers\?.*page=2/.test(r.url()) && r.ok());
    await page.getByRole('button', { name: 'Halaman berikutnya' }).click();
    await next;

    await expect(page.getByText('Hal. 2 / 2')).toBeVisible();
    await expect(page.getByText('Pelanggan Paket 021', { exact: true })).toBeVisible();
    await expect(page.getByText('Pelanggan Paket 025', { exact: true })).toBeVisible();
    await page.screenshot({ path: 'test-results/package-customers-page2.png', fullPage: true });

    // Status filter: back to page 1 with only the 3 Isolir customers.
    const filtered = page.waitForResponse((r) => /\/packages\/\d+\/customers\?.*status=isolated/.test(r.url()) && r.ok());
    await page.getByRole('button', { name: 'Status', exact: true }).click();
    await page.getByRole('option', { name: 'Isolir' }).click();
    await filtered;
    await page.keyboard.press('Escape');

    await expect(page.getByText('dari 3 data')).toBeVisible();
    await expect(page.getByText('Pelanggan Paket 003', { exact: true })).toBeVisible();
    await expect(page.getByText('Pelanggan Paket 001', { exact: true })).toHaveCount(0);
    await page.screenshot({ path: 'test-results/package-customers-isolir.png', fullPage: true });

    expect(appErrors).toEqual([]);
});

test('package detail search narrows the list and links to the customer page', async ({ page }) => {
    const appErrors = collectAppErrors(page);

    await openFixturePackage(page);

    const searched = page.waitForResponse((r) => /\/packages\/\d+\/customers\?.*search=e2e-pkg-017/.test(r.url()) && r.ok());
    await page.getByRole('searchbox', { name: /Cari nama, kode, PPPoE/ }).fill('e2e-pkg-017');
    await searched;

    await expect(page.getByText('dari 1 data')).toBeVisible();
    await expect(page.getByText('Pelanggan Paket 017', { exact: true })).toBeVisible();

    await expect(page.getByRole('link', { name: /Lihat semua di halaman Pelanggan/ })).toHaveAttribute('href', /package_id=\d+/);

    await page.getByRole('link', { name: 'Lihat', exact: true }).click();
    await expect(page).toHaveURL(/\/customers\/\d+/);

    expect(appErrors).toEqual([]);
});
