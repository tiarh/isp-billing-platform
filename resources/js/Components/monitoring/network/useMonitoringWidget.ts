import { useEffect, useState } from 'react';
import type { MonitoringRange, WidgetEnvelope, WidgetName, WidgetState } from './types';

/** Fetch order when several widgets are waiting: cheapest/most important first. */
const PRIORITY: WidgetName[] = ['summary', 'upstream', 'routers', 'ping', 'resources', 'router-traffic'];
const MAX_CONCURRENT = 3;

type Waiter = { priority: number; seq: number; resume: () => void };

/** Module-wide semaphore: the endpoint is throttled to 60/min/user, so never fire all six at once. */
const limiter = (() => {
    let active = 0;
    let seq = 0;
    const queue: Waiter[] = [];

    const release = () => {
        active -= 1;
        pump();
    };

    const pump = () => {
        while (active < MAX_CONCURRENT && queue.length > 0) {
            queue.sort((a, b) => a.priority - b.priority || a.seq - b.seq);
            const next = queue.shift();
            if (!next) {
                return;
            }
            active += 1;
            next.resume();
        }
    };

    return {
        acquire(widget: WidgetName, signal: AbortSignal): Promise<() => void> {
            return new Promise((resolve, reject) => {
                if (signal.aborted) {
                    reject(new DOMException('Aborted', 'AbortError'));
                    return;
                }
                const waiter: Waiter = {
                    priority: PRIORITY.indexOf(widget),
                    seq: (seq += 1),
                    resume: () => {
                        signal.removeEventListener('abort', onAbort);
                        let released = false;
                        resolve(() => {
                            if (!released) {
                                released = true;
                                release();
                            }
                        });
                    },
                };
                const onAbort = () => {
                    const index = queue.indexOf(waiter);
                    if (index >= 0) {
                        queue.splice(index, 1);
                        reject(new DOMException('Aborted', 'AbortError'));
                    }
                };
                signal.addEventListener('abort', onAbort);
                queue.push(waiter);
                pump();
            });
        },
    };
})();

/** Returns `value` after it has been stable for `delayMs` (the first value is immediate). */
export function useDebouncedValue<V>(value: V, delayMs: number): V {
    const [debounced, setDebounced] = useState(value);

    useEffect(() => {
        if (value === debounced) {
            return undefined;
        }
        const timer = window.setTimeout(() => setDebounced(value), delayMs);

        return () => window.clearTimeout(timer);
    }, [value, debounced, delayMs]);

    return debounced;
}

function emptyState<T>(): WidgetState<T> {
    return { envelope: null, lastGood: null, loading: true, error: null, lastSuccessAt: null };
}

/**
 * Loads one Monitoring widget. Data from the previous response stays on screen
 * while a refresh is in flight and when a refresh fails with 429 / 5xx / network.
 * `tick` changes trigger a refetch (see useAutoRefresh).
 */
export function useMonitoringWidget<T>(widget: WidgetName, range: MonitoringRange, tick = 0): WidgetState<T> {
    const [state, setState] = useState<WidgetState<T>>(() => emptyState<T>());

    useEffect(() => {
        const controller = new AbortController();
        let release: (() => void) | null = null;

        // A new range never shows the previous range's data under the new label:
        // drop it (skeleton) and keep data only across same-range refreshes.
        setState((current) => (current.envelope && current.envelope.range !== range ? emptyState<T>() : { ...current, loading: true }));

        const load = async () => {
            try {
                release = await limiter.acquire(widget, controller.signal);
                const response = await fetch(`${route('api.monitoring.widgets.show', widget)}?range=${encodeURIComponent(range)}`, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    signal: controller.signal,
                });

                if (controller.signal.aborted) {
                    return;
                }

                if (!response.ok) {
                    const error = response.status === 403 ? 'forbidden' : response.status === 429 ? 'throttled' : 'server';
                    setState((current) => ({ ...current, loading: false, error }));

                    return;
                }

                const envelope = (await response.json()) as WidgetEnvelope<T>;
                if (controller.signal.aborted) {
                    return;
                }
                const good = envelope.status === 'ok' || envelope.status === 'stale';
                setState((current) => ({
                    envelope,
                    lastGood: good ? envelope : current.lastGood,
                    lastSuccessAt: good ? (envelope.fetched_at ?? new Date().toISOString()) : current.lastSuccessAt,
                    loading: false,
                    error: null,
                }));
            } catch (error) {
                if (controller.signal.aborted || (error instanceof DOMException && error.name === 'AbortError')) {
                    return;
                }
                setState((current) => ({ ...current, loading: false, error: 'network' }));
            } finally {
                release?.();
            }
        };

        void load();

        return () => controller.abort();
    }, [widget, range, tick]);

    return state;
}
