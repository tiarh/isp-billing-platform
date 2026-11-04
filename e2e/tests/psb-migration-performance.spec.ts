import { expect, test } from '@playwright/test';

test.describe('PSB and migration loading', () => {
    test('PSB panel loads a bounded first page', async ({ page }) => {
        const appErrors: string[] = [];
        page.on('pageerror', (error) => appErrors.push(error.message));
        page.on('console', (message) => {
            if (message.type() === 'error') appErrors.push(message.text());
        });

        const panelResponse = page.waitForResponse((response) =>
            response.url().includes('/api/psb-wa/panel')
            && response.request().method() === 'GET'
        );
        const startedAt = Date.now();
        await page.goto('/olt-c300?tab=psb', { waitUntil: 'domcontentloaded' });
        const response = await panelResponse;
        const payload = await response.json() as {
            drafts?: unknown[];
            pagination?: { per_page?: number; has_more?: boolean };
        };

        await expect(page.getByText('PSB WA', { exact: false }).first()).toBeVisible({ timeout: 15_000 });
        expect(response.status()).toBeLessThan(400);
        expect(payload.drafts?.length ?? 0).toBeLessThanOrEqual(50);
        expect(payload.pagination?.per_page).toBe(50);
        expect(Date.now() - startedAt).toBeLessThan(15_000);
        expect(appErrors).toEqual([]);
    });

    test('migration page renders bounded tables without a browser error', async ({ page }) => {
        const appErrors: string[] = [];
        page.on('pageerror', (error) => appErrors.push(error.message));
        page.on('console', (message) => {
            if (message.type() === 'error') appErrors.push(message.text());
        });

        const startedAt = Date.now();
        const response = await page.goto('/migrasi', { waitUntil: 'domcontentloaded' });
        await expect(page.locator('main').first()).toBeVisible({ timeout: 15_000 });
        await expect(page.getByText(/Import Migrasi Staging|Belum ada data migrasi/i).first()).toBeVisible({ timeout: 15_000 });

        expect(response?.status() ?? 0).toBeLessThan(400);
        expect(await page.getByText(/Halaman 1/i).count()).toBeGreaterThan(0);
        expect(Date.now() - startedAt).toBeLessThan(15_000);
        expect(appErrors).toEqual([]);
    });
});
