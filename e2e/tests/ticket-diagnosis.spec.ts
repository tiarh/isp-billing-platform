import { expect, test } from '@playwright/test';
import type { Browser, Page } from '@playwright/test';

// Fixed ids / names from database/seeders/TicketDiagnosisE2ESeeder.php. The probes use the FAKE driver
// (DIAGNOSIS_PROBE_DRIVER=fake): router answers, the OLT is "dilewati: ... pasang baru", the ONU reports 5 GHz off.
// The seeder also turns ON the two learn-and-measure settings (both are off in production): the calibrated words
// ("Pasti" for the one class that is pasti, "Kemungkinan" for the rest, "Belum tahu" for an unknown answer) and the
// "Saran dari data" chip of the close form.
const TICKET = 9200001;
const SIGNATURE_TICKET = 9200002; // customer 8: RX -28 dBm for two days, -20.7 now => the chip
const NOCHIP_TICKET = 9200003; // customer 9: RX never moved => no chip

// The seeder puts events a few hours before "now". DiagnosisEvidence::clock() prints "HH:MM" for today (WIB) and
// "dd/mm HH:MM" for an earlier day, so between 00:00 and ~07:00 WIB the date prefix appears. Accept both.
const CLOCK = String.raw`(\d{1,2}\/\d{1,2} )?\d{2}:\d{2}`;

function collectAppErrors(page: Page): string[] {
    const appErrors: string[] = [];
    page.on('pageerror', (error) => appErrors.push(error.message));
    page.on('console', (message) => {
        // a deliberate 429 ("Tunggu 2 menit") is logged by the browser as a failed resource
        if (message.type() === 'error' && !/Failed to load resource/.test(message.text())) appErrors.push(message.text());
    });

    return appErrors;
}

async function loginAs(browser: Browser, email: string): Promise<{ close: () => Promise<void>; page: Page }> {
    const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    const page = await context.newPage();
    const password = process.env.E2E_PASSWORD;
    if (!password) throw new Error('E2E_PASSWORD is required for the isolated sandbox.');

    await page.goto('/login', { waitUntil: 'domcontentloaded' });
    await page.locator('#email').fill(email);
    await page.locator('#password').fill(password);
    await Promise.all([page.waitForURL(/\/dashboard/, { timeout: 45_000 }), page.getByRole('button', { name: /sign in/i }).click({ noWaitAfter: true })]);

    return { close: () => context.close(), page };
}

test.describe.configure({ mode: 'serial' });

