import { defineConfig, devices } from '@playwright/test';
import { createHash } from 'crypto';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

function loadLocalEnv(): void {
    const envFile = path.join(__dirname, '.env.local');

    if (!fs.existsSync(envFile)) {
        return;
    }

    for (const line of fs.readFileSync(envFile, 'utf8').split(/\r?\n/)) {
        const match = line.match(/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*?)\s*$/);

        if (!match || match[1] in process.env) {
            continue;
        }

        process.env[match[1]] = match[2].replace(/^(['"])(.*)\1$/, '$2');
    }
}

loadLocalEnv();

const configuredBaseURL = process.env.E2E_BASE_URL?.trim();

if (!configuredBaseURL) {
    throw new Error('E2E_BASE_URL is required; use an isolated sandbox URL.');
}

let parsedBaseURL: URL;

try {
    parsedBaseURL = new URL(configuredBaseURL);
} catch {
    throw new Error('E2E_BASE_URL must be a valid http(s) URL.');
}

if (!['http:', 'https:'].includes(parsedBaseURL.protocol)) {
    throw new Error('E2E_BASE_URL must use http or https.');
}

// sha256 of the lowercased production hostnames (no host name in the repo).
const productionHostHashes = new Set([
    'a5d6ee11bb20825ba879533b635e8c1ff69206404404dc0221fb0388f1d74ec9',
    '203609e3f957eae6dca0f4c4e6abe4badb7bc35dfb974d50227755ef6cdb1476',
]);

if (productionHostHashes.has(createHash('sha256').update(parsedBaseURL.hostname.toLowerCase()).digest('hex'))) {
    throw new Error('E2E refuses the production eBilling host; configure a sandbox URL.');
}

export default defineConfig({
    testDir: './tests',
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 1 : 0,
    workers: 1,
    timeout: 60_000,
    reporter: [
        ['list'],
        ['html', { outputFolder: 'e2e-report', open: 'never' }],
        ['json', { outputFile: 'e2e-report/results.json' }],
    ],
    use: {
        baseURL: configuredBaseURL,
        launchOptions: {
            executablePath: process.env.E2E_CHROMIUM_EXECUTABLE_PATH,
        },
        trace: 'on-first-retry',
        screenshot: 'only-on-failure',
        video: 'off',
        actionTimeout: 20_000,
        navigationTimeout: 45_000,
    },
    projects: [
        {
            name: 'setup',
            testMatch: /auth\.setup\.ts/,
        },
        {
            name: 'chromium',
            use: {
                ...devices['Desktop Chrome'],
                storageState: path.join(__dirname, '.auth', 'superadmin.json'),
            },
            dependencies: ['setup'],
        },
    ],
});
