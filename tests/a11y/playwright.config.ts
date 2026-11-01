import { defineConfig } from '@playwright/test';
import { appFor } from '../e2e/server';

const app = appFor({ database: 'a11y.sqlite', seeder: 'AccessibilityFlowSeeder', port: 8000, baseUrlVariable: 'A11Y_BASE_URL' });

export default defineConfig({
    testDir: '.',
    testMatch: 'main-flows.spec.ts',
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    retries: 0,
    reporter: 'line',
    timeout: 60_000,
    use: {
        baseURL: app.baseURL,
        ignoreHTTPSErrors: true,
        trace: 'off',
    },
    projects: app.projects,
    webServer: app.webServer,
});
