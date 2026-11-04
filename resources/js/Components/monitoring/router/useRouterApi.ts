import { useEffect, useMemo, useState } from 'react';
import type { WidgetEnvelope, WidgetState, WidgetStatus } from '@/Components/monitoring/network/types';

/**
 * Loads one router-detail endpoint into a WidgetState, so WidgetFrame can be
 * reused. Data from the previous response stays on screen while a refresh is in
 * flight and when it fails with 429 / 5xx / network. Endpoints without a status
 * (the daily history) count as "ok".
 */

const MAX_CONCURRENT = 2;

type Release = () => void;

const limiter = (() => {
    let active = 0;
    const queue: Array<() => void> = [];

    const pump = () => {
        while (active < MAX_CONCURRENT && queue.length > 0) {
            active += 1;
            queue.shift()?.();
        }
    };

    return {
        acquire(signal: AbortSignal): Promise<Release> {
            return new Promise((resolve, reject) => {
                if (signal.aborted) {
                    reject(new DOMException('Aborted', 'AbortError'));

                    return;
                }
                const start = () => {
                    signal.removeEventListener('abort', onAbort);
                    let released = false;
                    resolve(() => {
                        if (!released) {
                            released = true;
                            active -= 1;
                            pump();
                        }
                    });
                };
                const onAbort = () => {
                    const index = queue.indexOf(start);
                    if (index >= 0) {
                        queue.splice(index, 1);
                        reject(new DOMException('Aborted', 'AbortError'));
                    }
                };
                signal.addEventListener('abort', onAbort);
                queue.push(start);
                pump();
            });
        },
    };
})();

export type QueryParams = Record<string, string | number | null | undefined>;

export function buildQuery(params: QueryParams): string {
    const search = new URLSearchParams();
    for (const [key, value] of Object.entries(params)) {
        if (value !== null && value !== undefined && value !== '') {
            search.set(key, String(value));
        }
    }
    const text = search.toString();

    return text === '' ? '' : `?${text}`;
}

interface Options {
    /** Skip fetching (e.g. a closed drawer). */
    enabled?: boolean;
    /** Keep showing the old data while a different query loads (paging, filters). */
    keepOnChange?: boolean;
}

function empty<T>(loading: boolean): WidgetState<T> {
    return { envelope: null, lastGood: null, loading, error: null, lastSuccessAt: null };
}

export function useRouterApi<T>(url: string, params: QueryParams, tick = 0, { enabled = true, keepOnChange = false }: Options = {}): WidgetState<T> {
    const query = useMemo(() => buildQuery(params), [params]);
    const key = `${url}${query}`;
    const [state, setState] = useState<{ key: string; value: WidgetState<T> }>(() => ({ key, value: empty<T>(enabled) }));

    useEffect(() => {
        if (!enabled) {
            return undefined;
        }
        const controller = new AbortController();
        let release: Release | null = null;

        setState((current) => {
            if (current.key === key) {
                return { key, value: { ...current.value, loading: true } };
            }

            return { key, value: keepOnChange ? { ...current.value, loading: true } : empty<T>(true) };
        });

        const patch = (change: (value: WidgetState<T>) => WidgetState<T>) => setState((current) => (current.key === key ? { key, value: change(current.value) } : current));

        const load = async () => {
            try {
                release = await limiter.acquire(controller.signal);
                const response = await fetch(`${url}${query}`, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    signal: controller.signal,
                });
                if (controller.signal.aborted) {
                    return;
                }
                if (!response.ok) {
                    const error = response.status === 403 ? 'forbidden' : response.status === 429 ? 'throttled' : 'server';
                    patch((value) => ({ ...value, loading: false, error }));

                    return;
                }

                const body = (await response.json()) as Partial<WidgetEnvelope<T>> & { data: T };
                if (controller.signal.aborted) {
                    return;
                }
                const status: WidgetStatus = body.status ?? 'ok';
                const envelope: WidgetEnvelope<T> = {
                    widget: 'routers',
                    range: body.range ?? null,
                    status,
                    fetched_at: body.fetched_at ?? null,
                    reason: body.reason ?? null,
                    partial: body.partial ?? false,
                    step_seconds: body.step_seconds ?? null,
                    grafana_dashboard_url: body.grafana_dashboard_url ?? null,
                    data: body.data,
                };
                const good = status === 'ok' || status === 'stale';
                patch((value) => ({
                    envelope,
                    lastGood: good ? envelope : value.lastGood,
                    lastSuccessAt: good ? (envelope.fetched_at ?? new Date().toISOString()) : value.lastSuccessAt,
                    loading: false,
                    error: null,
                }));
            } catch (error) {
                if (controller.signal.aborted || (error instanceof DOMException && error.name === 'AbortError')) {
                    return;
                }
                patch((value) => ({ ...value, loading: false, error: 'network' }));
            } finally {
                release?.();
            }
        };

        void load();

        return () => controller.abort();
        // eslint-disable-next-line react-hooks/exhaustive-deps -- `key` already encodes url + query
    }, [key, tick, enabled, keepOnChange]);

    return state.key === key || keepOnChange ? state.value : empty<T>(enabled);
}
