import { defineConfig } from '@playwright/test';
import path from 'node:path';
import { appFor, root } from './server';

const app = appFor({ database: 'smoke.sqlite', seeder: 'SmokeFlowSeeder', port: 8100, baseUrlVariable: 'E2E_BASE_URL' });

export default defineConfig({
    testDir: '.',
    testMatch: '*.spec.ts',
    // The tests share one site and one database; each makes the records it needs and signs in for itself, so they do not depend on order.
    fullyParallel: false,
    workers: 1,
    forbidOnly: !!process.env.CI,
    retries: 0,
    reporter: process.env.CI ? [['line'], ['html', { open: 'never', outputFolder: path.join(root, 'playwright-report') }]] : 'line',
    // Traces and screenshots of a failed test; the folders are git-ignored at the root.
    outputDir: path.join(root, 'test-results/e2e'),
    timeout: 60_000,
    expect: { timeout: 15_000 },
    use: {
        baseURL: app.baseURL,
        ignoreHTTPSErrors: true,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: app.projects,
    webServer: app.webServer,
});
