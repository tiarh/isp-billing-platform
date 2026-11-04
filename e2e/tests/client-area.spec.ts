import { expect, test } from '@playwright/test';

// Customer client area (/pelanggan). The sandbox app runs with CLIENT_AREA_ENABLED=true and a
// portal account for E2E-CUSTOMER (see .github/workflows/e2e-sandbox.yml). Fresh context: no admin session.
test.use({ storageState: { cookies: [], origins: [] } });

test.describe('Client area smoke', () => {
    test('login page renders, login works, dashboard and legal pages render without JS errors', async ({ page }) => {
        const password = process.env.E2E_CLIENT_PASSWORD;
        test.skip(!password, 'E2E_CLIENT_PASSWORD not configured');

        const pageErrors: string[] = [];
        page.on('pageerror', (error) => pageErrors.push(error.message));

        await page.goto('/pelanggan/login', { waitUntil: 'domcontentloaded' });
        await expect(page.getByText('Masuk Area Pelanggan', { exact: true })).toBeVisible({ timeout: 15_000 });

        await page.locator('#code').fill('E2E-CUSTOMER');
        await page.locator('#password').fill(password!);
        await Promise.all([
            page.waitForURL(/\/pelanggan$/, { timeout: 45_000 }),
            page.getByRole('button', { name: 'Masuk', exact: true }).click({ noWaitAfter: true }),
        ]);

        await expect(page.getByText('Kode pelanggan: E2E-CUSTOMER')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByRole('button', { name: 'Keluar' })).toBeVisible();

        await page.getByRole('link', { name: /Syarat/ }).click();
        await expect(page.getByRole('heading', { name: /Syarat & Ketentuan/ })).toBeVisible({ timeout: 15_000 });
        await expect(page.getByText('DRAFT - menunggu persetujuan')).toBeVisible();

        await page.goto('/kebijakan-refund', { waitUntil: 'domcontentloaded' });
        await expect(page.getByRole('heading', { name: 'Kebijakan Refund' })).toBeVisible({ timeout: 15_000 });

        expect(pageErrors).toEqual([]);
    });

    test('wrong password shows the generic error and stays on the login page', async ({ page }) => {
        await page.goto('/pelanggan/login', { waitUntil: 'domcontentloaded' });
        await page.locator('#code').fill('E2E-CUSTOMER');
        await page.locator('#password').fill('definitely-not-the-password');
        await page.getByRole('button', { name: 'Masuk', exact: true }).click();
        await expect(page.getByText(/Kode pelanggan atau kata sandi salah/)).toBeVisible({ timeout: 15_000 });
        await expect(page).toHaveURL(/\/pelanggan\/login/);
    });
});
