import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

// Fixed ids from database/seeders/RootCauseE2ESeeder.php.
const ADMIN_TICKET = 9100001; // open incident, closed in the admin dialog ("Penyebab lain" -> "Tidak tahu")
const TECH_TICKET = 9100002; // open incident, closed from the technician form on a 375 px phone ("Lainnya" + note)
const EDIT_TICKET = 9100003; // open incident that already has a cause (edit form changes it)
const SUGGEST_TICKET = 9100004; // open incident with a stored diagnosis (wlan_down) = the suggestion
const NOT_FAULT_TICKET = 9100005; // open incident that was no fault ("Bukan gangguan")
const INSTALL_TICKET = 9100006; // open installation: no picker
const RETYPE_TICKET = 9100007; // open incident re-typed and closed in one Edit save

function collectAppErrors(page: Page): string[] {
    const appErrors: string[] = [];
    page.on('pageerror', (error) => appErrors.push(error.message));
    page.on('console', (message) => {
        if (message.type() === 'error' && !/Failed to load resource/.test(message.text())) appErrors.push(message.text());
    });

    return appErrors;
}

async function pick(page: Page, code: string) {
    await page.getByTestId(`root-cause-option-${code}`).click();
    await expect(page.getByTestId(`root-cause-option-${code}`)).toHaveAttribute('aria-pressed', 'true');
}

async function openMore(page: Page) {
    await page.getByTestId('root-cause-more').click();
    await expect(page.getByTestId('root-cause-more')).toHaveAttribute('aria-expanded', 'true');
}

async function expectNoHorizontalScroll(page: Page) {
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(1);
}

async function expectTapTargets(page: Page) {
    const viewport = page.viewportSize();
    const options = page.locator('[data-testid^="root-cause-option-"]');
    const count = await options.count();
    expect(count).toBeGreaterThanOrEqual(10);
    for (let i = 0; i < count; i++) {
        const box = await options.nth(i).boundingBox();
        expect(box).not.toBeNull();
        expect(box!.height).toBeGreaterThanOrEqual(43.5);
        if (viewport) expect(box!.x + box!.width).toBeLessThanOrEqual(viewport.width + 0.5);
    }
}

test('admin must pick a cause in the dialog; "Tidak tahu" sits under "Penyebab lain"', async ({ page }) => {
    const appErrors = collectAppErrors(page);

    await page.goto(`/tickets/${ADMIN_TICKET}`, { waitUntil: 'domcontentloaded' });
    await expect(page.getByText('Internet mati (fixture E2E penyebab ADMIN)').first()).toBeVisible({ timeout: 45_000 });

    // The status button opens the cause dialog instead of closing right away.
    await page.getByRole('button', { name: 'Resolved', exact: true }).click();
    const dialog = page.getByTestId('ticket-close-dialog');
    await expect(dialog).toBeVisible();
    await expect(dialog.getByText('Penyebab sebenarnya')).toBeVisible();
    await expect(page.getByTestId('ticket-close-confirm')).toBeDisabled();
    // Short list at once; "Tidak tahu" is not one tap away.
    await expect(page.getByTestId('root-cause-option-drop_los')).toBeVisible();
    await expect(page.getByTestId('root-cause-option-unknown')).toHaveCount(0);
    await page.screenshot({ path: 'test-results/root-cause-admin-dialog-empty.png', fullPage: true });

    await openMore(page);
    await expect(page.getByTestId('root-cause-option-unknown')).toBeVisible();
    await page.screenshot({ path: 'test-results/root-cause-admin-dialog-more.png', fullPage: true });

    await pick(page, 'unknown');
    await expect(page.getByTestId('ticket-close-confirm')).toBeEnabled();
    await page.screenshot({ path: 'test-results/root-cause-admin-dialog-selected.png', fullPage: true });

    await page.getByTestId('ticket-close-confirm').click();

    // Closed: the ticket page shows the status and the stored cause.
    await expect(page.getByTestId('ticket-root-cause')).toContainText('Tidak tahu', { timeout: 30_000 });
    await expect(page.getByText('Resolved', { exact: true }).first()).toBeVisible();
    await expect(page.getByTestId('ticket-close-dialog')).toHaveCount(0);
    await page.screenshot({ path: 'test-results/root-cause-admin-closed.png', fullPage: true });

    expect(appErrors).toEqual([]);
});

