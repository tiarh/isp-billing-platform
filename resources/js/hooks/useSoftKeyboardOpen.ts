import { useEffect, useState } from 'react';

function isTextEntry(el: Element | null): boolean {
    if (!el) return false;
    const tag = el.tagName;
    if (tag === 'TEXTAREA' || tag === 'SELECT') return true;
    if (tag === 'INPUT') {
        const type = (el as HTMLInputElement).type;
        return !['button', 'submit', 'reset', 'checkbox', 'radio', 'file', 'image', 'range', 'color'].includes(type);
    }
    return (el as HTMLElement).isContentEditable === true;
}

/**
 * Heuristic for "on-screen keyboard is open": a text control has focus and/or the visual
 * viewport shrank below 75% of the layout viewport. Used to hide fixed bottom bars.
 */
export function useSoftKeyboardOpen(): boolean {
    const [open, setOpen] = useState(false);

    useEffect(() => {
        if (typeof window === 'undefined') return;

        let focused = false;
        let shrunk = false;
        const update = () => setOpen(focused || shrunk);

        const onFocusIn = (event: FocusEvent) => {
            focused = isTextEntry(event.target as Element | null);
            update();
        };
        const onFocusOut = (event: FocusEvent) => {
            // Moving between two text inputs: keep the "open" state, no one-frame flash.
            if (isTextEntry(event.relatedTarget as Element | null)) return;
            focused = false;
            update();
        };
        const vv = window.visualViewport;
        const onResize = () => {
            if (!vv) return;
            shrunk = vv.height < window.innerHeight * 0.75;
            update();
        };

        document.addEventListener('focusin', onFocusIn);
        document.addEventListener('focusout', onFocusOut);
        vv?.addEventListener('resize', onResize);
        onResize();

        return () => {
            document.removeEventListener('focusin', onFocusIn);
            document.removeEventListener('focusout', onFocusOut);
            vv?.removeEventListener('resize', onResize);
        };
    }, []);

    return open;
}
