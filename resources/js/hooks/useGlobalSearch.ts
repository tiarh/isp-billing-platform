import { useCallback, useEffect, useRef, useState } from 'react';
import axios from 'axios';

export type SearchBadgeTone = 'up' | 'down' | 'degraded' | 'info' | 'neutral' | 'accent';

export interface GlobalSearchItem {
    id: string;
    type: 'customer' | 'invoice' | 'ticket' | 'router' | 'olt' | 'area';
    title: string;
    subtitle: string;
    badge: { label: string; tone: SearchBadgeTone } | null;
    href: string;
}

export interface GlobalSearchGroup {
    key: string;
    label: string;
    items: GlobalSearchItem[];
}

export type GlobalSearchError = 'failed' | 'throttled' | null;

export const GLOBAL_SEARCH_MIN_CHARS = 2;
const DEBOUNCE_MS = 200;
const ALLOWED = /^[\p{L}\p{N}\s\-._@+]+$/u;

/**
 * Debounced server search. No history, no storage: results live only in memory.
 * `enabled` is false while an IME composition is in progress.
 */
export function useGlobalSearch(query: string, enabled: boolean, retryKey = 0) {
    const [groups, setGroups] = useState<GlobalSearchGroup[]>([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<GlobalSearchError>(null);
    const [searchedFor, setSearchedFor] = useState('');
    const controllerRef = useRef<AbortController | null>(null);

    const reset = useCallback(() => {
        controllerRef.current?.abort();
        setGroups([]);
        setLoading(false);
        setError(null);
        setSearchedFor('');
    }, []);

    useEffect(() => {
        const q = query.trim();

        if (!enabled) {
            return;
        }
        if (q.length < GLOBAL_SEARCH_MIN_CHARS || q.length > 64 || !ALLOWED.test(q)) {
            reset();
            return;
        }

        const timer = window.setTimeout(async () => {
            controllerRef.current?.abort();
            const controller = new AbortController();
            controllerRef.current = controller;
            setLoading(true);
            setError(null);
            try {
                const res = await axios.post(route('api.global-search'), { q }, { signal: controller.signal });
                setGroups((res.data?.groups ?? []) as GlobalSearchGroup[]);
                setSearchedFor(q);
            } catch (e) {
                if (axios.isCancel(e)) {
                    return;
                }
                setError(axios.isAxiosError(e) && e.response?.status === 429 ? 'throttled' : 'failed');
            } finally {
                if (controllerRef.current === controller) {
                    setLoading(false);
                }
            }
        }, DEBOUNCE_MS);

        return () => window.clearTimeout(timer);
    }, [query, enabled, retryKey, reset]);

    useEffect(() => () => controllerRef.current?.abort(), []);

    return { groups, loading, error, searchedFor, reset };
}
