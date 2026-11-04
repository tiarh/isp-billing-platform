import { expect, test } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';

// Fixed devices from database/seeders/GenieAcsCpeE2ESeeder.php.
// Screenshots go to test-results/ (uploaded with the e2e-sandbox-artifacts).

function collectAppErrors(page: Page): string[] {
    const appErrors: string[] = [];
    page.on('pageerror', (error) => appErrors.push(error.message));
    page.on('console', (message) => {
        if (message.type() === 'error' && !/Failed to load resource/.test(message.text())) appErrors.push(message.text());
    });

    return appErrors;
}

// The drawer scrolls inside itself, so a page screenshot only shows the top. Scroll the WiFi rows
// (2.4 GHz ... Restart terakhir) into view first and take an element screenshot of the drawer.
async function shootDrawerRows(dialog: Locator, path: string) {
    await dialog.getByText('Restart terakhir').scrollIntoViewIfNeeded();
    await dialog.getByText('WiFi 2.4 GHz').scrollIntoViewIfNeeded();
    await expect(dialog.getByText('WiFi 2.4 GHz')).toBeInViewport();
    await dialog.screenshot({ path });
}

// listShowsWifi: the WiFi column of the list is not rendered at phone width, the detail drawer is the place there.
async function check(page: Page, tag: string, listShowsWifi: boolean) {
    const appErrors = collectAppErrors(page);

    // list column: fresh device shows the client count, stale device "data lama"
    await page.goto('/genieacs?search=E2E-CPE', { waitUntil: 'domcontentloaded' });
    // (table row on desktop, card on mobile: match by text, not by role)
    // (the page may render a hidden twin of a text for the other breakpoint: only look at visible matches)
    const fresh = page.getByText('Uji WiFi Segar').locator('visible=true').first();
    const stale = page.getByText('Uji WiFi Lama').locator('visible=true').first();
    await expect(fresh).toBeVisible({ timeout: 45_000 });
    if (listShowsWifi) {
        await expect(page.getByText('3 klien').locator('visible=true').first()).toBeVisible();
        await expect(page.getByText('— (data lama)').locator('visible=true').first()).toBeVisible();
    }
    await page.screenshot({ path: `test-results/genieacs-wifi-list-${tag}.png`, fullPage: true });

    // detail drawer, fresh device: 2.4 GHz Nyala, 5 GHz Mati, age and last restart
    await fresh.click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByText('WiFi 2.4 GHz')).toBeVisible({ timeout: 30_000 });
    await expect(dialog.getByText('Nyala', { exact: true })).toBeVisible();
    await expect(dialog.getByText('Mati', { exact: true })).toBeVisible();
    await expect(dialog.getByText('Restart terakhir')).toBeVisible();
    await expect(dialog.getByText(/Dicek \d+ menit lalu/).first()).toBeVisible();
    await shootDrawerRows(dialog, `test-results/genieacs-wifi-detail-fresh-${tag}.png`);
    await page.keyboard.press('Escape');
    await expect(dialog).toHaveCount(0);

    // detail drawer, stale device: no number, no Nyala/Mati
    await stale.click();
    await expect(dialog.getByText('WiFi 2.4 GHz')).toBeVisible({ timeout: 30_000 });
    await expect(dialog.getByText('— (data lama)').first()).toBeVisible();
    await expect(dialog.getByText(/Dicek \d+ hari lalu/).first()).toBeVisible();
    await expect(dialog.getByText('Nyala', { exact: true })).toHaveCount(0);
    await shootDrawerRows(dialog, `test-results/genieacs-wifi-detail-stale-${tag}.png`);

    expect(appErrors).toEqual([]);
}

test.describe('GenieACS WiFi state (RCA-3)', () => {
    test('desktop', async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await check(page, 'desktop', true);
    });

    test('mobile 375px', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 812 });
        await check(page, 'mobile', false);
    });
});
