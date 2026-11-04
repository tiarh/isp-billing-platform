import { expect, test } from '@playwright/test';

// A3 row actions: the overflow menu is the only path to "Pindah ke Arsip".
// Radix dropdown -> dialog hand-off must not leave the page inert after Batal.
test('customers row menu opens the archive dialog and Batal leaves the page usable', async ({ page }) => {
    await page.goto('/customers', { waitUntil: 'domcontentloaded' });

    const menuButton = page.getByRole('button', { name: /^Aksi lainnya untuk / }).first();
    await expect(menuButton).toBeVisible({ timeout: 45_000 });
    await expect(menuButton).toBeInViewport();

    await menuButton.click();
    await page.getByRole('menuitem', { name: 'Pindah ke Arsip' }).click();

    const dialog = page.getByRole('dialog');
    await expect(dialog.getByText('Pindahkan pelanggan ke arsip?')).toBeVisible();

    await dialog.getByRole('button', { name: 'Batal' }).click();
    await expect(dialog).toBeHidden();

    // Page must be clickable again (no leftover pointer-events: none on body).
    await menuButton.click();
    await expect(page.getByRole('menuitem', { name: 'Detail lengkap' })).toBeVisible();
    await page.keyboard.press('Escape');
});
