import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import type { Page } from '@playwright/test';

/**
 * Mock for GET /api/monitoring/widgets/{widget}. All names, IPs and numbers in
 * the JSON next to this file are fictitious (RFC 5737 / example.test).
 *
 * Series can be written as { "wave": { count, stepMinutes, base, amp, period, phase } };
 * they are expanded to [timestamp_ms, value] points ending "now".
 */

const __dirname = path.dirname(fileURLToPath(import.meta.url));

export type MockWidgets = 'ok' | 'unavailable';

const WIDGETS = ['summary', 'routers', 'router-traffic', 'upstream', 'resources', 'ping', 'cpe'] as const;

const EMPTY_DATA: Record<string, unknown> = {
    'router-traffic': { series: [] },
    upstream: { links: [] },
    resources: { rows: [] },
    ping: { targets: [], series: { rtt: [], loss: [] } },
};

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

export function buildWidgetEnvelope(widget: string, range: string, mode: MockWidgets): Record<string, unknown> | null {
    if (!(WIDGETS as readonly string[]).includes(widget)) {
        return null;
    }
    const now = Date.now();

    // CPE health is DB-backed (GenieACS), so it does not depend on Grafana being up.
    if (mode === 'unavailable' && widget !== 'cpe') {
        const hasDbRows = widget === 'summary' || widget === 'routers';
        const envelope = hasDbRows
            ? readFixture(`${widget}.unavailable`)
            : {
                  widget,
                  range,
                  status: 'unavailable',
                  fetched_at: null,
                  reason: 'Grafana tidak menjawab (timeout).',
                  partial: true,
                  step_seconds: 60,
                  grafana_dashboard_url: 'https://grafana.example.test/d/skynet-mikrotik-overview?orgId=1&from=now-1h&to=now',
                  data: EMPTY_DATA[widget],
              };

        return { ...(expand(envelope, now) as Record<string, unknown>), range };
    }

    return { ...(expand(readFixture(widget), now) as Record<string, unknown>), range };
}

/** Intercept the widget endpoint for this page. Call before page.goto(). */
export async function mockMonitoringWidgets(page: Page, mode: MockWidgets): Promise<void> {
    await page.route('**/api/monitoring/widgets/*', async (route) => {
        const url = new URL(route.request().url());
        const widget = decodeURIComponent(url.pathname.split('/').pop() ?? '');
        const body = buildWidgetEnvelope(widget, url.searchParams.get('range') ?? '1h', mode);

        if (body === null) {
            await route.fulfill({ status: 404, contentType: 'application/json', body: JSON.stringify({ message: 'Not Found' }) });

            return;
        }
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(body) });
    });
}