test('ticket detail: the stored diagnosis opens and closes, then "Cek sekarang" runs the three checks', async ({ page }) => {
    const appErrors = collectAppErrors(page);

    await page.goto(`/tickets/${TICKET}`, { waitUntil: 'domcontentloaded' });
    const panel = page.getByTestId('diagnosis-panel');
    await expect(panel).toBeVisible({ timeout: 45_000 });

    // 1. the stored diagnosis: cause, confidence, advice and three evidence lines
    await expect(page.getByTestId('diagnosis-cause')).toHaveText('WiFi mati');
    await expect(page.getByTestId('diagnosis-confidence')).toHaveText('Keyakinan: Pasti');
    await expect(page.getByTestId('diagnosis-action')).toHaveText('Bantu nyalakan WiFi lewat telepon');
    await expect(page.getByTestId('diagnosis-evidence').locator('li')).toHaveCount(3);
    await expect(page.getByTestId('diagnosis-evidence')).toContainText('WiFi 5 GHz mati, dicek 09:15.');
    await expect(page.getByTestId('diagnosis-check-now')).toBeVisible();
    await panel.screenshot({ path: 'test-results/diagnosis-panel-expanded.png' });

    // collapsed: one summary line
    await page.getByTestId('diagnosis-toggle').click();
    await expect(page.getByTestId('diagnosis-summary')).toContainText('WiFi mati');
    await expect(page.getByTestId('diagnosis-evidence')).toHaveCount(0);
    await panel.screenshot({ path: 'test-results/diagnosis-panel-collapsed.png' });
    await page.getByTestId('diagnosis-toggle').click();
    await expect(page.getByTestId('diagnosis-evidence')).toBeVisible();

    // 2. "Cek sekarang": the three rows run, then finish / skipped
    await page.getByTestId('diagnosis-check-now').click();
    await expect(page.getByTestId('diagnosis-probe-router')).toContainText('mengecek');
    await expect(page.getByTestId('diagnosis-probe-olt')).toContainText('mengecek');
    await panel.screenshot({ path: 'test-results/diagnosis-check-running.png' });

    await expect(page.getByTestId('diagnosis-probe-router')).toContainText('selesai', { timeout: 30_000 });
    await expect(page.getByTestId('diagnosis-probe-olt')).toContainText('dilewati: OLT sedang dipakai pasang baru');
    await expect(page.getByTestId('diagnosis-probe-cpe')).toContainText('selesai');
    // the result replaces the stored answer: the time line now says "baru saja", the sandbox customer has no known CPE model (band not verified) => sedang
    await expect(page.getByTestId('diagnosis-time')).toContainText('baru saja', { timeout: 30_000 });
    await expect(page.getByTestId('diagnosis-confidence')).toHaveText('Keyakinan: Kemungkinan');
    await expect(page.getByTestId('diagnosis-cause')).toHaveText('WiFi mati');
    await expect(page.getByTestId('diagnosis-check-now')).toBeEnabled();
    await panel.screenshot({ path: 'test-results/diagnosis-check-result-skipped.png' });

    // 3. an immediate second click is refused with the plain message
    await page.getByTestId('diagnosis-check-now').click();
    await expect(page.getByTestId('diagnosis-message')).toHaveText('Tunggu 2 menit sebelum cek lagi.');
    await panel.screenshot({ path: 'test-results/diagnosis-rate-limited.png' });

    expect(appErrors).toEqual([]);
});

test('ticket form: the panel follows customer + type, "Cek sekarang" works and the ticket is saved', async ({ page }) => {
    const appErrors = collectAppErrors(page);

    await page.goto('/tickets/create', { waitUntil: 'domcontentloaded' });
    await expect(page.getByText('Data Pelanggan')).toBeVisible({ timeout: 45_000 });
    // no customer yet: no panel
    await expect(page.getByTestId('diagnosis-panel')).toHaveCount(0);

    await page.getByRole('combobox').filter({ hasText: 'Ketik nama / kode' }).click();
    await page.getByPlaceholder('Ketik nama, kode, PPPoE, area, alamat, atau no HP...').fill('Pelanggan Diagnosa Dua');
    await page.getByRole('option', { name: /Pelanggan Diagnosa Dua/ }).click();

    // Gangguan (the default type) + a customer: the panel appears with a DB-only diagnosis
    const panel = page.getByTestId('diagnosis-panel');
    await expect(panel).toBeVisible({ timeout: 30_000 });
    await expect(page.getByTestId('diagnosis-cause')).not.toBeEmpty();
    await expect(page.getByTestId('diagnosis-check-now')).toBeVisible();
    await panel.screenshot({ path: 'test-results/diagnosis-create-panel.png' });

    // another type: the panel is hidden, back to Gangguan: it is back
    await page.getByRole('combobox').filter({ hasText: 'Gangguan' }).click();
    await page.getByRole('option', { name: 'Pasang Baru' }).click();
    await expect(panel).toHaveCount(0);
    await page.getByRole('combobox').filter({ hasText: 'Pasang Baru' }).click();
    await page.getByRole('option', { name: 'Gangguan', exact: true }).click();
    await expect(panel).toBeVisible();

    await page.getByTestId('diagnosis-check-now').click();
    await expect(page.getByTestId('diagnosis-probe-olt')).toContainText('dilewati', { timeout: 30_000 });
    await expect(page.getByTestId('diagnosis-time')).toContainText('baru saja', { timeout: 30_000 });

    await page.getByPlaceholder('Deskripsi gangguan atau keluhan pelanggan...').fill('WiFi tidak muncul (E2E form diagnosa)');
    await page.getByRole('button', { name: 'Simpan Tiket' }).click();

    // the saved ticket shows the panel too
    await expect(page).toHaveURL(/\/tickets\/\d+$/, { timeout: 45_000 });
    await expect(page.getByTestId('diagnosis-panel')).toBeVisible({ timeout: 30_000 });
    await expect(page.getByTestId('diagnosis-cause')).not.toBeEmpty();
    await page.screenshot({ path: 'test-results/diagnosis-create-saved.png', fullPage: true });

    expect(appErrors).toEqual([]);
});

