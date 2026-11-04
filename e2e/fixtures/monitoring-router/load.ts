import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import type { Page } from '@playwright/test';

/**
 * Mock for the router detail API (GET /api/monitoring/routers/{id}/overview,
 * /pppoe and /pppoe/daily). Every name here is fictitious (example.test).
 * Kept apart from monitoring-widgets/load.ts on purpose.
 */

const __dirname = path.dirname(fileURLToPath(import.meta.url));

export type MockRouter = 'ok' | 'unavailable';

type Wave = { count: number; stepMinutes: number; base: number; amp: number; period: number; phase: number };

function expand(value: unknown, now: number): unknown {
    if (value === '__now__') {
        return new Date(now).toISOString();
    }
    if (Array.isArray(value)) {
        return value.map((item) => expand(item, now));
    }
    if (value && typeof value === 'object') {
        const record = value as Record<string, unknown>;
        if (record.wave && typeof record.wave === 'object') {
            const wave = record.wave as Wave;

            return Array.from({ length: wave.count }, (_, index) => {
                const ts = now - (wave.count - 1 - index) * wave.stepMinutes * 60_000;
                const v = wave.base + wave.amp * Math.sin((index / wave.period) * Math.PI * 2 + wave.phase);

                return [ts, Math.max(0, Math.round(v * 10) / 10)];
            });
        }

        return Object.fromEntries(Object.entries(record).map(([key, item]) => [key, expand(item, now)]));
    }

    return value;
}

function readFixture(name: string): Record<string, unknown> {
    return JSON.parse(fs.readFileSync(path.join(__dirname, `${name}.json`), 'utf8'));
}

/** 30 WIB days ending today. The oldest days have no data (history starts later), one is partial. */
function dailyPoints(scale: number, now: number) {
    const wib = now + 7 * 3_600_000;

    return Array.from({ length: 30 }, (_, index) => {
        const date = new Date(wib - (29 - index) * 86_400_000).toISOString().slice(0, 10);
        if (index < 9) {
            return { date, download_gb: null, upload_gb: null, quality: 'none' };
        }
        const down = Math.round((scale * (1 + 0.25 * Math.sin(index / 3)) + (index % 5)) * 10) / 10;

        return { date, download_gb: down, upload_gb: Math.round(down * 0.18 * 10) / 10, quality: index === 9 ? 'partial' : 'ok' };
    });
}

const STATUSES = ['online', 'online', 'online', 'online', 'offline', 'unlinked'] as const;

function pppoeRows() {
    return Array.from({ length: 60 }, (_, index) => {
        const status = STATUSES[index % STATUSES.length];
        const online = status !== 'offline';
        const linked = status !== 'unlinked';
        const n = String(index + 1).padStart(3, '0');
        const month = Math.round((120 - index) * 3.7 * 10) / 10;

        return {
            pppoe_user: `user${n}@example.test`,
            name: linked ? `Pelanggan Contoh ${n}` : null,
            customer_id: linked ? 100 + index : null,
            customer_code: linked ? `CUST-${n}` : null,
            status,
            is_online: online,
            isolated: index % 17 === 5,
            download_mbps: online ? Math.round((50 - index * 0.7) * 10) / 10 : null,
            upload_mbps: online ? Math.round((6 - index * 0.08) * 10) / 10 : null,
            today_gb: Math.round(month / 30 * 10) / 10,
            month_gb: month,
            has_invalid_days: false,
            history_partial: true,
            spark7: [3.1, 4.2, 2.8, 5.0, 4.4, 3.6, 4.9].map((v, i) => Math.round((v + ((index + i) % 3)) * 10) / 10),
        };
    });
}

function envelope(mode: MockRouter, range: string, now: number): Record<string, unknown> {
    const raw = readFixture(mode === 'unavailable' ? 'overview.unavailable' : 'overview');
    const expanded = expand(raw, now) as Record<string, any>;
    expanded.data.daily = dailyPoints(560, now);

    return { ...expanded, range };
}

function listEnvelope(mode: MockRouter, url: URL, now: number): Record<string, unknown> {
    const live = mode === 'ok';
    let rows = pppoeRows().map((row) =>
        live ? row : { ...row, status: row.customer_id === null ? 'unlinked' : 'unknown', is_online: false, download_mbps: null, upload_mbps: null },
    );
    const counts = {
        all: rows.length,
        online: rows.filter((row) => row.status === 'online').length,
        offline: rows.filter((row) => row.status === 'offline').length,
        unlinked: rows.filter((row) => row.status === 'unlinked').length,
        unknown: rows.filter((row) => row.status === 'unknown').length,
    };

    const status = url.searchParams.get('status') ?? 'all';
    const q = (url.searchParams.get('q') ?? '').toLowerCase();
    if (status !== 'all') {
        rows = rows.filter((row) => row.status === status);
    }
    if (q !== '') {
        rows = rows.filter((row) => `${row.pppoe_user} ${row.name ?? ''} ${row.customer_code ?? ''}`.toLowerCase().includes(q));
    }

    const perPage = Number(url.searchParams.get('per_page') ?? 50);
    const page = Math.max(1, Number(url.searchParams.get('page') ?? 1));
    const lastPage = Math.max(1, Math.ceil(rows.length / perPage));

    return {
        widget: 'routers',
        range: null,
        status: live ? 'ok' : 'unavailable',
        fetched_at: live ? new Date(now).toISOString() : null,
        reason: live ? null : 'Grafana tidak menjawab (timeout).',
        partial: !live,
        step_seconds: null,
        grafana_dashboard_url: null,
        data: {
            rows: rows.slice((page - 1) * perPage, page * perPage),
            meta: { total: rows.length, page, per_page: perPage, last_page: lastPage, counts, live },
        },
    };
}

/** Intercept the router detail endpoints for this page. Call before page.goto(). */
export async function mockRouterDetail(page: Page, mode: MockRouter): Promise<void> {
    await page.route('**/api/monitoring/routers/*/**', async (route) => {
        const url = new URL(route.request().url());
        const now = Date.now();
        let body: unknown = null;

        if (url.pathname.endsWith('/pppoe/daily')) {
            const user = url.searchParams.get('user') ?? '';
            const row = pppoeRows().find((item) => item.pppoe_user === user);
            const days = dailyPoints(4.2, now);
            const total = days.reduce((sum, day) => sum + (day.download_gb ?? 0), 0);
            body = {
                status: 'ok',
                fetched_at: new Date(now).toISOString(),
                data: {
                    pppoe_user: user,
                    found: true,
                    customer_id: row?.customer_id ?? null,
                    name: row?.name ?? null,
                    days,
                    totals: { download_gb: Math.round(total * 10) / 10, upload_gb: Math.round(total * 0.18 * 10) / 10 },
                    has_invalid_days: false,
                    history_partial: true,
                },
            };
        } else if (url.pathname.endsWith('/pppoe')) {
            body = listEnvelope(mode, url, now);
        } else if (url.pathname.endsWith('/overview')) {
            body = envelope(mode, url.searchParams.get('range') ?? '1h', now);
        }

        if (body === null) {
            await route.fulfill({ status: 404, contentType: 'application/json', body: JSON.stringify({ message: 'Not Found' }) });

            return;
        }
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(body) });
    });
}
