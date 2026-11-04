import { expect, test, type Page } from '@playwright/test';

// Public pay page /pay/{uuid}: "Ganti metode pembayaran" asks for confirmation, codes that can still be paid stay
// visible with "Tampilkan lagi", and a 4th code is refused without opening the manual-transfer fallback.
// Same fixtures as public-pay-fallback.spec.ts (fake Duitku credentials, kill switch ON, fake BANK DEMO account);
// the provider-facing JSON endpoints are mocked in the browser, so no real provider is ever contacted.
test.use({ storageState: { cookies: [], origins: [] } });

const uuid = process.env.E2E_PAY_UUID;

const METHODS = {
    ok: true,
    remaining: 150000,
    methods: [
        { code: 'BC', name: 'BCA Virtual Account', group: 'va', fee: 4000, total: 154000, image_url: null },
        { code: 'NQ', name: 'QRIS', group: 'qris', fee: 700, total: 150700, image_url: null },
    ],
};

const inHours = (h: number) => new Date(Date.now() + h * 3600_000).toISOString();

function txn(method: 'BC' | 'NQ') {
    return method === 'BC'
        ? {
              order_id: 'E2E-ORDER-BC', provider: 'duitku', status: 'pending', kind: 'va', method: 'BC', method_group: 'va',
              method_name: 'BCA Virtual Account', amount_invoice: 150000, fee: 4000, amount_charged: 154000,
              payment_url: null, va_number: '8800000000001', qr_string: null, expires_at: inHours(3),
          }
        : {
              order_id: 'E2E-ORDER-NQ', provider: 'duitku', status: 'pending', kind: 'va', method: 'NQ', method_group: 'va',
              method_name: 'QRIS Demo', amount_invoice: 150000, fee: 700, amount_charged: 150700,
              payment_url: null, va_number: '8800000000002', qr_string: null, expires_at: inHours(1),
          };
}

async function mockMethods(page: Page, openTransactions: unknown[] = []) {
    await page.route(/\/pay\/[^/]+\/online\/methods/, (route) =>
        route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ...METHODS, open_transactions: openTransactions }) }),
    );
}

// Start is POST /pay/{uuid}/online; answers per requested method, or with a fixed refusal.
async function mockStart(page: Page, refusal?: { status: number; body: unknown }) {
    await page.route(/\/pay\/[^/?]+\/online(\?.*)?$/, (route) => {
        if (refusal) {
            return route.fulfill({ status: refusal.status, contentType: 'application/json', body: JSON.stringify(refusal.body) });
        }
        const method = (route.request().postDataJSON() as { method: 'BC' | 'NQ' }).method;
        return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, transaction: txn(method) }) });
    });
}

async function startWith(page: Page, name: RegExp) {
    await page.getByRole('button', { name }).click();
    await page.getByRole('button', { name: /^Bayar Rp/ }).click();
}

