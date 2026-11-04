import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

// Fixture from database/seeders/RouterUnlinkedSecretsE2ESeeder.php: router "Router Uji Secret" with 30 unlinked
// secrets USERUJI_RT01_RW01_ODP.01 … .30 (row 02 is disabled) and one package that matches their profile.

const ROUTER_NAME = 'Router Uji Secret';

async function openRouter(page: Page) {
    await page.goto('/routers', { waitUntil: 'domcontentloaded' });
    await page.getByRole('row', { name: new RegExp(ROUTER_NAME) }).first().click();
    await expect(page).toHaveURL(/\/routers\/\d+/, { timeout: 45_000 });
}

test('Ringkasan "Lihat daftar" opens the list; page 2, search and copy work', async ({ page, context }) => {
    await context.grantPermissions(['clipboard-read', 'clipboard-write']);
    await openRouter(page);

    await expect(page.getByText(/30 secret belum terhubung ke eBilling/)).toBeVisible({ timeout: 45_000 });
    await page.screenshot({ path: 'test-results/unlinked-01-ringkasan.png', fullPage: true });

    await page.getByRole('button', { name: 'Lihat daftar' }).click();
    const section = page.locator('#unlinked-secrets');
    await expect(section.getByText('Secret belum terhubung (30)')).toBeVisible({ timeout: 45_000 });
    await expect(section.getByText('USERUJI_RT01_RW01_ODP.01', { exact: true })).toBeVisible();
    await expect(section.getByText('USERUJI_RT01_RW01_ODP.26', { exact: true })).toHaveCount(0);
    await expect(section.getByText('Menampilkan 1–25 dari 30 data')).toBeVisible();
    await section.scrollIntoViewIfNeeded();
    await page.screenshot({ path: 'test-results/unlinked-02-page1.png', fullPage: true });

    // Page 2 must actually reach the backend (25 per page, no per-page override).
    const next = page.waitForResponse((r) => /\/unlinked-secrets\?.*page=2/.test(r.url()) && r.ok());
    await section.getByRole('button', { name: 'Halaman berikutnya' }).click();
    await next;
    await expect(section.getByText('USERUJI_RT01_RW01_ODP.26', { exact: true })).toBeVisible();
    await expect(section.getByText('USERUJI_RT01_RW01_ODP.30', { exact: true })).toBeVisible();
    await expect(section.getByText('USERUJI_RT01_RW01_ODP.01', { exact: true })).toHaveCount(0);
    await page.screenshot({ path: 'test-results/unlinked-03-page2.png', fullPage: true });

    // Search: "_" is a plain character, back on page 1 with one row.
    const searched = page.waitForResponse((r) => /\/unlinked-secrets\?.*search=ODP\.17/.test(r.url()) && r.ok());
    await section.getByRole('searchbox', { name: 'Cari username secret' }).fill('ODP.17');
    await searched;
    await expect(section.getByText('dari 1 data')).toBeVisible();
    await expect(section.getByText('USERUJI_RT01_RW01_ODP.17', { exact: true })).toBeVisible();
    await page.screenshot({ path: 'test-results/unlinked-04-search.png', fullPage: true });

    await section.getByRole('button', { name: 'Salin username USERUJI_RT01_RW01_ODP.17' }).click();
    await expect(page.getByText('Username disalin')).toBeVisible();
});

