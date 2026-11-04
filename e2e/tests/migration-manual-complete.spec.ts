import { expect, test } from '@playwright/test';

// "Tandai selesai" on a FAILED migration (fixture: MigrationManualCompleteE2ESeeder).
// Evidence is read-only; the close is DB-only (no device, no WA).
test('migrasi: Tandai selesai shows evidence, needs a reason, then closes the row', async ({ page }) => {
    const appErrors: string[] = [];
    page.on('pageerror', (error) => appErrors.push(error.message));

    await page.goto('/migrasi?status=all&search=e2e.mc.1', { waitUntil: 'domcontentloaded' });

    const row = page.getByRole('row').filter({ hasText: 'e2e.mc.1' });
    await expect(row).toBeVisible({ timeout: 45_000 });
    await expect(row.getByText('Gagal')).toBeVisible();

    await row.getByRole('button', { name: 'Tandai selesai' }).click();

    const dialog = page.getByRole('dialog');
    const evidence = dialog.getByTestId('manual-complete-evidence');
    await expect(evidence).toBeVisible({ timeout: 20_000 });
    await expect(evidence).toContainText('Bukti belum lengkap');
    await expect(evidence).toContainText('PPPoE offline');
    await expect(evidence).toContainText('MikroTik sudah pindah: ya');
    await expect(evidence).toContainText('OLT sudah diprovisi: ya');
    await expect(evidence).toContainText('online');

    // Reason is required: the submit button stays disabled until >= 10 characters.
    const submit = dialog.getByRole('button', { name: 'Tandai selesai' });
    await expect(submit).toBeDisabled();
    await dialog.getByLabel(/Alasan/).fill('pendek');
    await expect(submit).toBeDisabled();
    await dialog.getByLabel(/Alasan/).fill('sudah dicek, pelanggan jalan normal');
    await expect(submit).toBeEnabled();
    await page.screenshot({ path: 'test-results/migration-manual-complete-dialog.png', fullPage: true });

    await submit.click();
    await expect(dialog).toBeHidden({ timeout: 20_000 });

    const closed = page.getByRole('row').filter({ hasText: 'e2e.mc.1' });
    await expect(closed.getByText('Selesai', { exact: true }).first()).toBeVisible({ timeout: 20_000 });
    await expect(closed.getByRole('button', { name: 'Tandai selesai' })).toHaveCount(0);
    await page.screenshot({ path: 'test-results/migration-manual-complete-done.png', fullPage: true });

    expect(appErrors).toEqual([]);
});
