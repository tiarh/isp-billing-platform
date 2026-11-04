import { expect, test } from '@playwright/test';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

type MenuItem = {
    name: string;
    route: string;
    path: string;
    superOnly?: boolean;
    globalOnly?: boolean;
    duplicateOf?: string;
};

const manifestPath = path.join(__dirname, '..', 'menus.manifest.json');
const menus: MenuItem[] = JSON.parse(fs.readFileSync(manifestPath, 'utf-8'));

type AuditRow = {
    name: string;
    path: string;
    route: string;
    status: 'OK' | 'FAIL' | 'SKIP';
    httpStatus?: number;
    finalUrl?: string;
    error?: string;
    hasErrorBoundary?: boolean;
};

const results: AuditRow[] = [];

test.describe('Menu audit (read-only smoke)', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('/dashboard');
        await expect(page.locator('aside')).toBeVisible();
    });

    for (const menu of menus) {
        test(`menu: ${menu.name}`, async ({ page }) => {
            const row: AuditRow = {
                name: menu.name,
                path: menu.path,
                route: menu.route,
                status: 'OK',
            };

            if (menu.duplicateOf) {
                row.status = 'SKIP';
                row.error = `Duplicate of ${menu.duplicateOf}`;
                results.push(row);
                test.info().annotations.push({ type: 'skip', description: row.error });
                return;
            }

            try {
                const response = await page.goto(menu.path, { waitUntil: 'domcontentloaded' });
                row.httpStatus = response?.status();
                row.finalUrl = page.url();

                // Inertia pages hydrate after domcontentloaded — tunggu shell UI
                const contentLocator = page.locator('main h1, main h2, main .text-2xl, aside + div h2').first();
                await contentLocator.waitFor({ state: 'visible', timeout: 45_000 }).catch(() => {});

                if (response && response.status() >= 400) {
                    row.status = 'FAIL';
                    row.error = `HTTP ${response.status()}`;
                    results.push(row);
                    expect.soft(response.status(), `${menu.name} should not return ${response.status()}`).toBeLessThan(400);
                    return;
                }

                if (page.url().includes('/login')) {
                    row.status = 'FAIL';
                    row.error = 'Redirected to login';
                    results.push(row);
                    expect.soft(page.url(), `${menu.name} should stay authenticated`).not.toContain('/login');
                    return;
                }

                const bodyText = await page.locator('body').innerText();
                const errorPatterns = [
                    /Ziggy error/i,
                    /route .* is not in the route list/i,
                    /Internal Server Error/i,
                    /Whoops/i,
                    /404 Not Found/i,
                    /419 Page Expired/i,
                    /This page isn.?t working/i,
                ];

                if (menu.name === 'Monitoring') {
                    const bodyTextEarly = await page.locator('body').innerText();
                    expect.soft(bodyTextEarly, 'Monitoring harus pakai enterprise shell').toMatch(/Enterprise|Monitoring/i);
                    expect.soft(bodyTextEarly, 'Monitoring tabs NOC').toMatch(/Jaringan|Alerts/i);
                }

                if (menu.name === 'OLT Management') {
                    const bodyOlt = await page.locator('body').innerText();
                    expect.soft(bodyOlt, 'OLT index enterprise shell').toMatch(/Enterprise|Manajemen OLT/i);
                }

                for (const pattern of errorPatterns) {
                    if (pattern.test(bodyText)) {
                        row.status = 'FAIL';
                        row.error = `Matched error pattern: ${pattern}`;
                        break;
                    }
                }

                const hasMain = await page.locator('main, [role="main"], .py-8, h1, h2').first().isVisible().catch(() => false);
                if (!hasMain && row.status === 'OK') {
                    row.status = 'FAIL';
                    row.error = 'No main content detected';
                }

                results.push(row);
                expect.soft(row.status, `${menu.name}: ${row.error || 'unknown error'}`).toBe('OK');
            } catch (error) {
                row.status = 'FAIL';
                row.error = error instanceof Error ? error.message : String(error);
                results.push(row);
                throw error;
            }
        });
    }

    test.afterAll(async () => {
        const reportDir = path.join(__dirname, '..', 'e2e-report');
        fs.mkdirSync(reportDir, { recursive: true });

        const summary = {
            generatedAt: new Date().toISOString(),
            total: results.length,
            ok: results.filter((r) => r.status === 'OK').length,
            fail: results.filter((r) => r.status === 'FAIL').length,
            skip: results.filter((r) => r.status === 'SKIP').length,
            rows: results,
        };

        fs.writeFileSync(path.join(reportDir, 'menu-audit.json'), JSON.stringify(summary, null, 2));

        const md = [
            '# Menu Audit Report',
            '',
            `Generated: ${summary.generatedAt}`,
            '',
            `| Status | Count |`,
            `|--------|-------|`,
            `| OK | ${summary.ok} |`,
            `| FAIL | ${summary.fail} |`,
            `| SKIP | ${summary.skip} |`,
            '',
            `| Menu | Path | HTTP | Status | Note |`,
            `|------|------|------|--------|------|`,
            ...results.map((r) => `| ${r.name} | ${r.path} | ${r.httpStatus ?? '-'} | ${r.status} | ${r.error ?? ''} |`),
        ].join('\n');

        fs.writeFileSync(path.join(reportDir, 'menu-audit.md'), md);
    });
});