test('Tambah pelanggan: Aktif prefill, locked fields, save links the secret', async ({ page }) => {
    // A CI retry runs after the first attempt may already have linked a row, so every attempt uses a fresh row.
    const username = `USERUJI_RT01_RW01_ODP.0${3 + test.info().retry}`;
    await openRouter(page);
    await page.getByRole('button', { name: 'Lihat daftar' }).click();
    const section = page.locator('#unlinked-secrets');
    await expect(section.getByText(username, { exact: true })).toBeVisible({ timeout: 45_000 });
    const before = Number((await section.getByText(/Secret belum terhubung \(\d+\)/).innerText()).match(/\((\d+)\)/)![1]);

    await section.getByRole('row', { name: new RegExp(username.replace('.', '\\.')) }).getByRole('link', { name: /Tambah pelanggan/ }).click();
    await expect(page).toHaveURL(/\/customers\/create\?staged=\d+/);

    await expect(page.getByText(`Data dari secret di router ${ROUTER_NAME}. Password dan profil di MikroTik tidak diubah.`)).toBeVisible();
    await expect(page.locator('#pppoe_user')).toHaveValue(username);
    await expect(page.locator('#pppoe_user')).toHaveAttribute('readonly', '');
    await expect(page.getByRole('combobox').filter({ hasText: ROUTER_NAME })).toBeDisabled();
    await expect(page.getByRole('combobox').filter({ hasText: 'Paket Uji Secret 10M' })).toBeVisible();
    await expect(page.getByRole('combobox').filter({ hasText: /^Aktif$/ })).toBeVisible();
    await expect(page.getByText('Aktif = pelanggan mulai ditagih.')).toBeVisible();
    await page.screenshot({ path: 'test-results/unlinked-05-prefill-aktif.png', fullPage: true });

    await page.locator('#name').fill('BUDI CONTOH');
    await page.locator('#address').fill('Alamat uji 1');
    await page.getByRole('button', { name: 'Create Customer' }).click();

    await expect(page).toHaveURL(/\/routers\/\d+\?.*section=unlinked/, { timeout: 45_000 });
    await expect(page.getByText(`Pelanggan dibuat dan terhubung ke secret ${username}.`)).toBeVisible();
    const after = page.locator('#unlinked-secrets');
    await expect(after.getByText(`Secret belum terhubung (${before - 1})`)).toBeVisible({ timeout: 45_000 });
    await expect(after.getByText(username, { exact: true })).toHaveCount(0);
    await page.screenshot({ path: 'test-results/unlinked-06-after-save.png', fullPage: true });
});

test('Tambah pelanggan from a disabled secret starts on Menunggu Pasang', async ({ page }) => {
    await openRouter(page);
    await page.getByRole('button', { name: 'Lihat daftar' }).click();
    const section = page.locator('#unlinked-secrets');
    await expect(section.getByText('USERUJI_RT01_RW01_ODP.02', { exact: true })).toBeVisible({ timeout: 45_000 });

    await section.getByRole('row', { name: /USERUJI_RT01_RW01_ODP\.02/ }).getByRole('link', { name: /Tambah pelanggan/ }).click();
    await expect(page).toHaveURL(/\/customers\/create\?staged=\d+/);

    await expect(page.getByRole('combobox').filter({ hasText: 'Menunggu Pasang' })).toBeVisible();
    await expect(page.getByRole('combobox').filter({ hasText: /^Aktif$/ })).toHaveCount(0);
    await page.screenshot({ path: 'test-results/unlinked-07-prefill-pending.png', fullPage: true });
});

// ---- Hapus secret dialog states. The device is never touched: the list only gets can_delete=true and
// the delete-check / DELETE answers are stubbed, exactly the JSON the backend sends (tests/Feature/RouterUnlinkedSecretDeleteTest).

async function openListWithDelete(page: Page) {
    await page.route(/\/api\/routers\/\d+\/unlinked-secrets\?/, async (route) => {
        const response = await route.fetch();
        const json = await response.json();
        json.meta.can_delete = true;
        await route.fulfill({ response, json });
    });
    await openRouter(page);
    await page.getByRole('button', { name: 'Lihat daftar' }).click();
    return page.locator('#unlinked-secrets');
}

type Lookalike = { id: number; username: string; router: string; text: string };

function secretInfo(username: string, online: boolean, lookalikes: Lookalike[] = []) {
    return {
        ok: true,
        code: 'ready',
        message: '',
        secret: {
            username,
            router: ROUTER_NAME,
            profile: 'uji-10m',
            disabled: false,
            online,
            uptime: online ? '2h14m' : null,
            address: online ? '198.18.7.7' : null,
            last_logged_out: '2026-11-01 09:00:00',
            lookalikes,
        },
    };
}

