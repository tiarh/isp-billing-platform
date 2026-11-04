import { expect, test, type Page } from '@playwright/test';

// Public pay page /pay/{uuid}: manual bank transfer reappears when online payment is unavailable.
// The sandbox app is seeded (see .github/workflows/e2e-sandbox.yml) with fake Duitku credentials, the
// payment kill switch ON and a fake "BANK DEMO" account. The provider-facing JSON endpoints are mocked in
// the browser, so no real payment provider is ever contacted. Fresh context: no admin session.
test.use({ storageState: { cookies: [], origins: [] } });

const uuid = process.env.E2E_PAY_UUID;

const METHODS = {
    ok: true,
    remaining: 150000,
    methods: [{ code: 'BC', name: 'BCA Virtual Account', group: 'va', fee: 4000, total: 154000, image_url: null }],
};

async function mockMethods(page: Page, status: number, body: unknown) {
    await page.route(/\/pay\/[^/]+\/online\/methods/, (route) =>
        route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) }),
    );
}

async function mockStart(page: Page, status: number, body: unknown) {
    // Start is POST /pay/{uuid}/online (no suffix); the methods and status GETs have their own suffixes.
    await page.route(/\/pay\/[^/?]+\/online(\?.*)?$/, (route) =>
        route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) }),
    );
}

async function selectAndPay(page: Page) {
    await page.getByRole('button', { name: /BCA Virtual Account/ }).click();
    await page.getByRole('button', { name: /^Bayar Rp/ }).click();
}

