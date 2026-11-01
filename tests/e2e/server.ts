import { devices, type PlaywrightTestConfig } from '@playwright/test';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');

interface Suite {
    /** The file in database/ the suite builds for itself and throws away. */
    database: string;
    seeder: string;
    port: number;
    /** The environment variable that points the suite at a site that is already running, instead of starting one. */
    baseUrlVariable: string;
}

/**
 * What the browser suites share: the address they test, the application started for them (tests/e2e/serve.mjs) and the browser.
 * `E2E_BROWSER_CHANNEL=chrome` runs the Chrome that is installed instead of the Chromium Playwright downloads.
 */
export function appFor(suite: Suite): Pick<PlaywrightTestConfig, 'projects' | 'webServer'> & { baseURL: string } {
    const given = process.env[suite.baseUrlVariable];
    const baseURL = given ?? `http://127.0.0.1:${suite.port}`;

    return {
        baseURL,
        projects: [
            {
                name: 'chromium',
                use: { ...devices['Desktop Chrome'], channel: process.env.E2E_BROWSER_CHANNEL || undefined },
            },
        ],
        webServer:
            given === undefined
                ? {
                      command: 'node tests/e2e/serve.mjs',
                      cwd: root,
                      url: baseURL,
                      reuseExistingServer: false,
                      timeout: 180_000,
                      env: {
                          ...(process.env as Record<string, string>),
                          PHP_BIN: process.env.PHP_BIN ?? 'php',
                          E2E_DATABASE: suite.database,
                          E2E_SEEDER: suite.seeder,
                          E2E_PORT: String(suite.port),
                      },
                  }
                : undefined,
    };
}