async function pickCustomer(page: Page, name: string): Promise<void> {
    await page.goto('/tickets/create', { waitUntil: 'domcontentloaded' });
    await expect(page.getByText('Data Pelanggan')).toBeVisible({ timeout: 45_000 });
    await page.getByRole('combobox').filter({ hasText: 'Ketik nama / kode' }).click();
    await page.getByPlaceholder('Ketik nama, kode, PPPoE, area, alamat, atau no HP...').fill(name);
    await page.getByRole('option', { name: new RegExp(name) }).click();
}

test('ticket form: an online ONU that dropped twice today is "Kabel drop putus" with the history line on top', async ({ page }) => {
    const appErrors = collectAppErrors(page);

    await pickCustomer(page, 'Pelanggan Diagnosa Tiga');

    const panel = page.getByTestId('diagnosis-panel');
    await expect(panel).toBeVisible({ timeout: 30_000 });
    await expect(page.getByTestId('diagnosis-cause')).toHaveText('Kabel ke rumah putus');
    await expect(page.getByTestId('diagnosis-confidence')).toHaveText('Keyakinan: Kemungkinan');
    await expect(page.getByTestId('diagnosis-action')).toHaveText('Kirim teknisi FO');
    const lines = page.getByTestId('diagnosis-evidence').locator('li');
    await expect(lines.first()).toContainText(new RegExp(`^ONU putus karena kabel 2 kali dalam 24 jam, terakhir ${CLOCK}\\.$`));
    await expect(page.getByTestId('diagnosis-evidence')).toContainText(new RegExp(`Redaman -25,2 dBm \\(normal\\), data ${CLOCK}\\.`));
    await panel.screenshot({ path: 'test-results/diagnosis-history-intermittent.png' });

    expect(appErrors).toEqual([]);
});

test('ticket form: a normal line says that nothing was recorded in 24 hours, "lihat semua" opens the rest (desktop + phone)', async ({ page }) => {
    const appErrors = collectAppErrors(page);

    await pickCustomer(page, 'Pelanggan Diagnosa Empat');

    const panel = page.getByTestId('diagnosis-panel');
    await expect(panel).toBeVisible({ timeout: 30_000 });
    await expect(page.getByTestId('diagnosis-cause')).toHaveText('Belum tahu');
    await expect(page.getByTestId('diagnosis-confidence')).toHaveText('Keyakinan: Belum tahu');
    await expect(page.getByTestId('diagnosis-action')).toHaveText('Belum bisa ditebak');
    const evidence = page.getByTestId('diagnosis-evidence');
    await expect(evidence).toContainText('Jaringan normal saat dicek.');
    await expect(evidence).toContainText('24 jam terakhir tidak ada putus tercatat.');
    await expect(evidence).toContainText(new RegExp(`Redaman -22,4 dBm \\(normal\\), data ${CLOCK}\\.`));
    await expect(evidence.locator('li')).toHaveCount(5);
    await panel.screenshot({ path: 'test-results/diagnosis-history-normal.png' });

    await page.getByTestId('diagnosis-evidence-more').click();
    await expect(evidence.locator('li')).toHaveCount(6);
    await panel.screenshot({ path: 'test-results/diagnosis-history-normal-expanded.png' });

    await page.setViewportSize({ width: 390, height: 844 });
    const box = await panel.boundingBox();
    expect(box).not.toBeNull();
    expect(box!.x + box!.width).toBeLessThanOrEqual(391); // the panel fits the phone width
    await panel.scrollIntoViewIfNeeded();
    await panel.screenshot({ path: 'test-results/diagnosis-history-normal-mobile.png' });

    expect(appErrors).toEqual([]);
});

