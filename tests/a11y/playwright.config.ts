import { defineConfig } from '@playwright/test';
import { appFor } from '../e2e/server';

const app = appFor({ database: 'a11y.sqlite', seeder: 'AccessibilitySignedInFlowSeeder', port: 8000, baseUrlVariable: 'A11Y_BASE_URL' });

export default defineConfig({
    testDir: '.',
    testMatch: ['main-flows.spec.ts', 'signed-in-flows.spec.ts'],
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
        // Tall enough for the whole of a page: axe cannot measure text that is partly under the sticky header it was scrolled up to, or that is below the screen.
        viewport: { width: 1280, height: 3000 },
        trace: 'off',
    },
    projects: app.projects,
    webServer: app.webServer,
});
