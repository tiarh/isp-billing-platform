import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

// Fixture from database/seeders/HuaweiAutofindE2ESeeder.php: OLT "OLT GADANG E2E" on 192.0.2.10 (TEST-NET).
// The list and detect endpoints are stubbed (synthetic data); only the last test hits the real backend.

const OLT_NAME = 'OLT GADANG E2E';

const ENTRIES = [
    { sn: 'FHTT0000A052', sn_hex: '464854540000A052', pon_port: '0/0/1', vendor_id: 'FHTT', model: 'HG6145F', software: 'RP2521', seen_at: '2026-10-22 09:02:03+07:00' },
    { sn: 'HWTC0000A005', sn_hex: '485754430000A005', pon_port: '0/0/3', vendor_id: 'HWTC', model: 'EG8145V5', software: 'V5R019C10S125', seen_at: '2026-10-22 09:14:03+07:00' },
    { sn: 'ALCLA0000003', sn_hex: '414C434CA0000003', pon_port: '0/0/9', vendor_id: 'ALCL', model: 'G-140W-C', software: '3FE49000AOCB', seen_at: '2026-10-22 09:20:40+07:00' },
];

function listResponse(entries: unknown[]) {
    return {
        success: true,
        olt_id: 1,
        count: entries.length,
        entries,
        partial: false,
        fetched_at: '2026-10-22T09:30:00+07:00',
        age_seconds: 3,
        cached: false,
    };
}

function collectAppErrors(page: Page): string[] {
    const appErrors: string[] = [];
    page.on('pageerror', (error) => appErrors.push(error.message));
    page.on('console', (message) => {
        if (message.type() === 'error' && !/Failed to load resource/.test(message.text())) appErrors.push(message.text());
    });

    return appErrors;
}

async function openHuaweiTab(page: Page) {
    await page.goto('/olt-c300?tab=huawei', { waitUntil: 'domcontentloaded' });
    await expect(page.getByText('Pilih OLT Huawei', { exact: true })).toBeVisible({ timeout: 45_000 });
    await page.getByRole('combobox').filter({ hasText: 'Pilih OLT Huawei' }).click();
    await page.getByRole('option', { name: new RegExp(OLT_NAME) }).click();
}

async function expectNoHorizontalScroll(page: Page) {
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(0);
}