async function shootPhone(page: Page, name: string): Promise<void> {
    await page.setViewportSize({ width: 390, height: 844 });
    const panel = page.getByTestId('diagnosis-panel');
    await expect(panel).toBeVisible();
    const box = await panel.boundingBox();
    expect(box).not.toBeNull();
    expect(box!.x + box!.width).toBeLessThanOrEqual(391); // the panel fits the phone width
    await panel.scrollIntoViewIfNeeded();
    await panel.screenshot({ path: `test-results/${name}-mobile.png` });
}

test('RCA-5: an isolated customer with a fresh LOS is "Kabel drop putus" and the isolir note rides along (desktop + phone)', async ({ page }) => {
    const appErrors = collectAppErrors(page);

    await pickCustomer(page, 'Pelanggan Diagnosa Lima');

    const panel = page.getByTestId('diagnosis-panel');
    await expect(panel).toBeVisible({ timeout: 30_000 });
    await expect(page.getByTestId('diagnosis-cause')).toHaveText('Kabel ke rumah putus');
    await expect(page.getByTestId('diagnosis-action')).toHaveText('Kirim teknisi FO');
    const evidence = page.getByTestId('diagnosis-evidence');
    await expect(evidence).toContainText('Pelanggan juga sedang diisolir.');
    await expect(evidence.locator('li').first()).toContainText(/^ONU putus karena kabel \(LOS\)/);
    await expect(panel).toContainText('Bisa juga: Diisolir / cuti');
    await panel.screenshot({ path: 'test-results/rca5-isolated-fresh-los.png' });
    await shootPhone(page, 'rca5-isolated-fresh-los');

    expect(appErrors).toEqual([]);
});

test('RCA-5: an isolated customer without ONU data is "Diisolir / cuti", sedang, with the check line (desktop + phone)', async ({ page }) => {
    const appErrors = collectAppErrors(page);

    await pickCustomer(page, 'Pelanggan Diagnosa Enam');

    const panel = page.getByTestId('diagnosis-panel');
    await expect(panel).toBeVisible({ timeout: 30_000 });
    await expect(page.getByTestId('diagnosis-cause')).toHaveText('Diisolir / cuti');
    await expect(page.getByTestId('diagnosis-confidence')).toHaveText('Keyakinan: Kemungkinan');
    await expect(page.getByTestId('diagnosis-action')).toHaveText('Cek tagihan. Sudah bayar tapi tetap mati: kirim teknisi');
    await expect(page.getByTestId('diagnosis-evidence')).toContainText('Kondisi ONU belum terbaca. Kalau sudah bayar, cek kabel dan listrik ONU.');
    await panel.screenshot({ path: 'test-results/rca5-isolated-no-onu.png' });
    await shootPhone(page, 'rca5-isolated-no-onu');

    expect(appErrors).toEqual([]);
});

test('RCA-5: the leftover of a PON event is "Kabel drop putus" with the recovered line, not a PON cut (desktop + phone)', async ({ page }) => {
    const appErrors = collectAppErrors(page);

    await pickCustomer(page, 'Pelanggan Diagnosa Tujuh');

    const panel = page.getByTestId('diagnosis-panel');
    await expect(panel).toBeVisible({ timeout: 30_000 });
    await expect(page.getByTestId('diagnosis-cause')).toHaveText('Kabel ke rumah putus');
    await expect(page.getByTestId('diagnosis-confidence')).toHaveText('Keyakinan: Kemungkinan');
    await expect(page.getByTestId('diagnosis-evidence')).toContainText(/PON ini sempat putus .+, 3 ONU lain sudah pulih, ONU ini belum\./);
    await panel.screenshot({ path: 'test-results/rca5-pon-leftover.png' });
    await shootPhone(page, 'rca5-pon-leftover');

    expect(appErrors).toEqual([]);
});

// PR-D (the three flags are ON in the sandbox, OFF in production): one new line each, see seedMoreEvidence() in the seeder.

