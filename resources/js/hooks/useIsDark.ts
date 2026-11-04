import { useEffect, useState } from 'react';

function htmlIsDark(): boolean {
    return typeof document !== 'undefined' && document.documentElement.classList.contains('dark');
}

/**
 * Tracks the resolved theme (the `.dark` class on <html>), not the OS
 * preference. Use it for third-party widgets that take an explicit theme
 * (sonner, maps); prefer CSS tokens / `dark:` classes everywhere else.
 */
export function useIsDark(): boolean {
    const [isDark, setIsDark] = useState<boolean>(htmlIsDark);

    useEffect(() => {
        const update = () => setIsDark(htmlIsDark());
        update();

        const observer = new MutationObserver(update);
        observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });

        return () => observer.disconnect();
    }, []);

    return isDark;
}
