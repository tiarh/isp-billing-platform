import { expect, test, type Page } from '@playwright/test';

/**
 * Manual "Buat tiket" of Deteksi Gangguan: button -> dialog -> ticket -> "Tiket #N" -> reload -> ticket page.
 * Fixtures: OutageManualTicketE2ESeeder (episodes 9300001 single, 9300002 mass, 9300003 mobile; all 40 days old,
 * opened by id). The sandbox has no WA gateway, so "Pesan ke grup Report gagal terkirim." on the row is expected. Retry-safe: when the episode already has a ticket (a retry after a failed run) the creation steps
 * are skipped and the rest is checked again.
 */
const SINGLE = 9300001;
const MASS = 9300002;
const MOBILE = 9300003;

function collectAppErrors(page: Page): string[] {
    const appErrors: string[] = [];
    page.on('pageerror', (error) => appErrors.push(error.message));
    page.on('console', (message) => {
        if (message.type() === 'error') appErrors.push(message.text());
    });

    return appErrors;
}

async function openEpisode(page: Page, id: number) {
    await page.goto(`/monitoring?tab=deteksi&episode=${id}`, { waitUntil: 'domcontentloaded' });
    await expect(page.getByText('1 kejadian')).toBeVisible({ timeout: 45_000 });
    await expect(page.getByText(`#${id}`, { exact: true })).toBeVisible();
}

/** Click through the dialog unless the episode already has a ticket. */
async function createTicket(page: Page, id: number, shot: string) {
    const link = page.getByTestId('outage-ticket-link');
    if (await link.count()) return;

    const button = page.getByTestId('outage-ticket-button');
    await expect(button).toBeVisible();
    await button.scrollIntoViewIfNeeded();
    await page.screenshot({ path: `test-results/${shot}-1-button.png`, fullPage: true });

    const preview = page.waitForResponse((r) => new RegExp(`/outage-shadow/${id}/ticket-preview`).test(r.url()) && r.ok());
    await button.click();
    await preview;

    const dialog = page.getByRole('dialog');
    await expect(dialog.getByText('Buat tiket gangguan?')).toBeVisible();
    await expect(page.getByTestId('outage-ticket-lines')).toContainText(`Kejadian #${id}`);
    await expect(page.getByTestId('outage-ticket-info')).toContainText('Tiket dibuat tanpa teknisi. Pelanggan tidak dikirimi pesan.');
    await expect(page.getByTestId('outage-ticket-info')).toContainText('Grup Report menerima 1 pesan.');
    await dialog.getByLabel('Catatan (opsional)').fill('Dicek dari uji otomatis');
    await page.screenshot({ path: `test-results/${shot}-2-dialog.png`, fullPage: true });

    const saved = page.waitForResponse((r) => new RegExp(`/outage-shadow/${id}/ticket$`).test(r.url()) && r.request().method() === 'POST');
    await dialog.getByRole('button', { name: 'Buat tiket', exact: true }).click();
    const response = await saved;
    expect([200, 201]).toContain(response.status());

    await expect(page.getByRole('dialog')).toHaveCount(0);
    await expect(link).toBeVisible();
}