test('PR-D: a member of a session burst gets the burst line, the cause stays (desktop + phone)', async ({ page }) => {
    const appErrors = collectAppErrors(page);

    await pickCustomer(page, 'Pelanggan Bukti Sesi');

    const panel = page.getByTestId('diagnosis-panel');
    await expect(panel).toBeVisible({ timeout: 30_000 });
    await expect(page.getByTestId('diagnosis-evidence')).toContainText(
        new RegExp(`Jam ${CLOCK}, 21 pelanggan putus bersamaan, ONU tetap menyala\\. Kemungkinan gangguan jalur atau router, bukan di rumah\\.`),
    );
    await panel.screenshot({ path: 'test-results/prd-burst-line.png' });
    await shootPhone(page, 'prd-burst-line');

    expect(appErrors).toEqual([]);
});

test('PR-D: an ONU of an area power group gets the "kemungkinan listrik padam" line', async ({ page }) => {
    const appErrors = collectAppErrors(page);

    await pickCustomer(page, 'Pelanggan Bukti Listrik');

    await expect(page.getByTestId('diagnosis-panel')).toBeVisible({ timeout: 30_000 });
    await expect(page.getByTestId('diagnosis-evidence')).toContainText(new RegExp(`Kemungkinan listrik padam di area Area Diagnosa \\(12 ONU mati bersamaan ${CLOCK}\\)\\.`));
    await page.getByTestId('diagnosis-panel').screenshot({ path: 'test-results/prd-area-power-line.png' });

    expect(appErrors).toEqual([]);
});

test('PR-D: fresh traffic shows a line, and traffic after the ONU went down turns the answer into "Tidak tahu"', async ({ page }) => {
    const appErrors = collectAppErrors(page);

    await pickCustomer(page, 'Pelanggan Bukti Trafik');
    await expect(page.getByTestId('diagnosis-panel')).toBeVisible({ timeout: 30_000 });
    await expect(page.getByTestId('diagnosis-evidence')).toContainText(new RegExp(`Internet aktif, pemakaian 3,2 Mbps \\(${CLOCK}\\)\\.`));
    await page.getByTestId('diagnosis-panel').screenshot({ path: 'test-results/prd-traffic-line.png' });

    await pickCustomer(page, 'Pelanggan Bukti Konflik');
    await expect(page.getByTestId('diagnosis-panel')).toBeVisible({ timeout: 30_000 });
    await expect(page.getByTestId('diagnosis-cause')).toHaveText('Belum tahu');
    await expect(page.getByTestId('diagnosis-evidence')).toContainText(new RegExp(`Data bertentangan: internet masih jalan \\(900 kbps, ${CLOCK}\\)\\.`));
    await page.getByTestId('diagnosis-panel').screenshot({ path: 'test-results/prd-traffic-guard.png' });

    expect(appErrors).toEqual([]);
});

test('a technician does not see the panel while the panel roles are admin + noc', async ({ browser }) => {
    const technician = await loginAs(browser, 'e2e-diag-tech@example.test');
    try {
        const appErrors = collectAppErrors(technician.page);
        await technician.page.goto(`/tickets/${TICKET}`, { waitUntil: 'domcontentloaded' });
        await expect(technician.page.getByText('WiFi tidak muncul (fixture E2E diagnosa)').first()).toBeVisible({ timeout: 45_000 });
        await expect(technician.page.getByTestId('diagnosis-panel')).toHaveCount(0);
        await technician.page.screenshot({ path: 'test-results/diagnosis-hidden-for-technician.png', fullPage: true });
        expect(appErrors).toEqual([]);
    } finally {
        await technician.close();
    }
});

