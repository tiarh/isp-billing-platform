export type Theme = 'dark' | 'light' | 'system';

const STORAGE_KEY = 'theme';

export function getStoredTheme(): Theme {
    if (typeof window === 'undefined') {
        return 'system';
    }

    let saved: string | null = null;
    try {
        saved = localStorage.getItem(STORAGE_KEY);
    } catch {
        // Storage blocked (private mode / site data disabled): follow the OS.
    }
    return saved === 'dark' || saved === 'light' ? saved : 'system';
}

export function resolveIsDark(theme: Theme = getStoredTheme()): boolean {
    if (typeof window === 'undefined') {
        return false;
    }

    if (theme === 'dark') {
        return true;
    }

    if (theme === 'light') {
        return false;
    }

    return window.matchMedia('(prefers-color-scheme: dark)').matches;
}

export function applyTheme(theme: Theme): void {
    if (typeof document === 'undefined') {
        return;
    }

    document.documentElement.classList.toggle('dark', resolveIsDark(theme));
}

export function setTheme(theme: Theme): void {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        if (theme === 'system') {
            localStorage.removeItem(STORAGE_KEY);
        } else {
            localStorage.setItem(STORAGE_KEY, theme);
        }
    } catch {
        // Storage blocked: still apply the theme for this page view.
    }

    applyTheme(theme);
    window.dispatchEvent(new CustomEvent('theme-change', { detail: { theme } }));
}

export function initTheme(): void {
    applyTheme(getStoredTheme());

    if (typeof window === 'undefined') {
        return;
    }

    const media = window.matchMedia('(prefers-color-scheme: dark)');
    const onSystemChange = () => {
        if (getStoredTheme() === 'system') {
            applyTheme('system');
        }
    };

    media.addEventListener('change', onSystemChange);
}