async function ticketIdFromLink(page: Page): Promise<string> {
    const text = (await page.getByTestId('outage-ticket-link').innerText()).trim();
    const match = text.match(/Tiket #(\d+)/);
    expect(match).not.toBeNull();

    return match![1];
}

test('deteksi: a single episode gets a ticket from the button and the ticket page shows its source', async ({ page }) => {
    const appErrors = collectAppErrors(page);

    await openEpisode(page, SINGLE);
    await createTicket(page, SINGLE, 'monitoring-deteksi-ticket-single');

    const ticketId = await ticketIdFromLink(page);
    await expect(page.getByTestId('outage-ticket-link')).toContainText(`Tiket #${ticketId}`);
    await expect(page.getByTestId('outage-ticket-button')).toHaveCount(0);
    await page.screenshot({ path: 'test-results/monitoring-deteksi-ticket-single-3-created.png', fullPage: true });

    // after a reload the row still shows the ticket (it is stored, not just local state)
    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(page.getByText('1 kejadian')).toBeVisible({ timeout: 45_000 });
    await expect(page.getByTestId('outage-ticket-link')).toContainText(`Tiket #${ticketId}`);
    await expect(page.getByTestId('outage-ticket-button')).toHaveCount(0);
    await page.getByTestId('outage-ticket-link').scrollIntoViewIfNeeded();
    await page.screenshot({ path: 'test-results/monitoring-deteksi-ticket-single-4-reload.png', fullPage: true });

    // the ticket page: customer, source link, evidence
    await page.getByTestId('outage-ticket-link').getByRole('link').click();
    await expect(page).toHaveURL(new RegExp(`/tickets/${ticketId}$`));
    const source = page.getByTestId('ticket-detection-source');
    await expect(source).toBeVisible({ timeout: 45_000 });
    await expect(source).toContainText(`Sumber: Deteksi Gangguan, kejadian #${SINGLE}`);
    await expect(page.getByText('Pelanggan Tiket Satu').first()).toBeVisible();
    await expect(page.getByText('[Deteksi] LOS individual').first()).toBeVisible();
    await page.screenshot({ path: 'test-results/monitoring-deteksi-ticket-single-5-ticket-page.png', fullPage: true });

    // the source link goes back to the episode
    await source.getByRole('link').click();
    await expect(page).toHaveURL(new RegExp(`episode=${SINGLE}`));
    await expect(page.getByTestId('outage-ticket-link')).toContainText(`Tiket #${ticketId}`, { timeout: 45_000 });

    expect(appErrors).toEqual([]);
});

test('deteksi: a mass episode gets ONE ticket without a customer and with the affected list', async ({ page }) => {
    const appErrors = collectAppErrors(page);

    await openEpisode(page, MASS);
    await createTicket(page, MASS, 'monitoring-deteksi-ticket-mass');

    const ticketId = await ticketIdFromLink(page);
    await page.screenshot({ path: 'test-results/monitoring-deteksi-ticket-mass-3-created.png', fullPage: true });

    await page.getByTestId('outage-ticket-link').getByRole('link').click();
    await expect(page).toHaveURL(new RegExp(`/tickets/${ticketId}$`));
    await expect(page.getByTestId('ticket-detection-source')).toContainText(`kejadian #${MASS}`, { timeout: 45_000 });
    await expect(page.getByText('Gangguan massal — OLT E2E Tiket PON 1/4/2 (6 pelanggan)').first()).toBeVisible();
    await expect(page.getByText('Pelanggan Massal 1').first()).toBeVisible();
    await page.screenshot({ path: 'test-results/monitoring-deteksi-ticket-mass-4-ticket-page.png', fullPage: true });

    expect(appErrors).toEqual([]);
});

test.describe('mobile', () => {
    test.use({ viewport: { width: 390, height: 844 } });

    test('deteksi: the dialog fits a phone screen and Batal creates nothing', async ({ page }) => {
        const appErrors = collectAppErrors(page);

        await openEpisode(page, MOBILE);
        const button = page.getByTestId('outage-ticket-button');
        await expect(button).toBeVisible();

        const preview = page.waitForResponse((r) => new RegExp(`/outage-shadow/${MOBILE}/ticket-preview`).test(r.url()) && r.ok());
        await button.click();
        await preview;

        const dialog = page.getByRole('dialog');
        await expect(dialog.getByText('Buat tiket gangguan?')).toBeVisible();
        await expect(page.getByTestId('outage-ticket-info')).toBeVisible();
        await expect(dialog.getByRole('button', { name: 'Buat tiket', exact: true })).toBeVisible();
        await expect(dialog.getByRole('button', { name: 'Batal' })).toBeVisible();

        const box = await dialog.boundingBox();
        expect(box).not.toBeNull();
        expect(box!.x).toBeGreaterThanOrEqual(0);
        expect(box!.x + box!.width).toBeLessThanOrEqual(390 + 1);
        expect(box!.y + box!.height).toBeLessThanOrEqual(844 + 1);
        await page.screenshot({ path: 'test-results/monitoring-deteksi-ticket-mobile-dialog.png', fullPage: false });

        await dialog.getByRole('button', { name: 'Batal' }).click();
        await expect(page.getByRole('dialog')).toHaveCount(0);
        await expect(page.getByTestId('outage-ticket-link')).toHaveCount(0);
        await expect(button).toBeVisible();

        expect(appErrors).toEqual([]);
    });
});