test('weekly precision card: classes, excluded closes and page 2 of the confusion list (desktop + phone)', async ({ page }) => {
    const appErrors = collectAppErrors(page);

    await page.goto('/monitoring?tab=deteksi', { waitUntil: 'domcontentloaded' });
    // the card sits below the list, collapsed: open it
    await page.getByTestId('deteksi-precision-details').locator('summary').click({ timeout: 45_000 });
    const card = page.getByTestId('diagnosis-precision');
    await expect(card).toBeVisible({ timeout: 45_000 });
    await expect(card).toContainText('Ketepatan diagnosa');
    await expect(page.getByTestId('diagnosis-precision-summary')).toContainText('benar 28 dari 34');
    await expect(page.getByTestId('diagnosis-precision-excluded')).toContainText('ditutup otomatis 8');
    await expect(page.getByTestId('diagnosis-precision-excluded')).toContainText('Tidak tahu');
    await expect(page.getByTestId('diagnosis-precision-classes').locator('tbody tr')).toHaveCount(4);

    // the confusion list is paged: 8 rows, then 4 on page 2
    await expect(page.getByTestId('diagnosis-precision-confusions').locator('li')).toHaveCount(8);
    await expect(page.getByTestId('diagnosis-precision-page')).toHaveText('Halaman 1 dari 2');
    await card.screenshot({ path: 'test-results/diagnosis-precision-page1.png' });

    await page.getByTestId('diagnosis-precision-next').click();
    await expect(page.getByTestId('diagnosis-precision-page')).toHaveText('Halaman 2 dari 2');
    await expect(page.getByTestId('diagnosis-precision-confusions').locator('li')).toHaveCount(4);
    await expect(page.getByTestId('diagnosis-precision-next')).toBeDisabled();
    await card.screenshot({ path: 'test-results/diagnosis-precision-page2.png' });

    await page.getByTestId('diagnosis-precision-prev').click();
    await expect(page.getByTestId('diagnosis-precision-page')).toHaveText('Halaman 1 dari 2');

    // phone width: no horizontal page scroll
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(card).toBeVisible();
    const box = await card.boundingBox();
    expect(box).not.toBeNull();
    expect(box!.x + box!.width).toBeLessThanOrEqual(391); // the card fits the phone width (the table scrolls inside it)
    await card.screenshot({ path: 'test-results/diagnosis-precision-mobile.png' });

    expect(appErrors).toEqual([]);
});

test('weekly precision card (learn & measure): per version, calibration word per class, data checks and page 2 of the disagreement list (desktop + phone)', async ({ page }) => {
    const appErrors = collectAppErrors(page);

    await page.goto('/monitoring?tab=deteksi', { waitUntil: 'domcontentloaded' });
    await page.getByTestId('deteksi-precision-details').locator('summary').click({ timeout: 45_000 });
    const card = page.getByTestId('diagnosis-precision');
    await expect(card).toBeVisible({ timeout: 45_000 });

    // the closed tickets add up: 60 = 40 rated + 20 not rated (8 + 5 + 0 + 0 + 0 + 7)
    await expect(page.getByTestId('diagnosis-precision-sum')).toContainText('60 tiket = 40 dinilai + 20 tidak dinilai');

    // per rules version, with the interval and the "same kind" number
    const versions = page.getByTestId('diagnosis-precision-versions');
    await expect(versions.locator('tbody tr')).toHaveCount(2);
    await expect(versions.locator('tbody tr').first()).toContainText('2026-10-26.1');
    await expect(versions.locator('tbody tr').first()).toContainText('15 (79%, kisaran 57–91%)');
    await expect(versions.locator('tbody tr').first()).toContainText('17 (89%, kisaran 69–97%)');
    await expect(card).toContainText('Kabel drop, redaman dan ODP dihitung satu jenis');

    // the calibration word of each class
    await expect(page.getByTestId('diagnosis-precision-state-wlan_down')).toHaveText('Pasti');
    await expect(page.getByTestId('diagnosis-precision-state-drop_los')).toHaveText('Kemungkinan');
    await expect(page.getByTestId('diagnosis-precision-state-cpe_dead')).toHaveText('Kemungkinan');
    await expect(page.getByTestId('diagnosis-precision-state-power_off')).toHaveText('Belum tahu');

    // data checks: independent counted, circular only shown
    await expect(page.getByTestId('diagnosis-precision-outcomes')).toContainText('52 dipakai untuk menilai');
    await expect(page.getByTestId('diagnosis-precision-outcomes')).toContainText('61 tidak dinilai (sama dengan tebakan: listrik mati lalu nyala)');
    await expect(page.getByTestId('diagnosis-precision-outcomes')).toContainText('Listrik mati: 0 dipakai menilai · 61 tidak dinilai');

    // "Label beda dengan data": 12 rows = page 1 of 2 (8) and page 2 (4)
    const list = page.getByTestId('diagnosis-precision-disagree');
    await expect(list.locator('li')).toHaveCount(8);
    await expect(page.getByTestId('diagnosis-precision-disagree-page')).toHaveText('Halaman 1 dari 2');
    await expect(list.locator('li').first()).toContainText('TCK-E2E-01: dipilih Listrik mati, data bilang Kabel drop putus (redaman membaik)');
    await page.getByTestId('diagnosis-precision-disagree').scrollIntoViewIfNeeded();
    await page.screenshot({ path: 'test-results/learn-precision-card-desktop-page1.png', fullPage: true });

    await page.getByTestId('diagnosis-precision-disagree-next').click();
    await expect(page.getByTestId('diagnosis-precision-disagree-page')).toHaveText('Halaman 2 dari 2');
    await expect(list.locator('li')).toHaveCount(4);
    await expect(list.locator('li').first()).toContainText('TCK-E2E-09');
    await expect(page.getByTestId('diagnosis-precision-disagree-next')).toBeDisabled();
    // the other pager (wrong guesses) is independent: still page 1
    await expect(page.getByTestId('diagnosis-precision-page')).toHaveText('Halaman 1 dari 2');
    await page.screenshot({ path: 'test-results/learn-precision-card-desktop-page2.png', fullPage: true });

    await page.getByTestId('diagnosis-precision-disagree-prev').click();
    await expect(page.getByTestId('diagnosis-precision-disagree-page')).toHaveText('Halaman 1 dari 2');

    // phone width: the card fits, the tables scroll inside it
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(card).toBeVisible();
    const box = await card.boundingBox();
    expect(box).not.toBeNull();
    expect(box!.x + box!.width).toBeLessThanOrEqual(391);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(1);
    await page.screenshot({ path: 'test-results/learn-precision-card-mobile.png', fullPage: true });

    expect(appErrors).toEqual([]);
});