test.describe('Huawei tab: Lihat ONT baru', () => {
    test('list, pick a row, detect runs by itself with the PON hint', async ({ page }) => {
        const appErrors = collectAppErrors(page);
        const listBodies: Array<Record<string, unknown>> = [];
        const detectBodies: Array<Record<string, unknown>> = [];

        await page.route('**/api/olt-huawei/autofind', async (route) => {
            listBodies.push(route.request().postDataJSON());
            await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(listResponse(ENTRIES)) });
        });
        await page.route('**/api/olt-huawei/detect', async (route) => {
            detectBodies.push(route.request().postDataJSON());
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    success: true,
                    olt_id: 1,
                    sn: 'HWTC0000A005',
                    pon_port: '0/0/3',
                    onu_id: 2,
                    onu_ref: '0/0/3:2',
                    vendor_id: 'HWTC',
                    vlan: 104,
                    tr069_vlan: 69,
                    line_profile: 22,
                    already_registered: false,
                    source: 'autofind',
                }),
            });
        });

        await openHuaweiTab(page);
        await page.getByRole('button', { name: 'Lihat ONT baru' }).click();

        await expect(page.getByText('ONT baru di OLT')).toBeVisible();
        await expect(page.getByText('Belum terdaftar. Klik salah satu.')).toBeVisible();
        await expect(page.getByTestId('hw-autofind-list').getByRole('button')).toHaveCount(3);
        await expect(page.getByText('HWTC0000A005')).toBeVisible();
        await expect(page.getByText('PON 0/0/3')).toBeVisible();
        await expect(page.getByText('EG8145V5 · HWTC · 09:14')).toBeVisible();
        await expect(page.getByText('Data 3 detik lalu')).toBeVisible();
        await page.screenshot({ path: 'test-results/huawei-autofind-list.png', fullPage: true });

        // "Muat ulang" asks the server for a fresh read.
        await page.getByRole('button', { name: 'Muat ulang', exact: true }).click();
        await expect.poll(() => listBodies.length).toBe(2);
        expect(listBodies[0].refresh).toBe(false);
        expect(listBodies[1].refresh).toBe(true);

        // Pick row 2: SN is filled and detect runs at once with the PON of that row.
        await page.getByTestId('hw-autofind-list').getByRole('button').nth(1).click();
        await expect.poll(() => detectBodies.length).toBe(1);
        expect(detectBodies[0].sn).toBe('HWTC0000A005');
        expect(detectBodies[0].pon_port).toBe('0/0/3');
        await expect(page.getByPlaceholder('HWTC… / FHTT…')).toHaveValue('HWTC0000A005');
        await expect(page.getByText('0/0/3:2', { exact: true })).toBeVisible();
        await expect(page.getByText('104 / 69', { exact: true })).toBeVisible();
        await expect(page.getByText('ONT baru', { exact: true })).toBeVisible();
        await page.screenshot({ path: 'test-results/huawei-autofind-detect.png', fullPage: true });

        // Typing by hand clears the old result and sends no PON hint.
        await page.getByPlaceholder('HWTC… / FHTT…').fill('FHTT0000A052');
        await expect(page.getByText('0/0/3:2', { exact: true })).toHaveCount(0);
        await page.getByRole('button', { name: /Deteksi SN/ }).click();
        await expect.poll(() => detectBodies.length).toBe(2);
        expect(detectBodies[1].pon_port).toBeUndefined();

        expect(appErrors).toEqual([]);
    });

    test('empty list shows the plain empty text', async ({ page }) => {
        await page.route('**/api/olt-huawei/autofind', async (route) => {
            await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(listResponse([])) });
        });

        await openHuaweiTab(page);
        await page.getByRole('button', { name: 'Lihat ONT baru' }).click();

        await expect(page.getByText('Tidak ada ONT baru. Pastikan ONT menyala, tunggu 1 menit, lalu muat ulang.')).toBeVisible();
        await page.screenshot({ path: 'test-results/huawei-autofind-empty.png', fullPage: true });
    });

    test('busy OLT shows the busy text', async ({ page }) => {
        await page.route('**/api/olt-huawei/autofind', async (route) => {
            await route.fulfill({
                status: 423,
                contentType: 'application/json',
                body: JSON.stringify({ success: false, busy: true, error: 'OLT sedang dipakai. Coba lagi 1 menit.' }),
            });
        });

        await openHuaweiTab(page);
        await page.getByRole('button', { name: 'Lihat ONT baru' }).click();

        await expect(page.getByText('OLT sedang dipakai. Coba lagi 1 menit.')).toBeVisible();
        await page.screenshot({ path: 'test-results/huawei-autofind-busy.png', fullPage: true });
    });

    test('un-stubbed: unreachable OLT gives a plain Indonesian error within 10 seconds', async ({ page }) => {
        await openHuaweiTab(page);
        await page.getByRole('button', { name: 'Lihat ONT baru' }).click();

        const error = page.getByTestId('hw-autofind-error');
        await expect(error).toBeVisible({ timeout: 10_000 });
        await expect(error).toHaveText(/OLT tidak menjawab|OLT tidak bisa dibaca/);
        const body = await page.locator('body').innerText();
        expect(body).not.toMatch(/Whoops|Stack trace|phpseclib/i);
    });
});

test.describe('Huawei tab on a 375 px phone', () => {
    test.use({ viewport: { width: 375, height: 812 } });

    test('list, pick and result fit without horizontal scroll', async ({ page }) => {
        await page.route('**/api/olt-huawei/autofind', async (route) => {
            await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(listResponse(ENTRIES)) });
        });
        await page.route('**/api/olt-huawei/detect', async (route) => {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    success: true,
                    olt_id: 1,
                    sn: 'HWTC0000A005',
                    pon_port: '0/0/3',
                    onu_id: 2,
                    onu_ref: '0/0/3:2',
                    vendor_id: 'HWTC',
                    vlan: 104,
                    tr069_vlan: 69,
                    line_profile: 22,
                    already_registered: false,
                    source: 'autofind',
                }),
            });
        });

        await openHuaweiTab(page);
        await page.getByRole('button', { name: 'Lihat ONT baru' }).click();
        await expect(page.getByTestId('hw-autofind-list').getByRole('button')).toHaveCount(3);
        await expectNoHorizontalScroll(page);
        await page.screenshot({ path: 'test-results/huawei-autofind-mobile-list.png', fullPage: true });

        await page.getByTestId('hw-autofind-list').getByRole('button').nth(1).click();
        await expect(page.getByText('0/0/3:2', { exact: true })).toBeVisible();
        await expectNoHorizontalScroll(page);
        await page.screenshot({ path: 'test-results/huawei-autofind-mobile.png', fullPage: true });
    });
});
