import { defineConfig, devices } from '@playwright/test';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const php = process.env.PHP_BIN ?? 'php';
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const baseURL = process.env.A11Y_BASE_URL ?? 'http://127.0.0.1:8000';
const serve = process.env.A11Y_BASE_URL === undefined;

export default defineConfig({
    testDir: '.',
    testMatch: 'main-flows.spec.ts',
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    retries: 0,
    reporter: 'line',
    timeout: 60_000,
    use: {
        baseURL,
        ignoreHTTPSErrors: true,
        trace: 'off',
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
    webServer: serve
        ? {
              command: `node tests/a11y/serve.mjs`,
              cwd: root,
              url: 'http://127.0.0.1:8000',
              reuseExistingServer: false,
              timeout: 180_000,
              env: { ...process.env, PHP_BIN: php },
          }
        : undefined,
});