async function openCloseDialog(page: Page, ticketId: number, text: string): Promise<void> {
    await page.goto(`/tickets/${ticketId}`, { waitUntil: 'domcontentloaded' });
    await expect(page.getByText(text).first()).toBeVisible({ timeout: 45_000 });
    await page.getByRole('button', { name: 'Resolved', exact: true }).click();
    await expect(page.getByTestId('ticket-close-dialog')).toBeVisible();
}

test('close form: a hint under every main choice and the "Saran dari data" chip; "Pakai saran" saves the close with via = signature (desktop + phone)', async ({ page }) => {
    const appErrors = collectAppErrors(page);

    await openCloseDialog(page, SIGNATURE_TICKET, 'Internet mati (fixture E2E saran data SIGNATURE)');

    // one plain line under the choices (owner text for the two fibre ones)
    await expect(page.getByTestId('root-cause-hint-drop_los')).toHaveText('ONU merah / tidak ada sinyal');
    await expect(page.getByTestId('root-cause-hint-optical_degrade')).toHaveText('ONU nyala tapi redaman jelek atau putus-sambung');
    await expect(page.getByTestId('root-cause-hint-self_recovered')).toHaveText('Normal lagi sebelum dicek');
    await expect(page.getByTestId('root-cause-hint-other')).toHaveCount(0);

    // the chip names the repair, is never selected for the person and the close stays blocked until a cause is picked
    const chip = page.getByTestId('root-cause-signature');
    await expect(chip).toContainText('Saran dari data: Redaman / konektor — redaman membaik -28,0 → -20,7 setelah perbaikan. Pilih kalau sesuai.');
    await expect(page.locator('[data-testid^="root-cause-option-"][aria-pressed="true"]')).toHaveCount(0);
    await expect(page.getByTestId('ticket-close-confirm')).toBeDisabled();
    await page.screenshot({ path: 'test-results/learn-close-form-chip-desktop.png', fullPage: true });

    // phone: the hints and the chip fit, buttons stay tappable
    await page.setViewportSize({ width: 390, height: 844 });
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(1);
    const chipBox = await chip.boundingBox();
    expect(chipBox).not.toBeNull();
    expect(chipBox!.x + chipBox!.width).toBeLessThanOrEqual(391);
    const options = page.locator('[data-testid^="root-cause-option-"]');
    for (let i = 0; i < (await options.count()); i++) {
        const b = await options.nth(i).boundingBox();
        expect(b).not.toBeNull();
        expect(b!.height).toBeGreaterThanOrEqual(43.5);
        expect(b!.x + b!.width).toBeLessThanOrEqual(391);
    }
    await page.getByTestId('ticket-close-dialog').screenshot({ path: 'test-results/learn-close-form-chip-mobile.png' });
    await page.setViewportSize({ width: 1280, height: 800 });

    // "Pakai saran" selects the cause, the technician confirms, the close is saved
    await page.getByTestId('root-cause-use-signature').click();
    await expect(page.getByTestId('root-cause-option-optical_degrade')).toHaveAttribute('aria-pressed', 'true');
    await expect(page.getByTestId('ticket-close-confirm')).toBeEnabled();
    await page.waitForTimeout(400); // let the button colour transition finish before the picture
    await page.screenshot({ path: 'test-results/learn-close-form-chip-used.png', fullPage: true });
    await page.getByTestId('ticket-close-confirm').click();

    await expect(page.getByTestId('ticket-root-cause')).toContainText('Redaman / konektor', { timeout: 30_000 });
    await expect(page.getByTestId('ticket-close-dialog')).toHaveCount(0);
    await page.screenshot({ path: 'test-results/learn-close-form-chip-closed.png', fullPage: true });

    // the saved result: the timeline row carries via = signature
    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(page.getByTestId('ticket-root-cause')).toContainText('Redaman / konektor', { timeout: 30_000 });
    const via = await page.evaluate(() => {
        const props = JSON.parse(document.getElementById('app')!.getAttribute('data-page')!).props;
        const row = (props.ticket.activities as { action: string; new_value: { root_cause?: string; via?: string } }[]).find((a) => a.action === 'root_cause_set');
        return row ? `${row.new_value.root_cause}:${row.new_value.via}` : null;
    });
    expect(via).toBe('optical_degrade:signature');

    expect(appErrors).toEqual([]);
});

