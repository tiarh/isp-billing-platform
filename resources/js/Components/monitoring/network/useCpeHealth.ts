import { useEffect, useState } from 'react';
import type { CpeHealthData, WidgetEnvelope, WidgetState } from './types';

/**
 * Loads the CPE health widget (DB-backed, not range-bound). Like
 * useMonitoringWidget, the previous response stays on screen while a refresh is
 * in flight or fails. `tick` changes trigger a refetch (see useAutoRefresh).
 */
export function useCpeHealth(tick = 0): WidgetState<CpeHealthData> {
    const [state, setState] = useState<WidgetState<CpeHealthData>>({ envelope: null, lastGood: null, loading: true, error: null, lastSuccessAt: null });

    useEffect(() => {
        const controller = new AbortController();
        setState((current) => ({ ...current, loading: true }));

        const load = async () => {
            try {
                const response = await fetch(route('api.monitoring.widgets.cpe'), {
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

                const envelope = (await response.json()) as WidgetEnvelope<CpeHealthData>;
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
            }
        };

        void load();

        return () => controller.abort();
    }, [tick]);

    return state;
}
