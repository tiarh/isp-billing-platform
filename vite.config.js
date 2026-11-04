import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import path from 'path';

export default defineConfig(({ mode }) => {
    // Production builds used to ship 200+ .map files (~9 MB) next to the
    // assets. Apache serves them with the same immutable cache header, so
    // the full React/TSX source (comments included) was public. Keep maps
    // for local debugging only.
    const build = {
        sourcemap: mode !== 'production',
        rollupOptions: {
            output: {
                // Stable vendor chunks: the framework code changes far less
                // often than page code, so a deploy no longer invalidates the
                // ~400 KB entry for every user. Pages keep their own chunks via
                // import.meta.glob in resources/js/app.tsx.
                manualChunks: (id) => {
                    if (!id.includes('node_modules')) return undefined;
                    if (/node_modules\/(react|react-dom|scheduler|@inertiajs)\//.test(id)) return 'vendor-react';
                    // Tiny shared helpers (clsx, cva, tailwind-merge) and the
                    // icon set live together: otherwise Rollup folds e.g. clsx
                    // into whichever big chunk also uses it (recharts), and the
                    // login page ends up downloading the whole chart library.
                    // One icons chunk also replaces ~40 one-icon requests.
                    if (/node_modules\/(lucide-react|clsx|tailwind-merge|class-variance-authority)\//.test(id)) return 'vendor-ui';
                    if (/node_modules\/(recharts|d3-|victory-vendor|react-smooth|internmap|delaunator|robust-predicates)\//.test(id)) return 'vendor-charts';
                    if (/node_modules\/(leaflet|react-leaflet|@react-leaflet)\//.test(id)) return 'vendor-map';
                    return undefined;
                },
            },
        },
    };
    const env = loadEnv(mode, process.cwd(), '');
    const devServerHost = env.VITE_DEV_SERVER_HOST || 'localhost';
    const devServerPort = Number(env.VITE_PORT || 5173);

    return {
        build,
        resolve: {
            alias: {
                '@': path.resolve(__dirname, './resources/js'),
            },
        },
        server: {
            host: '0.0.0.0',
            port: devServerPort,
            strictPort: true,
            cors: true,
            origin: `http://${devServerHost}:${devServerPort}`,
            hmr: {
                host: devServerHost,
                port: devServerPort,
            },
        },
        plugins: [
            laravel({
                input: 'resources/js/app.tsx',
                refresh: true,
            }),
            react(),
            tailwindcss(),
        ],
    };
});
