import '@fontsource-variable/instrument-sans/wght.css';
import '../css/app.css';
import './bootstrap';

import { initTheme } from '@/lib/theme';
import { setupCsrfRecovery, syncCsrfFromPageProps } from '@/lib/csrf';
import { createInertiaApp } from '@inertiajs/react';

initTheme();
setupCsrfRecovery();
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

const reloadAfterAssetError = () => {
    const key = 'skynet:asset-reload-attempted';
    if (sessionStorage.getItem(key)) return;
    sessionStorage.setItem(key, '1');
    window.location.reload();
};

window.addEventListener('vite:preloadError', reloadAfterAssetError);
window.addEventListener('unhandledrejection', (event) => {
    const message = String(event.reason?.message ?? event.reason ?? '');
    if (message.includes('Failed to fetch dynamically imported module') || message.includes('Importing a module script failed')) {
        reloadAfterAssetError();
    }
});

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.tsx`,
            import.meta.glob('./Pages/**/*.tsx'),
        ),
    setup({ el, App, props }) {
        syncCsrfFromPageProps(props.initialPage?.props as Record<string, unknown> | undefined);
        const root = createRoot(el);

        root.render(<App {...props} />);
    },
    progress: {
        // Theme token: the fixed gray-600 bar was barely visible on the dark shell.
        color: 'var(--color-primary)',
    },
});
