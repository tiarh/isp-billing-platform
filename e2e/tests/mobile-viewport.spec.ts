import { expect, test } from '@playwright/test';

test.use({
    viewport: { width: 375, height: 812 },
    isMobile: true,
    hasTouch: true,
});

test.describe('Mobile viewport smoke (375px)', () => {
    test('dashboard loads with navigation', async ({ page }) => {
        await page.goto('/dashboard', { waitUntil: 'domcontentloaded' });
        await expect(page.locator('main')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();
    });

    test('peta map fills viewport and sidebar is closed by default', async ({ page }) => {
        await page.goto('/peta', { waitUntil: 'domcontentloaded' });
        await expect(page.getByText('Peta Aset Skynet').first()).toBeVisible({ timeout: 15_000 });

        const map = page.locator('.leaflet-container');
        await expect(map).toBeVisible({ timeout: 20_000 });

        const box = await map.boundingBox();
        expect(box).not.toBeNull();
        expect(box!.height).toBeGreaterThan(300);

        await expect(page.getByPlaceholder('Cari ODP, pelanggan...')).not.toBeVisible();

        await page.getByRole('button', { name: 'Buka panel peta' }).click();
        await expect(page.getByPlaceholder('Cari ODP, pelanggan...')).toBeVisible({ timeout: 5_000 });
    });

    // PR-UX0 / C2: a non-isolated customer (seeded E2E fixture is terminated)
    // must not get a clickable reconnect button; the reason is shown instead.
    test('customer detail does not offer reconnect for a terminated customer', async ({ page }) => {
        const res = await page.request.get('/api/customers/search?q=E2E-CUSTOMER', {
            headers: { Accept: 'application/json' },
        });
        expect(res.ok()).toBeTruthy();
        const rows = (await res.json()) as Array<{ id: number; code: string; status: string }>;
        const fixture = rows.find((row) => row.code === 'E2E-CUSTOMER' && row.status === 'terminated');
        test.skip(!fixture, 'RecoveryE2ESeeder terminated customer fixture not present');

        await page.goto(`/customers/${fixture!.id}`, { waitUntil: 'domcontentloaded' });
        await expect(page.locator('main')).toBeVisible({ timeout: 15_000 });

        await expect(page.getByRole('button', { name: 'Buka isolir' })).toBeDisabled({ timeout: 15_000 });
        await expect(page.getByTestId('customer-toggle-blocked-reason')).toContainText('Buka isolir hanya untuk pelanggan berstatus Isolir');
        await expect(page.getByText('Activate Service')).toHaveCount(0);
    });
});
