import { expect, test } from '@playwright/test';
import { mockMonitoringWidgets } from '../fixtures/monitoring-widgets/load';

function collectAppErrors(page: import('@playwright/test').Page): string[] {
    const appErrors: string[] = [];
    page.on('pageerror', (error) => appErrors.push(error.message));
    page.on('console', (message) => {
        if (message.type() === 'error') appErrors.push(message.text());
    });

    return appErrors;
}

test('monitoring jaringan tab renders Grafana widgets and stays error-free', async ({ page }) => {
    const appErrors = collectAppErrors(page);
    await mockMonitoringWidgets(page, 'ok');

    const response = await page.goto('/monitoring', { waitUntil: 'domcontentloaded' });

    // Jaringan is the default tab; the widgets come from fictitious fixtures.
    await expect(page.getByRole('heading', { name: 'Monitoring', exact: true })).toBeVisible({ timeout: 45_000 });
    await expect(page.getByText('Router online')).toBeVisible({ timeout: 45_000 });
    await expect(page.getByText('PPPoE online')).toBeVisible();
    await expect(page.getByText('Upstream A (contoh)').first()).toBeVisible();
    await expect(page.getByText('RTR-SUKAMAJU-01').first()).toBeVisible();
    await expect(page.getByText('ICMP diblokir').first()).toBeVisible();
    await expect(page.getByText('Kesehatan CPE')).toBeVisible();
    await expect(page.getByText('Area Contoh A')).toBeVisible();
    await expect(page.getByText(/Sumber: Grafana · diperbarui/)).toBeVisible();
    await expect(page.getByRole('radiogroup', { name: 'Rentang waktu' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Buka di Grafana' })).toHaveAttribute('rel', /noopener/);

    expect(response?.status() ?? 0).toBeLessThan(400);
    expect(appErrors).toEqual([]);
});

test('monitoring range selection is remembered and refetches widgets', async ({ page }) => {
    await mockMonitoringWidgets(page, 'ok');
    await page.goto('/monitoring', { waitUntil: 'domcontentloaded' });
    await expect(page.getByText('Router online')).toBeVisible({ timeout: 45_000 });

    const refetch = page.waitForRequest((request) => /\/api\/monitoring\/widgets\/summary\?range=24h/.test(request.url()));
    await page.getByRole('radio', { name: '24 jam terakhir' }).click();
    await refetch;

    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('radio', { name: '24 jam terakhir' })).toHaveAttribute('aria-checked', 'true', { timeout: 45_000 });
});

test('monitoring old overview URL redirects to the jaringan tab', async ({ page }) => {
    const appErrors = collectAppErrors(page);
    await mockMonitoringWidgets(page, 'ok');

    await page.goto('/monitoring?tab=overview', { waitUntil: 'domcontentloaded' });

    await expect(page).toHaveURL(/tab=jaringan/);
    await expect(page.getByText('Router online')).toBeVisible({ timeout: 45_000 });
    expect(appErrors).toEqual([]);
});

test('removed legacy monitoring tabs redirect to the jaringan tab', async ({ page }) => {
    const appErrors = collectAppErrors(page);
    await mockMonitoringWidgets(page, 'ok');

    for (const tab of ['overview-legacy', 'infrastructure', 'analytics']) {
        await page.goto(`/monitoring?tab=${tab}`, { waitUntil: 'domcontentloaded' });
        await expect(page).toHaveURL(/tab=jaringan/);
    }

    expect(appErrors).toEqual([]);
});

test('monitoring jaringan keeps working when Grafana is unavailable', async ({ page }) => {
    const appErrors = collectAppErrors(page);
    await mockMonitoringWidgets(page, 'unavailable');

    await page.goto('/monitoring?tab=jaringan', { waitUntil: 'domcontentloaded' });

    await expect(page.getByText('Grafana tidak dapat dijangkau').first()).toBeVisible({ timeout: 45_000 });
    await expect(page.getByText('RTR-SUKAMAJU-01').first()).toBeVisible();
    expect(appErrors).toEqual([]);
});

test('monitoring router names link to the router detail page', async ({ page }) => {
    const appErrors = collectAppErrors(page);
    await mockMonitoringWidgets(page, 'ok');
    await page.goto('/monitoring', { waitUntil: 'domcontentloaded' });

    const link = page.getByRole('link', { name: 'RTR-SUKAMAJU-01' }).first();
    await expect(link).toBeVisible({ timeout: 45_000 });
    await expect(link).toHaveAttribute('href', /\/monitoring\/routers\/\d+$/);
    expect(appErrors).toEqual([]);
});
