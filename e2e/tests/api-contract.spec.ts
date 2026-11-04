import { expect, test } from '@playwright/test';

test.describe('API contract (security)', () => {
    test('attendance export rejects missing token', async ({ request }) => {
        const res = await request.get('/api/attendances/export');
        expect([401, 503]).toContain(res.status());
        const body = await res.json();
        expect(body).toHaveProperty('error');
    });

    test('attendance export rejects token in query string', async ({ request }) => {
        const res = await request.get('/api/attendances/export?token=leaked');
        expect([401, 503]).toContain(res.status());
    });

    test('analytics mrr requires authentication', async () => {
        const base = process.env.E2E_BASE_URL;

        if (!base) {
            throw new Error('E2E_BASE_URL is required for the isolated sandbox.');
        }

        const res = await fetch(`${base}/api/analytics/mrr`, {
            headers: { Accept: 'application/json' },
            redirect: 'manual',
        });
        const body = await res.json().catch(() => null);

        if (body && typeof body === 'object' && 'current_mrr' in body) {
            throw new Error('analytics mrr returned authenticated payload without session');
        }
        expect([401, 403, 302, 303]).toContain(res.status);
    });

    test('analytics mrr returns scoped payload when authenticated', async ({ page }) => {
        await page.goto('/dashboard', { waitUntil: 'domcontentloaded' });
        const res = await page.request.get('/api/analytics/mrr');
        expect(res.ok()).toBeTruthy();
        const body = await res.json();
        expect(body).toHaveProperty('current_mrr');
        expect(body).toHaveProperty('growth_percentage');
        expect(body).toHaveProperty('trend');
        expect(Array.isArray(body.trend)).toBeTruthy();
    });

    test('analytics revenue-trend returns array when authenticated', async ({ page }) => {
        await page.goto('/dashboard', { waitUntil: 'domcontentloaded' });
        const res = await page.request.get('/api/analytics/revenue-trend?months=6');
        expect(res.ok()).toBeTruthy();
        const body = await res.json();
        expect(Array.isArray(body)).toBeTruthy();
    });
});