test('Hapus secret - offline secret needs only the typed username', async ({ page }) => {
    const username = 'USERUJI_RT01_RW01_ODP.10';
    await page.route('**/unlinked-secrets/*/delete-check', (route) => route.fulfill({ status: 200, json: secretInfo(username, false) }));
    let sent: Record<string, unknown> | null = null;
    await page.route(/\/unlinked-secrets\/\d+$/, async (route) => {
        if (route.request().method() !== 'DELETE') return route.fallback();
        sent = route.request().postDataJSON();
        return route.fulfill({ status: 200, json: { ok: true, status: 200, code: 'deleted', message: `Secret ${username} dihapus dari ${ROUTER_NAME}. Cadangan disimpan.` } });
    });
    const section = await openListWithDelete(page);
    await expect(section.getByText(username, { exact: true })).toBeVisible({ timeout: 45_000 });

    await section.getByRole('row', { name: /USERUJI_RT01_RW01_ODP\.10/ }).getByRole('button', { name: 'Hapus secret' }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByText('Online')).toBeVisible();
    await expect(dialog.getByText('Tidak', { exact: true })).toBeVisible();
    await expect(dialog.getByText('Koneksi yang sedang aktif akan diputus')).toHaveCount(0);

    const confirm = dialog.getByRole('button', { name: 'Hapus secret', exact: true });
    await expect(confirm).toBeDisabled();
    await dialog.getByLabel('Ketik username secret untuk melanjutkan.').fill(username);
    await expect(confirm).toBeEnabled();
    await page.screenshot({ path: 'test-results/unlinked-08-delete-offline.png', fullPage: true });

    await confirm.click();
    await expect(page.getByText(`Secret ${username} dihapus dari ${ROUTER_NAME}. Cadangan disimpan.`)).toBeVisible();
    await expect(dialog).toHaveCount(0);
    expect(sent).toMatchObject({ confirm_username: username, disconnect_online: false, expected_profile: 'uji-10m', expected_disabled: false });
    await page.screenshot({ path: 'test-results/unlinked-11-delete-success.png', fullPage: true });
});

test('Hapus secret - online secret also needs the tick', async ({ page }) => {
    const username = 'USERUJI_RT01_RW01_ODP.11';
    await page.route('**/unlinked-secrets/*/delete-check', (route) => route.fulfill({ status: 200, json: secretInfo(username, true) }));
    let sent: Record<string, unknown> | null = null;
    await page.route(/\/unlinked-secrets\/\d+$/, async (route) => {
        if (route.request().method() !== 'DELETE') return route.fallback();
        sent = route.request().postDataJSON();
        return route.fulfill({ status: 200, json: { ok: true, status: 200, code: 'deleted', message: 'Secret dihapus.' } });
    });
    const section = await openListWithDelete(page);
    await expect(section.getByText(username, { exact: true })).toBeVisible({ timeout: 45_000 });

    await section.getByRole('row', { name: /USERUJI_RT01_RW01_ODP\.11/ }).getByRole('button', { name: 'Hapus secret' }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByText('Ya (2h14m), 198.18.7.7')).toBeVisible();
    const confirm = dialog.getByRole('button', { name: 'Hapus secret', exact: true });
    await dialog.getByLabel('Ketik username secret untuk melanjutkan.').fill(username);
    // Username typed but not ticked: still blocked.
    await expect(confirm).toBeDisabled();
    await page.screenshot({ path: 'test-results/unlinked-09-delete-online-needs-tick.png', fullPage: true });

    await dialog.getByLabel('Koneksi yang sedang aktif akan diputus').check();
    await expect(confirm).toBeEnabled();
    await confirm.click();
    await expect(dialog).toHaveCount(0);
    expect(sent).toMatchObject({ confirm_username: username, disconnect_online: true });
});

test('Hapus secret - a lookalike customer is a warning and needs the extra tick', async ({ page }) => {
    const username = 'USERUJI_RT01_RW01_ODP.14';
    const lookalikes = [
        { id: 68, username: 'USERUJI_RT01_RW01_ODP.03', router: 'Router Uji B', text: 'Mirip pelanggan #68 (Router Uji B)' },
        { id: 71, username: 'USERUJI_RT01_RW01_ODP.40', router: ROUTER_NAME, text: `Mirip pelanggan #71 (${ROUTER_NAME})` },
    ];
    await page.route('**/unlinked-secrets/*/delete-check', (route) => route.fulfill({ status: 200, json: secretInfo(username, false, lookalikes) }));
    let sent: Record<string, unknown> | null = null;
    await page.route(/\/unlinked-secrets\/\d+$/, async (route) => {
        if (route.request().method() !== 'DELETE') return route.fallback();
        sent = route.request().postDataJSON();
        return route.fulfill({ status: 200, json: { ok: true, status: 200, code: 'deleted', message: 'Secret dihapus.' } });
    });
    const section = await openListWithDelete(page);
    await expect(section.getByText(username, { exact: true })).toBeVisible({ timeout: 45_000 });

    await section.getByRole('row', { name: /USERUJI_RT01_RW01_ODP\.14/ }).getByRole('button', { name: 'Hapus secret' }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByText('Mirip pelanggan #68 (Router Uji B)')).toBeVisible();
    await expect(dialog.getByText(`Mirip pelanggan #71 (${ROUTER_NAME})`)).toBeVisible();
    const confirm = dialog.getByRole('button', { name: 'Hapus secret', exact: true });
    await dialog.getByLabel('Ketik username secret untuk melanjutkan.').fill(username);
    // Username typed but the lookalike tick is missing: still blocked.
    await expect(confirm).toBeDisabled();
    await page.screenshot({ path: 'test-results/unlinked-12-delete-lookalike.png', fullPage: true });

    await dialog.getByLabel('Saya sudah cek, ini bukan pelanggan itu').check();
    await expect(confirm).toBeEnabled();
    await confirm.click();
    await expect(dialog).toHaveCount(0);
    expect(sent).toMatchObject({ confirm_username: username, lookalike_ack: true, disconnect_online: false });
});