test('the system suggestion is shown but not selected; "Pakai saran" selects it and the close is saved', async ({ page }) => {
    const appErrors = collectAppErrors(page);

    await page.goto(`/tickets/${SUGGEST_TICKET}`, { waitUntil: 'domcontentloaded' });
    await expect(page.getByText('Internet mati (fixture E2E penyebab SUGGEST)').first()).toBeVisible({ timeout: 45_000 });

    await page.getByRole('button', { name: 'Resolved', exact: true }).click();
    const suggestion = page.getByTestId('root-cause-suggestion');
    await expect(suggestion).toContainText('Saran sistem: WiFi modem mati (sedang). Pilih kalau sesuai.');
    // Never pre-selected.
    await expect(page.locator('[data-testid^="root-cause-option-"][aria-pressed="true"]')).toHaveCount(0);
    await expect(page.getByTestId('ticket-close-confirm')).toBeDisabled();
    await page.screenshot({ path: 'test-results/root-cause-suggestion-dialog.png', fullPage: true });

    await page.getByTestId('root-cause-use-suggestion').click();
    await expect(page.getByTestId('root-cause-option-wlan_down')).toHaveAttribute('aria-pressed', 'true');
    await expect(page.getByTestId('ticket-close-confirm')).toBeEnabled();
    await page.screenshot({ path: 'test-results/root-cause-suggestion-used.png', fullPage: true });

    await page.getByTestId('ticket-close-confirm').click();

    await expect(page.getByTestId('ticket-root-cause')).toContainText('WiFi modem mati', { timeout: 30_000 });
    await expect(page.getByText('Penyebab: WiFi modem mati').first()).toBeVisible();
    await page.screenshot({ path: 'test-results/root-cause-suggestion-closed.png', fullPage: true });

    expect(appErrors).toEqual([]);
});

test('"Bukan gangguan" closes an incident that was no fault', async ({ page }) => {
    const appErrors = collectAppErrors(page);

    await page.goto(`/tickets/${NOT_FAULT_TICKET}`, { waitUntil: 'domcontentloaded' });
    await expect(page.getByText('Internet mati (fixture E2E penyebab NOTFAULT)').first()).toBeVisible({ timeout: 45_000 });

    await page.getByRole('button', { name: 'Resolved', exact: true }).click();
    await expect(page.getByTestId('ticket-close-dialog')).toBeVisible();
    await pick(page, 'not_fault');
    await page.screenshot({ path: 'test-results/root-cause-notfault-selected.png', fullPage: true });
    await page.getByTestId('ticket-close-confirm').click();

    await expect(page.getByTestId('ticket-root-cause')).toContainText('Bukan gangguan', { timeout: 30_000 });
    await page.screenshot({ path: 'test-results/root-cause-notfault-closed.png', fullPage: true });

    expect(appErrors).toEqual([]);
});

test('technician on a 375 px phone: "Lainnya" needs a note, then the close is saved', async ({ page }) => {
    const appErrors = collectAppErrors(page);
    await page.setViewportSize({ width: 375, height: 812 });

    await page.goto(`/tickets/${TECH_TICKET}`, { waitUntil: 'domcontentloaded' });
    await expect(page.getByTestId('ticket-technician-form')).toBeVisible({ timeout: 45_000 });

    await page.getByLabel('Kendala').fill('Pelanggan tidak bisa internet');
    await page.getByLabel('Tindakan').fill('Dicek di lokasi');

    // The picker only appears once a closing status is chosen.
    await expect(page.getByTestId('root-cause-field')).toHaveCount(0);
    await page.getByTestId('ticket-status-select').click();
    await page.getByRole('option', { name: 'Resolved', exact: true }).click();
    await expect(page.getByTestId('root-cause-field')).toBeVisible();
    await page.getByTestId('root-cause-field').scrollIntoViewIfNeeded();
    await expectTapTargets(page);
    await expectNoHorizontalScroll(page);
    await page.screenshot({ path: 'test-results/root-cause-mobile-technician-picker.png', fullPage: true });

    // The server rejects a close without a cause.
    await page.getByTestId('ticket-save-update').click();
    await expect(page.getByTestId('root-cause-error')).toContainText('Pilih penyebab sebenarnya', { timeout: 30_000 });
    await page.screenshot({ path: 'test-results/root-cause-mobile-technician-error.png', fullPage: true });

    // "Lainnya" shows the note box; saving without a note is refused.
    await expect(page.getByTestId('root-cause-note')).toHaveCount(0);
    await pick(page, 'other');
    await expect(page.getByTestId('root-cause-note')).toBeVisible();
    await page.screenshot({ path: 'test-results/root-cause-mobile-technician-other.png', fullPage: true });
    await page.getByTestId('ticket-save-update').click();
    await expect(page.getByTestId('root-cause-note-error')).toContainText('Tulis singkat penyebabnya', { timeout: 30_000 });
    await page.screenshot({ path: 'test-results/root-cause-mobile-technician-note-error.png', fullPage: true });

    await page.getByTestId('root-cause-note').fill('Kabel LAN dalam rumah putus');
    await expectNoHorizontalScroll(page);
    await page.getByTestId('ticket-save-update').click();

    await expect(page.getByTestId('ticket-root-cause')).toContainText('Lainnya', { timeout: 30_000 });
    await expect(page.getByTestId('ticket-root-cause-note')).toContainText('Kabel LAN dalam rumah putus');
    await expect(page.getByText('Resolved', { exact: true }).first()).toBeVisible();
    await expectNoHorizontalScroll(page);
    await page.screenshot({ path: 'test-results/root-cause-mobile-technician-saved.png', fullPage: true });

    expect(appErrors).toEqual([]);
});