test.describe('Pay page switch method guard', () => {
    test.beforeEach(() => {
        test.skip(!uuid, 'E2E_PAY_UUID not configured (pay-page fixtures not seeded)');
    });

    test('A: switching asks for confirmation; cancelling keeps the code on screen', async ({ page }) => {
        await mockMethods(page);
        await mockStart(page);
        await page.goto(`/pay/${uuid}`, { waitUntil: 'domcontentloaded' });
        await expect(page.getByTestId('online-methods')).toBeVisible({ timeout: 20_000 });
        await startWith(page, /BCA Virtual Account/);

        await expect(page.getByText('8800000000001')).toBeVisible();
        await page.getByRole('button', { name: 'Ganti metode pembayaran' }).click();
        const confirm = page.getByTestId('switch-method-confirm');
        await expect(confirm).toBeVisible();
        await expect(confirm).toContainText('Kode pembayaran sebelumnya masih bisa dibayar sampai');
        await expect(confirm).toContainText('WIB');
        await expect(confirm).toContainText('Jangan bayar dua kali.');

        await page.getByRole('button', { name: 'Tetap pakai kode ini' }).click();
        await expect(page.getByTestId('switch-method-confirm')).toHaveCount(0);
        await expect(page.getByTestId('online-instructions')).toBeVisible();
        await expect(page.getByText('8800000000001')).toBeVisible();
    });

    test('B: confirming shows the method list with the active code and "Tampilkan lagi" restores it', async ({ page }) => {
        await mockMethods(page);
        await mockStart(page);
        await page.goto(`/pay/${uuid}`, { waitUntil: 'domcontentloaded' });
        await expect(page.getByTestId('online-methods')).toBeVisible({ timeout: 20_000 });
        await startWith(page, /BCA Virtual Account/);

        await page.getByRole('button', { name: 'Ganti metode pembayaran' }).click();
        await page.getByRole('button', { name: 'Ganti metode', exact: true }).click();

        await expect(page.getByTestId('online-methods')).toBeVisible();
        const banner = page.getByTestId('active-codes-banner');
        await expect(banner).toBeVisible();
        await expect(banner).toContainText('BCA Virtual Account');
        await expect(banner).toContainText('sampai');
        await expect(banner).toContainText('WIB');
        // Panel stayed mounted and no manual fallback shows next to an active code.
        await expect(page.getByTestId('manual-fallback-alert')).toHaveCount(0);
        await expect(page.getByText('BANK DEMO')).toHaveCount(0);

        await page.getByRole('button', { name: 'Tampilkan lagi' }).click();
        await expect(page.getByTestId('online-instructions')).toBeVisible();
        await expect(page.getByText('8800000000001')).toBeVisible();
    });

    test('C: a second method keeps both codes listed; the first one can be shown again', async ({ page }) => {
        await mockMethods(page);
        await mockStart(page);
        await page.goto(`/pay/${uuid}`, { waitUntil: 'domcontentloaded' });
        await expect(page.getByTestId('online-methods')).toBeVisible({ timeout: 20_000 });
        await startWith(page, /BCA Virtual Account/);
        await page.getByRole('button', { name: 'Ganti metode pembayaran' }).click();
        await page.getByRole('button', { name: 'Ganti metode', exact: true }).click();
        await startWith(page, /^QRIS/);
        await expect(page.getByText('8800000000002')).toBeVisible();

        await page.getByRole('button', { name: 'Ganti metode pembayaran' }).click();
        await page.getByRole('button', { name: 'Ganti metode', exact: true }).click();
        await expect(page.getByTestId('active-code-row')).toHaveCount(2);

        await page.getByTestId('active-code-row').filter({ hasText: 'BCA Virtual Account' }).getByRole('button', { name: 'Tampilkan lagi' }).click();
        await expect(page.getByText('8800000000001')).toBeVisible();
    });

    test('D: EMPTY method list + active code on load: banner shown, manual fallback stays away (fails if hasActiveCode regresses)', async ({ page }) => {
        // Empty list is exactly what triggers the fallback without an active code (see public-pay-fallback A).
        await page.route(/\/pay\/[^/]+\/online\/methods/, (route) =>
            route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, remaining: 150000, methods: [], open_transactions: [txn('BC')] }) }),
        );
        await page.goto(`/pay/${uuid}`, { waitUntil: 'domcontentloaded' });

        await expect(page.getByTestId('active-codes-banner')).toBeVisible({ timeout: 20_000 });
        await expect(page.getByTestId('active-codes-banner')).toContainText('BCA Virtual Account');
        await expect(page.getByText('Belum ada metode pembayaran online')).toBeVisible();
        await expect(page.getByTestId('manual-fallback-alert')).toHaveCount(0);
        await expect(page.getByRole('tab', { name: 'Transfer Bank' })).toHaveCount(0);
        await expect(page.getByText('BANK DEMO')).toHaveCount(0);
    });

    test('F: a code for an older amount is listed with a warning and WITHOUT "Tampilkan lagi"', async ({ page }) => {
        // Server render has remaining 150000 (fixture invoice); this code was issued for a bigger bill.
        const stale = { ...txn('BC'), order_id: 'E2E-ORDER-STALE', amount_invoice: 200000, amount_charged: 204000, va_number: '8800000000999' };
        await mockMethods(page, [stale, { ...txn('NQ'), amount_invoice: 150000 }]);
        await page.goto(`/pay/${uuid}`, { waitUntil: 'domcontentloaded' });

        const rows = page.getByTestId('active-code-row');
        await expect(rows).toHaveCount(2, { timeout: 20_000 });
        const staleRow = rows.filter({ hasText: 'BCA Virtual Account' });
        await expect(staleRow.getByTestId('active-code-mismatch')).toContainText('Nominal kode ini tidak sesuai sisa tagihan');
        await expect(staleRow.getByRole('button', { name: 'Tampilkan lagi' })).toHaveCount(0);
        await expect(rows.filter({ hasText: 'QRIS Demo' }).getByRole('button', { name: 'Tampilkan lagi' })).toBeVisible();
        await expect(page.getByTestId('manual-fallback-alert')).toHaveCount(0);
    });

    test('G: confirmation moves keyboard focus to "Tetap pakai kode ini"', async ({ page }) => {
        await mockMethods(page);
        await mockStart(page);
        await page.goto(`/pay/${uuid}`, { waitUntil: 'domcontentloaded' });
        await expect(page.getByTestId('online-methods')).toBeVisible({ timeout: 20_000 });
        await startWith(page, /BCA Virtual Account/);
        await page.getByRole('button', { name: 'Ganti metode pembayaran' }).click();
        await expect(page.getByRole('button', { name: 'Tetap pakai kode ini' })).toBeFocused();
    });

    test('E: 409 too_many_open shows the reason and does NOT open the manual fallback', async ({ page }) => {
        await mockMethods(page);
        await mockStart(page, {
            status: 409,
            body: { ok: false, reason: 'too_many_open', message: 'Anda sudah punya 3 kode bayar aktif. Gunakan salah satu atau tunggu hingga kedaluwarsa.' },
        });
        await page.goto(`/pay/${uuid}`, { waitUntil: 'domcontentloaded' });
        await expect(page.getByTestId('online-methods')).toBeVisible({ timeout: 20_000 });
        await startWith(page, /BCA Virtual Account/);

        await expect(page.getByText('Anda sudah punya 3 kode bayar aktif.')).toBeVisible();
        await expect(page.getByTestId('manual-fallback-alert')).toHaveCount(0);
        await expect(page.getByText('BANK DEMO')).toHaveCount(0);
    });
});
