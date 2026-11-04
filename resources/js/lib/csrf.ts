export function readMetaCsrfToken(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

export function readXsrfCookieToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
    if (! match?.[1]) {
        return '';
    }

    try {
        return decodeURIComponent(match[1]);
    } catch {
        return match[1];
    }
}

export function applyCsrfToken(token: string): void {
    if (! token) {
        return;
    }

    const meta = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]');
    if (meta) {
        meta.content = token;
    }

    (window as any).csrfToken = token;

    if (window.axios) {
        window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token;
    }
}

export function syncCsrfFromPageProps(props: Record<string, unknown> | undefined): void {
    const token = props?.csrf_token;
    if (typeof token === 'string' && token !== '') {
        applyCsrfToken(token);
    }
}

export function setupCsrfRecovery(): void {
    document.addEventListener('inertia:navigate', (event) => {
        const detail = (event as CustomEvent).detail as { page?: { props?: Record<string, unknown> } } | undefined;
        syncCsrfFromPageProps(detail?.page?.props);
    });

    document.addEventListener('inertia:invalid', (event) => {
        const detail = (event as CustomEvent).detail as { response?: { status?: number } } | undefined;
        if (detail?.response?.status === 419) {
            event.preventDefault();
            window.location.reload();
        }
    });
}