test('ticket edit form changes "Penyebab sebenarnya" of an open incident and the ticket page shows it', async ({ page }) => {
    const appErrors = collectAppErrors(page);

    await page.goto(`/tickets/${EDIT_TICKET}/edit`, { waitUntil: 'domcontentloaded' });
    const field = page.getByTestId('root-cause-field');
    await expect(field).toBeVisible({ timeout: 45_000 });
    // Not a close: the cause is optional here and the stored value is selected.
    await expect(page.getByTestId('root-cause-option-drop_los')).toHaveAttribute('aria-pressed', 'true');
    await expect(field.getByText('Boleh diganti kalau penyebab berubah.')).toBeVisible();
    await page.screenshot({ path: 'test-results/root-cause-edit-before.png', fullPage: true });

    await openMore(page);
    await pick(page, 'odp_cut');
    await expect(page.getByTestId('root-cause-option-drop_los')).toHaveAttribute('aria-pressed', 'false');
    await page.screenshot({ path: 'test-results/root-cause-edit-changed.png', fullPage: true });

    await page.getByRole('button', { name: 'Simpan Perubahan' }).click();

    await expect(page.getByTestId('ticket-root-cause')).toContainText('Kabel ODP putus', { timeout: 30_000 });
    await expect(page.getByText('Penyebab diubah: Kabel ODP putus').first()).toBeVisible();
    await page.screenshot({ path: 'test-results/root-cause-edit-saved.png', fullPage: true });

    expect(appErrors).toEqual([]);
});

test('changing the type away from Gangguan while closing still needs a cause', async ({ page }) => {
    const appErrors = collectAppErrors(page);

    await page.goto(`/tickets/${RETYPE_TICKET}/edit`, { waitUntil: 'domcontentloaded' });
    await expect(page.getByTestId('root-cause-field')).toBeVisible({ timeout: 45_000 });

    await page.getByText('Tipe', { exact: true }).locator('..').getByRole('combobox').click();
    await page.getByRole('option', { name: 'Lainnya', exact: true }).click();
    await page.getByText('Status', { exact: true }).locator('..').getByRole('combobox').click();
    await page.getByRole('option', { name: 'Resolved', exact: true }).click();

    // The picker stays and is required.
    await expect(page.getByTestId('root-cause-field')).toBeVisible();
    await expect(page.getByTestId('root-cause-field').getByText('Wajib diisi.')).toBeVisible();
    await page.getByRole('button', { name: 'Simpan Perubahan' }).click();
    await expect(page.getByTestId('root-cause-error')).toContainText('Pilih penyebab sebenarnya', { timeout: 30_000 });
    await page.screenshot({ path: 'test-results/root-cause-retype-error.png', fullPage: true });

    await pick(page, 'cpe_dead');
    await page.getByRole('button', { name: 'Simpan Perubahan' }).click();

    await expect(page.getByTestId('ticket-root-cause')).toContainText('Modem rusak', { timeout: 30_000 });
    await expect(page.getByText('Resolved', { exact: true }).first()).toBeVisible();
    await page.screenshot({ path: 'test-results/root-cause-retype-saved.png', fullPage: true });

    expect(appErrors).toEqual([]);
});

test('a ticket that is not an incident closes without a cause picker', async ({ page }) => {
    const appErrors = collectAppErrors(page);

    await page.goto(`/tickets/${INSTALL_TICKET}`, { waitUntil: 'domcontentloaded' });
    await expect(page.getByText('Internet mati (fixture E2E penyebab INSTALL)').first()).toBeVisible({ timeout: 45_000 });

    await page.getByRole('button', { name: 'Resolved', exact: true }).click();

    await expect(page.getByTestId('ticket-close-dialog')).toHaveCount(0);
    await expect(page.getByTestId('root-cause-field')).toHaveCount(0);
    await expect(page.getByText('Resolved', { exact: true }).first()).toBeVisible({ timeout: 30_000 });
    await expect(page.getByTestId('ticket-root-cause')).toHaveCount(0);
    await page.screenshot({ path: 'test-results/root-cause-noincident-closed.png', fullPage: true });

    expect(appErrors).toEqual([]);
});