test('close form: the hints show without a chip when the data shows no repair, and a manual pick is saved as manual', async ({ page }) => {
    const appErrors = collectAppErrors(page);

    await openCloseDialog(page, NOCHIP_TICKET, 'Internet mati (fixture E2E saran data NOCHIP)');

    await expect(page.getByTestId('root-cause-hint-drop_los')).toHaveText('ONU merah / tidak ada sinyal');
    await expect(page.getByTestId('root-cause-signature')).toHaveCount(0);
    await page.screenshot({ path: 'test-results/learn-close-form-no-chip.png', fullPage: true });

    await page.getByTestId('root-cause-option-drop_los').click();
    await page.getByTestId('ticket-close-confirm').click();
    await expect(page.getByTestId('ticket-root-cause')).toContainText('Kabel drop putus', { timeout: 30_000 });

    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(page.getByTestId('ticket-root-cause')).toContainText('Kabel drop putus', { timeout: 30_000 });
    const via = await page.evaluate(() => {
        const props = JSON.parse(document.getElementById('app')!.getAttribute('data-page')!).props;
        const row = (props.ticket.activities as { action: string; new_value: { via?: string } }[]).find((a) => a.action === 'root_cause_set');
        return row ? row.new_value.via : null;
    });
    expect(via).toBe('manual');

    expect(appErrors).toEqual([]);
});

test('ticket detail on a phone: the panel fits the screen', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(`/tickets/${TICKET}`, { waitUntil: 'domcontentloaded' });
    const panel = page.getByTestId('diagnosis-panel');
    await expect(panel).toBeVisible({ timeout: 45_000 });
    const box = await panel.boundingBox();
    expect(box).not.toBeNull();
    expect(box!.x + box!.width).toBeLessThanOrEqual(391); // the panel fits the phone width
    await panel.scrollIntoViewIfNeeded();
    await panel.screenshot({ path: 'test-results/diagnosis-panel-mobile.png' });
});
