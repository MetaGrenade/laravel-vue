import { defineConfig } from '@playwright/test';
import { appFor } from '../e2e/server';
import { VIEWPORT } from './axe';

const app = appFor({ database: 'a11y.sqlite', seeder: 'AccessibilitySignedInFlowSeeder', port: 8000, baseUrlVariable: 'A11Y_BASE_URL' });

export default defineConfig({
    testDir: '.',
    testMatch: ['main-flows.spec.ts', 'signed-in-flows.spec.ts', 'states-flows.spec.ts', 'phone-flows.spec.ts'],
    fullyParallel: false,
    // One browser at a time: the pages are served by PHP's built-in server, which answers one request at a time, from one SQLite file.
    workers: 1,
    forbidOnly: !!process.env.CI,
    retries: 0,
    reporter: 'line',
    timeout: 60_000,
    use: {
        baseURL: app.baseURL,
        ignoreHTTPSErrors: true,
        trace: 'off',
    },
    // The viewport is set on the project, after its device: the project from appFor() spreads `devices['Desktop Chrome']` (1280 by 720) into its own
    // `use`, which wins over a viewport in the top-level one. tests/a11y/axe.ts fails a page if the browser is not this size.
    projects: app.projects.map((project) => ({ ...project, use: { ...project.use, viewport: VIEWPORT } })),
    webServer: app.webServer,
});