test('Hapus secret - a refused DELETE (row changed) shows the message and no confirm button', async ({ page }) => {
    const username = 'USERUJI_RT01_RW01_ODP.15';
    const message = 'Username ini dipakai pelanggan #7 (Router Uji B). Tidak bisa dihapus dari sini.';
    await page.route('**/unlinked-secrets/*/delete-check', (route) => route.fulfill({ status: 200, json: secretInfo(username, false) }));
    await page.route(/\/unlinked-secrets\/\d+$/, async (route) => {
        if (route.request().method() !== 'DELETE') return route.fallback();
        return route.fulfill({ status: 409, json: { ok: false, status: 409, code: 'customer_exists', message } });
    });
    const section = await openListWithDelete(page);
    await expect(section.getByText(username, { exact: true })).toBeVisible({ timeout: 45_000 });

    await section.getByRole('row', { name: /USERUJI_RT01_RW01_ODP\.15/ }).getByRole('button', { name: 'Hapus secret' }).click();
    const dialog = page.getByRole('dialog');
    await dialog.getByLabel('Ketik username secret untuk melanjutkan.').fill(username);
    await dialog.getByRole('button', { name: 'Hapus secret', exact: true }).click();

    await expect(dialog.getByText(message)).toBeVisible();
    await expect(dialog.getByRole('button', { name: 'Hapus secret', exact: true })).toHaveCount(0);
    await expect(dialog.getByRole('button', { name: 'Tutup' })).toBeVisible();
    await page.screenshot({ path: 'test-results/unlinked-13-delete-refused-on-submit.png', fullPage: true });
});

test('Hapus secret - a refusal shows plain text and no confirm button', async ({ page }) => {
    const message = 'Username ini dipakai pelanggan #7 (Router Uji B). Tidak bisa dihapus dari sini.';
    await page.route('**/unlinked-secrets/*/delete-check', (route) =>
        route.fulfill({ status: 409, json: { ok: false, status: 409, code: 'customer_exists', message } }),
    );
    const section = await openListWithDelete(page);
    await expect(section.getByText('USERUJI_RT01_RW01_ODP.12', { exact: true })).toBeVisible({ timeout: 45_000 });

    await section.getByRole('row', { name: /USERUJI_RT01_RW01_ODP\.12/ }).getByRole('button', { name: 'Hapus secret' }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByText(message)).toBeVisible();
    await expect(dialog.getByRole('button', { name: 'Hapus secret', exact: true })).toHaveCount(0);
    await expect(dialog.getByLabel('Ketik username secret untuk melanjutkan.')).toHaveCount(0);
    await expect(dialog.getByRole('button', { name: 'Tutup' })).toBeVisible();
    await page.screenshot({ path: 'test-results/unlinked-10-delete-refused.png', fullPage: true });
});

test('Hapus secret - without can_delete the button is not on the page', async ({ page }) => {
    await openRouter(page);
    await page.getByRole('button', { name: 'Lihat daftar' }).click();
    const section = page.locator('#unlinked-secrets');
    await expect(section.getByText('USERUJI_RT01_RW01_ODP.13', { exact: true })).toBeVisible({ timeout: 45_000 });
    await expect(section.getByRole('button', { name: 'Hapus secret' })).toHaveCount(0);
});
