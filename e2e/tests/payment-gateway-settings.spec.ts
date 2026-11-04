import { expect, test } from '@playwright/test';

// Settings > Payment Gateway (superadmin). Click-through of the pre-live additions:
//  - the callback IP list follows the selected environment (never carries sandbox IPs into production)
//  - last rejected callback IP hint
//  - pilot customer allowlist field
// Saving needs a TOTP step-up (the sandbox superadmin has none), so the save POST is intercepted: the spec
// checks exactly what the browser would send and answers with a redirect back. The server side of saving is
// covered by PaymentGatewaySettingsTest. Nothing here contacts a payment provider.

// The expected IPs are read from the page itself (the lists come from the app config), not typed in here.
const IPV4 = /\d{1,3}(?:\.\d{1,3}){3}/;
const escapeRe = (value: string) => value.replace(/\./g, '\\.');

test.describe('Payment gateway settings: IP list per environment + pilot allowlist', () => {
    test('environment switch swaps the IP list, pilot ids are posted as numbers', async ({ page }) => {
        const appErrors: string[] = [];
        page.on('pageerror', (error) => appErrors.push(error.message));

        await page.goto('/settings/payment-gateway', { waitUntil: 'domcontentloaded' });
        const ips = page.getByLabel('Daftar IP callback');
        await expect(ips).toBeVisible({ timeout: 45_000 });

        // Starts on sandbox: documented sandbox list, no rejected callback yet.
        await expect(ips).toHaveValue(IPV4);
        const SANDBOX_FIRST_IP = ((await ips.inputValue()).match(IPV4) ?? [''])[0];
        expect(SANDBOX_FIRST_IP).not.toBe('');
        await expect(page.getByTestId('duitku-last-rejected-ip')).toContainText('Belum ada callback yang ditolak');
        // The page scrolls inside its own container, so bring the area into view before the shot.
        await page.getByTestId('duitku-last-rejected-ip').scrollIntoViewIfNeeded();
        await page.screenshot({ path: 'test-results/pg-settings-sandbox.png' });

        // Switch to production: the list follows, and the confirm box appears.
        await page.locator('#dk-env').selectOption('production');
        await expect(ips).not.toHaveValue(new RegExp(escapeRe(SANDBOX_FIRST_IP)));
        const PRODUCTION_FIRST_IP = ((await ips.inputValue()).match(IPV4) ?? [''])[0];
        expect(PRODUCTION_FIRST_IP).not.toBe('');
        await expect(ips).toHaveValue(new RegExp('^\\s*' + escapeRe(PRODUCTION_FIRST_IP)));
        await page.getByLabel(/Saya paham/).check();
        await page.getByTestId('duitku-last-rejected-ip').scrollIntoViewIfNeeded();
        await page.screenshot({ path: 'test-results/pg-settings-production-ips.png' });

        // Pilot allowlist.
        const pilot = page.getByLabel('Pelanggan uji bayar online');
        await expect(pilot).toBeVisible();
        await expect(page.getByTestId('pilot-hint')).toContainText('Kosong = semua pelanggan');
        await pilot.fill('1\n2');
        await page.getByTestId('pilot-hint').scrollIntoViewIfNeeded();
        await page.screenshot({ path: 'test-results/pg-settings-production-pilot.png' });

        // Save: capture the POST (TOTP step-up is not available in the sandbox).
        let posted: Record<string, unknown> | null = null;
        await page.route(/\/settings\/payment-gateway$/, async (route) => {
            const request = route.request();
            if (request.method() !== 'POST') {
                await route.continue();

                return;
            }
            posted = request.postDataJSON() as Record<string, unknown>;
            await route.fulfill({ status: 303, headers: { location: '/settings/payment-gateway' } });
        });
        await page.getByRole('button', { name: 'Simpan pengaturan' }).click();
        await expect.poll(() => posted).not.toBeNull();

        const body = posted as unknown as Record<string, unknown>;
        expect(body.duitku_environment).toBe('production');
        expect(body.confirm_production).toBe(true);
        expect(body.pilot_customer_ids).toEqual([1, 2]);
        const sent = body.duitku_callback_ips as string[];
        expect(sent[0]).toBe(PRODUCTION_FIRST_IP);
        expect(sent).not.toContain(SANDBOX_FIRST_IP);
        expect(sent).toHaveLength(10);

        expect(appErrors).toEqual([]);
    });

    test('a customer code typed as pilot id shows a clear message for any entry', async ({ page }) => {
        await page.goto('/settings/payment-gateway', { waitUntil: 'domcontentloaded' });
        const pilot = page.getByLabel('Pelanggan uji bayar online');
        await expect(pilot).toBeVisible({ timeout: 45_000 });
        await pilot.fill('1\nE2E-CUSTOMER');

        // The hint appears while typing, for ANY entry (here the second one), before the save.
        await expect(page.getByTestId('pilot-error')).toContainText('bukan kode');
        await expect(page.getByTestId('pilot-error')).toContainText('E2E-CUSTOMER');
        await page.getByTestId('pilot-error').scrollIntoViewIfNeeded();
        await page.screenshot({ path: 'test-results/pg-settings-pilot-error.png' });
    });

    test('empty pilot list is posted as an empty array (= all customers)', async ({ page }) => {
        await page.goto('/settings/payment-gateway', { waitUntil: 'domcontentloaded' });
        await expect(page.getByLabel('Pelanggan uji bayar online')).toHaveValue('', { timeout: 45_000 });

        let posted: Record<string, unknown> | null = null;
        await page.route(/\/settings\/payment-gateway$/, async (route) => {
            if (route.request().method() !== 'POST') {
                await route.continue();

                return;
            }
            posted = route.request().postDataJSON() as Record<string, unknown>;
            await route.fulfill({ status: 303, headers: { location: '/settings/payment-gateway' } });
        });
        await page.getByRole('button', { name: 'Simpan pengaturan' }).click();
        await expect.poll(() => posted).not.toBeNull();

        expect((posted as unknown as Record<string, unknown>).pilot_customer_ids).toEqual([]);
    });
});