test.describe('Pay page manual-transfer fallback', () => {
    test.beforeEach(() => {
        test.skip(!uuid, 'E2E_PAY_UUID not configured (pay-page fixtures not seeded)');
    });

    test('A: empty online method list opens the manual transfer tab', async ({ page }) => {
        await mockMethods(page, 200, { ok: true, remaining: 150000, methods: [] });
        await page.goto(`/pay/${uuid}`, { waitUntil: 'domcontentloaded' });

        await expect(page.getByTestId('manual-fallback-alert')).toBeVisible({ timeout: 20_000 });
        await expect(page.getByText('Pembayaran online sedang tidak tersedia')).toBeVisible();
        await expect(page.getByTestId('manual-fallback-hint')).toBeVisible();
        await expect(page.getByText('BANK DEMO')).toBeVisible();
        await expect(page.getByText('0000000001')).toBeVisible();
    });

    test('B: methods endpoint failure opens the manual transfer tab', async ({ page }) => {
        await mockMethods(page, 502, {});
        await page.goto(`/pay/${uuid}`, { waitUntil: 'domcontentloaded' });

        await expect(page.getByTestId('manual-fallback-alert')).toBeVisible({ timeout: 20_000 });
        await expect(page.getByText('BANK DEMO')).toBeVisible();
    });

    test('C: provider error on start keeps the online tab and offers a button to the accounts', async ({ page }) => {
        await mockMethods(page, 200, METHODS);
        await mockStart(page, 502, { ok: false, reason: 'provider_error', message: 'Mock start gangguan.' });
        await page.goto(`/pay/${uuid}`, { waitUntil: 'domcontentloaded' });

        await expect(page.getByTestId('online-methods')).toBeVisible({ timeout: 20_000 });
        await expect(page.getByTestId('manual-fallback-alert')).toHaveCount(0);
        await selectAndPay(page);

        // The exact mocked message proves the mocked POST /pay/{uuid}/online was hit, not the real server.
        await expect(page.getByText('Mock start gangguan.')).toBeVisible();
        await expect(page.getByTestId('manual-fallback-alert')).toBeVisible();
        await expect(page.getByText('BANK DEMO')).toHaveCount(0);
        await page.getByRole('button', { name: 'Lihat rekening Transfer Bank' }).click();
        await expect(page.getByText('BANK DEMO')).toBeVisible();
        // Focus moves to the bank accounts section for keyboard / screen reader users.
        await expect(page.getByTestId('manual-accounts-heading')).toBeFocused();
    });

    test('F: after a failed start, a successful retry hides the fallback and the online panel was kept (one VA only)', async ({ page }) => {
        await mockMethods(page, 200, METHODS);
        let startCalls = 0;
        await page.route(/\/pay\/[^/?]+\/online(\?.*)?$/, (route) => {
            startCalls += 1;
            if (startCalls === 1) {
                return route.fulfill({ status: 502, contentType: 'application/json', body: JSON.stringify({ ok: false, reason: 'provider_error', message: 'Mock start gangguan.' }) });
            }
            return route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    ok: true,
                    transaction: {
                        order_id: 'E2E-ORDER-1', provider: 'duitku', status: 'pending', kind: 'va', method: 'BC', method_group: 'va',
                        method_name: 'BCA Virtual Account', amount_invoice: 150000, fee: 4000, amount_charged: 154000,
                        payment_url: null, va_number: '8800000000001', qr_string: null, expires_at: null,
                    },
                }),
            });
        });
        await page.goto(`/pay/${uuid}`, { waitUntil: 'domcontentloaded' });

        await expect(page.getByTestId('online-methods')).toBeVisible({ timeout: 20_000 });
        await selectAndPay(page);
        await expect(page.getByTestId('manual-fallback-alert')).toBeVisible();

        // Look at the bank accounts and come back: the online panel (and the chosen method) must still be there.
        await page.getByRole('button', { name: 'Lihat rekening Transfer Bank' }).click();
        await expect(page.getByText('BANK DEMO')).toBeVisible();
        await page.getByRole('tab', { name: 'Bayar Instan' }).click();
        await page.getByRole('button', { name: /^Bayar Rp/ }).click(); // enabled without re-selecting: panel stayed mounted

        await expect(page.getByTestId('online-instructions')).toBeVisible();
        await expect(page.getByText('8800000000001')).toBeVisible();
        // Active VA: no fallback alert, no Transfer Bank tab, no bank accounts next to it.
        await expect(page.getByTestId('manual-fallback-alert')).toHaveCount(0);
        await expect(page.getByRole('tab', { name: 'Transfer Bank' })).toHaveCount(0);
        await expect(page.getByText('BANK DEMO')).toHaveCount(0);
        expect(startCalls).toBe(2);
    });

    test('G: returning from the provider gets no fallback after a failed start', async ({ page }) => {
        await mockMethods(page, 200, METHODS);
        await mockStart(page, 502, { ok: false, reason: 'provider_error', message: 'Mock start gangguan.' });
        await page.goto(`/pay/${uuid}?payment_result=return`, { waitUntil: 'domcontentloaded' });

        await expect(page.getByTestId('online-methods')).toBeVisible({ timeout: 20_000 });
        await selectAndPay(page);

        await expect(page.getByText('Mock start gangguan.')).toBeVisible();
        await expect(page.getByTestId('manual-fallback-alert')).toHaveCount(0);
        await expect(page.getByText('BANK DEMO')).toHaveCount(0);
    });

    test('D: a void invoice refusal on start does not trigger the fallback', async ({ page }) => {
        await mockMethods(page, 200, METHODS);
        await mockStart(page, 422, { ok: false, reason: 'void', message: 'Tagihan ini sudah dibatalkan dan tidak dapat dibayar. Hubungi admin.' });
        await page.goto(`/pay/${uuid}`, { waitUntil: 'domcontentloaded' });

        await expect(page.getByTestId('online-methods')).toBeVisible({ timeout: 20_000 });
        await selectAndPay(page);

        await expect(page.getByText('Pembayaran belum dimulai')).toBeVisible();
        await expect(page.getByTestId('manual-fallback-alert')).toHaveCount(0);
        await expect(page.getByText('BANK DEMO')).toHaveCount(0);
    });

    test('E: healthy online methods keep manual transfer hidden', async ({ page }) => {
        await mockMethods(page, 200, METHODS);
        await page.goto(`/pay/${uuid}`, { waitUntil: 'domcontentloaded' });

        await expect(page.getByRole('button', { name: /BCA Virtual Account/ })).toBeVisible({ timeout: 20_000 });
        await expect(page.getByTestId('manual-fallback-alert')).toHaveCount(0);
        await expect(page.getByRole('tab', { name: 'Transfer Bank' })).toHaveCount(0);
    });
});
