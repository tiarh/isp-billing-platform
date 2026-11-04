import { expect, test as setup } from '@playwright/test';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const authFile = path.join(__dirname, '..', '.auth', 'superadmin.json');

setup('authenticate superadmin', async ({ page }) => {
    const email = process.env.E2E_EMAIL;
    const password = process.env.E2E_PASSWORD;

    if (!email || !password) {
        throw new Error('E2E_EMAIL and E2E_PASSWORD are required for the isolated sandbox.');
    }

    fs.mkdirSync(path.dirname(authFile), { recursive: true });

    await page.goto('/login', { waitUntil: 'domcontentloaded' });
    await page.locator('#email').fill(email);
    await page.locator('#password').fill(password);

    const signIn = page.getByRole('button', { name: /sign in/i });
    await Promise.all([
        page.waitForURL(/\/dashboard/, { timeout: 45_000 }),
        signIn.click({ noWaitAfter: true }),
    ]);

    const loginError = page.getByText(/credentials do not match/i);
    if (await loginError.isVisible().catch(() => false)) {
        throw new Error(
            `Login gagal untuk ${email}. Set E2E_PASSWORD atau jalankan scripts/remote-reset-e2e-password.sh`
        );
    }

    await expect(page.locator('aside')).toBeVisible({ timeout: 15_000 });

    await page.context().storageState({ path: authFile });
});
