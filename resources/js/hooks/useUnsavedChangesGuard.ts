import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

const DEFAULT_MESSAGE = 'Perubahan belum disimpan. Tinggalkan halaman?';

/** Non-GET visits that still leave the page (POST /logout, also under a prefix). */
const LOGOUT_PATH = /\/logout\/?$/;

/** Protect dirty forms on browser unload and Inertia navigation. */
export function useUnsavedChangesGuard(isDirty: boolean, message = DEFAULT_MESSAGE): void {
    const dirtyRef = useRef(isDirty);

    useEffect(() => {
        dirtyRef.current = isDirty;
    }, [isDirty]);

    useEffect(() => {
        if (typeof window === 'undefined') return;

        const handleBeforeUnload = (event: BeforeUnloadEvent) => {
            if (!dirtyRef.current) return;

            event.preventDefault();
            event.returnValue = '';
        };

        window.addEventListener('beforeunload', handleBeforeUnload);
        const removeBefore = router.on('before', (event) => {
            // Leaving the page (GET navigation or POST logout) can lose edits; a save (PUT/POST/...) must never prompt.
            const { method, url } = event.detail.visit;
            if (method !== 'get' && !LOGOUT_PATH.test(url.pathname)) return;
            if (!dirtyRef.current || window.confirm(message)) return;

            event.preventDefault();
        });

        return () => {
            window.removeEventListener('beforeunload', handleBeforeUnload);
            removeBefore();
        };
    }, [message]);
}
