import { expect, test } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import type { Browser } from '@playwright/test';
import type { Page } from '@playwright/test';

type SubmitResult = { status: number; location: string | null; body: string };

// 1x1 PNG; the page re-encodes it to JPEG before upload.
const E2E_PHOTO = (name: string) => ({
    name: `${name}.png`,
    mimeType: 'image/png',
    buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==', 'base64'),
});

async function submit(page: Page, path: string, data: Record<string, string>): Promise<SubmitResult> {
    return page.evaluate(async ({ path, data }) => {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const response = await fetch(path, {
            method: 'POST',
            redirect: 'follow',
            headers: {
                Accept: 'application/json, text/html,application/xhtml+xml',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
            },
            body: new URLSearchParams({ ...data, _token: csrf }).toString(),
        });

        return { status: response.status, location: response.headers.get('location'), body: await response.text() };
    }, { path, data });
}

// Owner 2026-10-09: pickup capture requires label SN + device photos.
async function submitWithPhotos(page: Page, path: string, data: Record<string, string>, photoFields: Record<string, number>): Promise<SubmitResult> {
    return page.evaluate(async ({ path, data, photoFields }) => {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const body = new FormData();
        Object.entries({ ...data, _token: csrf }).forEach(([key, value]) => body.append(key, value));
        const png = Uint8Array.from(atob('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg=='), (char) => char.charCodeAt(0));
        Object.entries(photoFields).forEach(([field, count]) => {
            for (let index = 0; index < count; index++) {
                body.append(`${field}[]`, new File([png], `${field}-${index}.png`, { type: 'image/png' }));
            }
        });
        const response = await fetch(path, {
            method: 'POST',
            redirect: 'follow',
            headers: {
                Accept: 'application/json, text/html,application/xhtml+xml',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body,
        });

        return { status: response.status, location: response.headers.get('location'), body: await response.text() };
    }, { path, data, photoFields });
}

const PICKUP_PHOTOS = { label_sn_photos: 1, device_photos: 1 };
// Owner 2026-10-09: warehouse receipt needs the physical SN + a receipt photo.
const RECEIPT_PHOTOS = { receipt_photos: 1 };

async function recoveryDetail(page: Page, id: number): Promise<Record<string, any>> {
    return page.evaluate(async (path) => {
        const response = await fetch(path, { headers: { Accept: 'application/json' } });
        return { status: response.status, body: await response.json() };
    }, `/device-recovery/${id}`).then((result) => {
        expect(result.status, `recovery detail ${id} must be readable`).toBe(200);
        return result.body;
    });
}

async function caseIdByTicket(page: Page, ticketCode: string): Promise<number> {
    await page.goto(`/device-recovery?search=${encodeURIComponent(ticketCode)}`, { waitUntil: 'domcontentloaded' });
    const row = page.locator('tbody tr').filter({ hasText: ticketCode });
    const detailRequest = page.waitForRequest((request) => {
        const path = new URL(request.url()).pathname;

        return request.method() === 'GET' && /^\/device-recovery\/\d+$/.test(path);
    });
    await row.click();
    const request = await detailRequest;
    const id = Number(new URL(request.url()).pathname.split('/').pop());
    await page.keyboard.press('Escape');

    expect(Number.isInteger(id) && id > 0, `case id for ${ticketCode} must be present`).toBeTruthy();

    return id;
}

async function loginAs(browser: Browser, email: string): Promise<{ context: Awaited<ReturnType<Browser['newContext']>>; page: Page }> {
    const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    const page = await context.newPage();
    const password = process.env.E2E_PASSWORD;

    if (!password) throw new Error('E2E_PASSWORD is required for the isolated sandbox.');

    await page.goto('/login', { waitUntil: 'domcontentloaded' });
    await page.locator('#email').fill(email);
    await page.locator('#password').fill(password);
    await Promise.all([
        page.waitForURL(/\/dashboard/, { timeout: 45_000 }),
        page.getByRole('button', { name: /sign in/i }).click({ noWaitAfter: true }),
    ]);

    return { context, page };
}

function collectRuntimeErrors(page: Page): string[] {
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    page.on('console', (message) => {
        if (message.type() === 'error') errors.push(message.text());
    });

    return errors;
}

test.describe('Device recovery read-only smoke', () => {
    test('inventaris exposes the recovery context without changing asset tabs', async ({ page }) => {
        const errors = collectRuntimeErrors(page);
        const response = await page.goto('/inventaris?type=recovery', { waitUntil: 'domcontentloaded' });

        expect(response?.status()).toBeLessThan(400);
        await expect(page.getByRole('heading', { name: 'Inventaris Aset' })).toBeVisible();
        await expect(page.getByRole('tab', { name: /Perangkat kembali/ })).toBeVisible();
        await expect(page.getByText('Status pengambilan perangkat')).toBeVisible();
        await expect(page.locator('main')).toContainText(/Assignment bukan bukti perangkat sudah diterima gudang|Belum ada perangkat kembali|Perangkat kembali/);
        expect(errors, 'inventaris recovery must not emit browser errors').toEqual([]);

        await page.screenshot({ path: 'e2e-report/device-recovery-inventaris.png', fullPage: true });
    });

    test('inventaris export and import preview preserve read-only boundaries', async ({ page }) => {
        const errors = collectRuntimeErrors(page);
        await page.goto('/inventaris?type=recovery', { waitUntil: 'domcontentloaded' });

        const exportResult = await page.evaluate(async () => {
            const response = await fetch('/inventaris/export?type=recovery&area=1', {
                headers: { Accept: 'text/csv' },
            });

            return {
                status: response.status,
                contentType: response.headers.get('content-type') || '',
                body: await response.text(),
            };
        });

        expect(exportResult.status).toBe(200);
        expect(exportResult.contentType).toContain('text/csv');
        expect(exportResult.body).toContain('=== Perangkat Kembali ===');
        expect(exportResult.body).toContain('TCK-E2E-001');
        expect(exportResult.body).not.toContain('password');
        expect(exportResult.body).not.toContain('secret');
        expect(exportResult.body).not.toContain('api_key');

        await page.locator('#inventory-import-file').setInputFiles({
            name: 'e2e-inventory.csv',
            mimeType: 'text/csv',
            buffer: Buffer.from('name,area,type\nODP-E2E-NEW,E2E Area,odp\n,Unknown Area,odp\n'),
        });

        await expect(page.getByRole('heading', { name: 'Preview Import: e2e-inventory.csv' })).toBeVisible();
        await expect(page.locator('main')).toContainText('ODP-E2E-NEW');
        await expect(page.locator('main')).toContainText('Nama/area/tipe belum lengkap');
        expect(errors, 'inventaris import/export must not emit browser errors').toEqual([]);
    });

    test('legacy inventory tabs and hostile recovery search remain safe', async ({ page }) => {
        const errors = collectRuntimeErrors(page);

        for (const type of ['odp', 'odc', 'kabel', 'splitter']) {
            const response = await page.goto(`/inventaris?type=${type}`, { waitUntil: 'domcontentloaded' });
            expect(response?.status(), `legacy inventory tab ${type} must load`).toBeLessThan(400);
            await expect(page.getByRole('heading', { name: 'Inventaris Aset' })).toBeVisible();
        }

        const hostileSearch = encodeURIComponent(`' OR 1=1 -- <script>alert(1)</script>\u0000`);
        const recoveryResponse = await page.goto(`/device-recovery?search=${hostileSearch}`, { waitUntil: 'domcontentloaded' });
        expect(recoveryResponse?.status()).toBeLessThan(400);
        await expect(page.getByRole('heading', { name: 'Pengambilan Perangkat', level: 2 })).toBeVisible();
        expect(errors, 'legacy tabs and hostile search must not emit browser errors').toEqual([]);
    });

    test('anonymous users cannot open recovery administration', async ({ browser }) => {
        const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
        const anonymousPage = await context.newPage();
        const errors = collectRuntimeErrors(anonymousPage);

        try {
            const response = await anonymousPage.goto('/device-recovery?search=HWTC-E2E-0001', { waitUntil: 'domcontentloaded' });
            expect(response?.status()).toBe(200);
            await expect(anonymousPage).toHaveURL(/\/login/);
            expect(errors, 'anonymous recovery access must not emit browser errors').toEqual([]);
        } finally {
            await context.close();
        }
    });

    test('recovery queue renders a safe empty or populated state', async ({ page }) => {
        const errors = collectRuntimeErrors(page);
        const response = await page.goto('/device-recovery?limit=20', { waitUntil: 'domcontentloaded' });

        expect(response?.status()).toBeLessThan(400);
        await expect(page.getByRole('heading', { name: 'Pengambilan Perangkat', level: 2 })).toBeVisible();
        await expect(page.locator('main')).toContainText(/Belum ada recovery perangkat|Daftar pengambilan/);
        expect(errors, 'recovery queue must not emit browser errors').toEqual([]);

        await page.screenshot({ path: 'e2e-report/device-recovery-queue.png', fullPage: true });
    });

    test('approved dismantle completes recovery lifecycle with boundaries and idempotency', async ({ page, browser }) => {
        const errors = collectRuntimeErrors(page);
        await page.goto('/device-recovery?limit=20', { waitUntil: 'domcontentloaded' });

        const create = await submit(page, '/tickets/2/device-recovery', {
            device_type: 'onu',
            idempotency_key: 'e2e-create-ticket-2',
        });
        expect(create.status).toBe(200);

        const caseId = await caseIdByTicket(page, 'TCK-E2E-ADMIN-001');
        const assign = await submit(page, `/device-recovery/${caseId}/assign`, {
            assigned_to: '2',
            idempotency_key: 'e2e-assign-case-2',
        });
        expect(assign.status).toBe(200);
        const itemId = (await recoveryDetail(page, caseId)).items[0].id;
        expect((await recoveryDetail(page, caseId)).status).toBe('assigned');

        const technician = await loginAs(browser, 'e2e-technician@example.test');
        try {
            const start = await submit(technician.page, `/device-recovery/${caseId}/start`, {
                idempotency_key: 'e2e-start-case-2',
            });
            expect(start.status).toBe(200);

            const capture = await submitWithPhotos(technician.page, `/device-recovery/${caseId}/items/${itemId}/capture`, {
                serial_number: 'HWTC-E2E-0002',
                condition: 'good',
                idempotency_key: 'e2e-capture-case-2',
            }, PICKUP_PHOTOS);
            expect(capture.status).toBe(200);
        } finally {
            await technician.context.close();
        }

        let detail = await recoveryDetail(page, caseId);
        expect(detail.status).toBe('carried');
        expect(detail.items[0].captured_serial_number).toBe('HWTCE2E0002');

        const closeBeforeWarehouse = await submit(page, '/tickets/2', {
            _method: 'PATCH',
            status: 'closed',
        });
        expect(closeBeforeWarehouse.status).toBe(422);
        expect(closeBeforeWarehouse.body).toContain('perangkat belum diterima gudang');

        const receive = await submitWithPhotos(page, `/device-recovery/${caseId}/receive`, {
            [`serials[${itemId}]`]: 'hwtc e2e 0002',
            idempotency_key: 'e2e-receive-case-2',
        }, RECEIPT_PHOTOS);
        expect(receive.status).toBe(200);
        detail = await recoveryDetail(page, caseId);
        expect(detail.status).toBe('received');
        expect(detail.items[0].status).toBe('received');

        const closeAfterWarehouse = await submit(page, '/tickets/2', {
            _method: 'PATCH',
            status: 'closed',
        });
        expect(closeAfterWarehouse.status).toBe(200);

        const exceptionCreate = await submit(page, '/tickets/3/device-recovery', {
            device_type: 'onu',
            idempotency_key: 'e2e-create-ticket-3',
        });
        expect(exceptionCreate.status).toBe(200);
        const exceptionCaseId = await caseIdByTicket(page, 'TCK-E2E-EXCEPTION-001');
        expect((await submit(page, `/device-recovery/${exceptionCaseId}/assign`, {
            assigned_to: '2',
            idempotency_key: 'e2e-assign-case-3',
        })).status).toBe(200);
        const exceptionItemId = (await recoveryDetail(page, exceptionCaseId)).items[0].id;

        const exceptionTechnician = await loginAs(browser, 'e2e-technician@example.test');
        try {
            expect((await submit(exceptionTechnician.page, `/device-recovery/${exceptionCaseId}/start`, {
                idempotency_key: 'e2e-start-case-3',
            })).status).toBe(200);
            expect((await submitWithPhotos(exceptionTechnician.page, `/device-recovery/${exceptionCaseId}/items/${exceptionItemId}/capture`, {
                serial_number: 'HWTC-E2E-WRONG',
                condition: 'good',
                reason: 'SN tidak cocok',
                idempotency_key: 'e2e-capture-case-3',
            }, PICKUP_PHOTOS)).status).toBe(200);
        } finally {
            await exceptionTechnician.context.close();
        }

        detail = await recoveryDetail(page, exceptionCaseId);
        expect(detail.status).toBe('exception');
        expect(detail.items[0].condition).toBe('mismatch');
        const exceptionResolved = await submit(page, `/device-recovery/${exceptionCaseId}/resolve-exception`, {
            resolution: 'accept',
            reason: 'Supervisor menerima hasil mismatch',
            idempotency_key: 'e2e-resolve-case-3',
        });
        expect(exceptionResolved.status).toBe(200);
        expect((await recoveryDetail(page, exceptionCaseId)).status).toBe('exception_accepted');

        const scopedAdmin = await loginAs(browser, 'e2e-scoped-admin@example.test');
        try {
            const idor = await scopedAdmin.page.evaluate(async (path) => {
                const response = await fetch(path, { headers: { Accept: 'application/json' } });
                return response.status;
            }, `/device-recovery/${caseId}`);
            expect(idor).toBe(403);
        } finally {
            await scopedAdmin.context.close();
        }

        const technicianCannotAssign = await loginAs(browser, 'e2e-technician@example.test');
        try {
            const forbidden = await submit(technicianCannotAssign.page, `/device-recovery/${exceptionCaseId}/assign`, {
                assigned_to: '2',
                idempotency_key: 'e2e-technician-cannot-assign',
            });
            expect(forbidden.status).toBe(403);
        } finally {
            await technicianCannotAssign.context.close();
        }

        const concurrent = await Promise.all([
            submit(page, '/tickets/4/device-recovery', { device_type: 'onu', idempotency_key: 'e2e-concurrent-create' }),
            submit(page, '/tickets/4/device-recovery', { device_type: 'onu', idempotency_key: 'e2e-concurrent-create' }),
        ]);
        expect(concurrent.map((result) => result.status)).toEqual([200, 200]);
        const concurrentCaseId = await caseIdByTicket(page, 'TCK-E2E-CONCURRENT-001');
        const concurrentCase = await recoveryDetail(page, concurrentCaseId);
        expect(concurrentCase.ticket.code).toBe('TCK-E2E-CONCURRENT-001');
        expect(concurrentCase.items).toHaveLength(1);

        errors.length = 0;
        await page.goto('/device-recovery?search=TCK-E2E-001', { waitUntil: 'domcontentloaded' });
        await expect(page.locator('main')).toContainText('TCK-E2E-001');
        expect(errors, 'recovery lifecycle must not emit browser errors').toEqual([]);
    });

    test('primary recovery actions work through the UI drawer', async ({ page, browser }) => {
        const errors = collectRuntimeErrors(page);
        const openCase = async (targetPage: Page, ticketCode: string) => {
            await targetPage.goto(`/device-recovery?search=${ticketCode}`, { waitUntil: 'domcontentloaded' });
            const row = targetPage.locator('tbody tr').filter({ hasText: ticketCode });
            await row.click();
            const drawer = targetPage.getByRole('dialog');
            await drawer.waitFor({ state: 'visible' });
            return drawer;
        };

        let drawer = await openCase(page, 'TCK-E2E-CONCURRENT-001');
        await drawer.getByLabel('Pilih teknisi recovery').selectOption('2');
        await drawer.getByRole('button', { name: 'Simpan penugasan' }).click();
        await drawer.waitFor({ state: 'hidden' });
        drawer = await openCase(page, 'TCK-E2E-CONCURRENT-001');
        await expect(drawer).toContainText('Perangkat sedang diambil');
        await expect(drawer).toContainText('Ditugaskan');
        await expect(drawer).toContainText('Status tiket: closed');
        await page.keyboard.press('Escape');
        await drawer.waitFor({ state: 'hidden' });

        const technician = await loginAs(browser, 'e2e-technician@example.test');
        try {
            // Owner 2026-10-09: one form, no separate start; label SN + device photos required.
            drawer = await openCase(technician.page, 'TCK-E2E-CONCURRENT-001');
            await drawer.locator('#recovery-capture-sn').fill('HWTC-E2E-0001');
            await drawer.locator('#recovery-photo-label-sn').setInputFiles(E2E_PHOTO('label-sn'));
            await drawer.locator('#recovery-photo-device').setInputFiles(E2E_PHOTO('perangkat'));
            await drawer.getByTestId('recovery-capture-submit').click();
            await drawer.waitFor({ state: 'hidden' });
        } finally {
            await technician.context.close();
        }

        drawer = await openCase(page, 'TCK-E2E-CONCURRENT-001');
        await expect(drawer).toContainText('Dibawa teknisi');
        await drawer.locator('input[id^="recovery-receive-sn-"]').first().fill('HWTC-E2E-0001');
        await drawer.locator('#recovery-photo-receipt').setInputFiles(E2E_PHOTO('penerimaan'));
        await drawer.getByRole('button', { name: 'Tandai diterima gudang' }).click();
        await drawer.waitFor({ state: 'hidden' });
        drawer = await openCase(page, 'TCK-E2E-CONCURRENT-001');
        await expect(drawer).toContainText('Diterima gudang');
        await page.screenshot({ path: 'e2e-report/device-recovery-ui-drawer.png', fullPage: true });
        expect(errors, 'UI recovery actions must not emit browser errors').toEqual([]);
    });

    test('detail error has a working retry action', async ({ page }) => {
        const errors = collectRuntimeErrors(page);
        let failOnce = true;
        await page.route('**/device-recovery/*', async (route) => {
            const pathname = new URL(route.request().url()).pathname;

            if (failOnce && /^\/device-recovery\/\d+$/.test(pathname)) {
                failOnce = false;
                await route.abort();
                return;
            }

            await route.continue();
        });

        await page.goto('/device-recovery?search=TCK-E2E-001', { waitUntil: 'domcontentloaded' });
        const row = page.locator('tbody tr').filter({ hasText: 'TCK-E2E-001' });
        await row.click();
        const drawer = page.getByRole('dialog');
        await expect(drawer.getByRole('alert')).toBeVisible();
        errors.length = 0;
        await drawer.getByRole('button', { name: 'Coba lagi' }).click();
        await expect(drawer).toContainText('Perangkat sedang diambil');
        expect(errors, 'detail retry must not emit browser errors').toEqual([]);
    });

    test('admin can edit, add, schedule, and cancel before pickup through the UI', async ({ page }) => {
        const errors = collectRuntimeErrors(page);
        const openCase = async () => {
            await page.goto('/device-recovery?search=TCK-E2E-001', { waitUntil: 'domcontentloaded' });
            const row = page.locator('tbody tr').filter({ hasText: 'TCK-E2E-001' });
            await row.click();
            const drawer = page.getByRole('dialog');
            await drawer.waitFor({ state: 'visible' });
            return drawer;
        };

        let drawer = await openCase();
        await expect(drawer).toContainText('Perangkat sedang diambil');
        await expect(drawer).toContainText('Ditugaskan');
        await drawer.getByRole('button', { name: 'Edit data' }).first().click();
        await drawer.locator('input[id^="recovery-expected-sn-"]').first().fill('HWTC-E2E-UI-UPDATED');
        await drawer.getByRole('button', { name: 'Simpan data' }).click();
        await drawer.waitFor({ state: 'hidden' });

        drawer = await openCase();
        await expect(drawer).toContainText('HWTCE2EUIUPDATED');
        await drawer.getByLabel('Jenis perangkat tambahan').selectOption('router');
        await drawer.getByLabel('SN expected perangkat tambahan').fill('ROUTER-E2E-UI-001');
        await drawer.getByRole('button', { name: 'Tambah perangkat' }).click();
        await drawer.waitFor({ state: 'hidden' });

        drawer = await openCase();
        await expect(drawer.getByRole('button', { name: 'Edit data' })).toHaveCount(2);
        await drawer.getByLabel('Pilih teknisi recovery').selectOption('2');
        await drawer.getByLabel('Jadwal pengambilan').fill('2030-01-02T10:00');
        await drawer.getByRole('button', { name: 'Simpan penugasan' }).click();
        await drawer.waitFor({ state: 'hidden' });

        drawer = await openCase();
        await expect(drawer).toContainText('Dijadwalkan');
        await drawer.getByLabel('Alasan pembatalan recovery').fill('Pelanggan menunda pengambilan');
        await drawer.getByRole('button', { name: 'Batalkan recovery' }).click();
        await drawer.waitFor({ state: 'hidden' });

        drawer = await openCase();
        await expect(drawer).toContainText('Dibatalkan');
        await expect(drawer).toContainText('HWTCE2EUIUPDATED');
        await expect(drawer).toContainText('ROUTERE2EUI001');
        expect(errors, 'pre-pickup UI actions must not emit browser errors').toEqual([]);
    });

    test('E2E-08 concurrent duplicate transitions remain single-event and consistent', async ({ page, browser }) => {
        await page.goto('/device-recovery?limit=20', { waitUntil: 'domcontentloaded' });

        const create = await Promise.all([
            submit(page, '/tickets/5/device-recovery', { device_type: 'onu', idempotency_key: 'e2e-race-create' }),
            submit(page, '/tickets/5/device-recovery', { device_type: 'onu', idempotency_key: 'e2e-race-create' }),
        ]);
        expect(create.map((result) => result.status)).toEqual([200, 200]);

        const caseId = await caseIdByTicket(page, 'TCK-E2E-RACE-001');
        const concurrentDetail = async () => recoveryDetail(page, caseId);
        const assertSingleEvent = async (eventType: string, expectedStatus: string) => {
            const detail = await concurrentDetail();
            expect(detail.status).toBe(expectedStatus);
            expect(detail.events.filter((event: { event_type: string }) => event.event_type === eventType)).toHaveLength(1);
        };

        const assign = await Promise.all([
            submit(page, `/device-recovery/${caseId}/assign`, { assigned_to: '2', idempotency_key: 'e2e-race-assign' }),
            submit(page, `/device-recovery/${caseId}/assign`, { assigned_to: '2', idempotency_key: 'e2e-race-assign' }),
        ]);
        expect(assign.map((result) => result.status)).toEqual([200, 200]);
        await assertSingleEvent('assigned', 'assigned');

        const itemId = (await concurrentDetail()).items[0].id;
        const technician = await loginAs(browser, 'e2e-technician@example.test');
        try {
            const start = await Promise.all([
                submit(technician.page, `/device-recovery/${caseId}/start`, { idempotency_key: 'e2e-race-start' }),
                submit(technician.page, `/device-recovery/${caseId}/start`, { idempotency_key: 'e2e-race-start' }),
            ]);
            expect(start.map((result) => result.status)).toEqual([200, 200]);

            const capture = await Promise.all([
                submitWithPhotos(technician.page, `/device-recovery/${caseId}/items/${itemId}/capture`, { serial_number: 'HWTC-E2E-0003', condition: 'good', idempotency_key: 'e2e-race-capture' }, PICKUP_PHOTOS),
                submitWithPhotos(technician.page, `/device-recovery/${caseId}/items/${itemId}/capture`, { serial_number: 'HWTC-E2E-0003', condition: 'good', idempotency_key: 'e2e-race-capture' }, PICKUP_PHOTOS),
            ]);
            expect(capture.map((result) => result.status)).toEqual([200, 200]);
        } finally {
            await technician.context.close();
        }

        await assertSingleEvent('pickup_started', 'carried');
        await assertSingleEvent('serial_captured', 'carried');

        const receive = await Promise.all([
            submitWithPhotos(page, `/device-recovery/${caseId}/receive`, { [`serials[${itemId}]`]: 'HWTC-E2E-0003', idempotency_key: 'e2e-race-receive' }, RECEIPT_PHOTOS),
            submitWithPhotos(page, `/device-recovery/${caseId}/receive`, { [`serials[${itemId}]`]: 'HWTC-E2E-0003', idempotency_key: 'e2e-race-receive' }, RECEIPT_PHOTOS),
        ]);
        expect(receive.map((result) => result.status)).toEqual([200, 200]);
        await assertSingleEvent('warehouse_received', 'received');

        const close = await Promise.all([
            submit(page, '/tickets/5', { _method: 'PATCH', status: 'closed' }),
            submit(page, '/tickets/5', { _method: 'PATCH', status: 'closed' }),
        ]);
        expect(close.map((result) => result.status)).toEqual([200, 200]);
        await page.goto('/tickets/5', { waitUntil: 'domcontentloaded' });
        await expect(page.locator('main')).toContainText('Closed');
    });

    test('E2E-07 UI variants keep recovery usable and accessible', async ({ page }) => {
        const errors = collectRuntimeErrors(page);
        const variants = [
            { name: 'desktop-light', width: 1440, height: 900, colorScheme: 'light' as const },
            { name: 'desktop-dark', width: 1440, height: 900, colorScheme: 'dark' as const },
            { name: 'mobile-light', width: 390, height: 844, colorScheme: 'light' as const },
            { name: 'mobile-dark', width: 390, height: 844, colorScheme: 'dark' as const },
        ];

        for (const variant of variants) {
            await page.setViewportSize({ width: variant.width, height: variant.height });
            await page.emulateMedia({ colorScheme: variant.colorScheme });
            await page.goto('/device-recovery?search=TCK-E2E-001', { waitUntil: 'domcontentloaded' });
            await expect(page.getByRole('heading', { name: 'Pengambilan Perangkat', level: 2 })).toBeVisible();

            const formButtonTypes = await page.locator('form button').evaluateAll((buttons) =>
                buttons.map((button) => button.getAttribute('type')),
            );
            expect(formButtonTypes, `${variant.name} form buttons must declare type`).not.toContain(null);

            await page.locator('tbody tr:visible, button:visible').filter({ hasText: 'TCK-E2E-001' }).first().click();
            const drawer = page.getByRole('dialog');
            await expect(drawer).toBeVisible();
            await page.keyboard.press('Escape');
            await expect(drawer).toBeHidden();
            const accessibility = await new AxeBuilder({ page }).include('main').analyze();
            expect(accessibility.violations, `${variant.name} accessibility violations`).toEqual([]);
            await page.screenshot({ path: `e2e-report/device-recovery-${variant.name}.png`, fullPage: true });
        }

        expect(errors, 'recovery UI variants must not emit browser errors').toEqual([]);
    });
});
