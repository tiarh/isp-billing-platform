import { expect, test } from '@playwright/test';

/**
 * Anti-regression: deploy stripped OltC300/Index.tsx pernah menghapus tab FiberHome.
 * Guard shell (olt-feature-guard.sh) + test ini = dua lapis proteksi.
 */
test.describe('Aktivasi C300 — FiberHome tab guard', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('/olt-c300', { waitUntil: 'domcontentloaded' });
        await page.locator('button:has-text("Auto PPPoE"), button:has-text("Provision FiberHome")').first()
            .waitFor({ state: 'visible', timeout: 45_000 });
    });

    // S6 (PR-UX3): the switcher is ui/tabs, so triggers have role="tab".
    test('semua tab aktivasi tampil (Auto PPPoE, Aktivasi, Reconfigure, FiberHome)', async ({ page }) => {
        const body = await page.locator('body').innerText();

        expect(body, 'Ziggy/route crash').not.toMatch(/Ziggy error|route .* is not in the route list/i);
        expect(body, 'Server error').not.toMatch(/Internal Server Error|Whoops|419 Page Expired/i);

        await expect(page.getByRole('tab', { name: /Auto PPPoE/i })).toBeVisible();
        await expect(page.getByRole('tab', { name: /Provision ZTE C300/i })).toBeVisible();
        await expect(page.getByRole('tab', { name: /Rekonfig ZTE/i })).toBeVisible();
        await expect(page.getByRole('tab', { name: /Provision FiberHome/i })).toBeVisible();
    });

    test('tab Provision FiberHome bisa dibuka dan punya form', async ({ page }) => {
        await page.getByRole('tab', { name: /Provision FiberHome/i }).click();

        const fhPanel = page.locator('body');
        await expect(fhPanel).toContainText(/FiberHome|Serial Number|OLT/i, { timeout: 15_000 });

        const errorText = await fhPanel.innerText();
        expect(errorText).not.toMatch(/Ziggy error|is not in the route list/i);
    });
});