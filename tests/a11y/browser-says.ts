import { test as base, expect } from '@playwright/test';

/**
 * What the browser says about a page, besides what axe reads from it (docs/accessibility.md, "What the browser says"): a console error or
 * warning, an exception nobody caught, a request that failed, an answer of 400 or above (a missing image or script, a request the page makes
 * that the server refuses), and a content security policy violation (Chrome writes those to the console as errors). A page that loads is
 * expected to be silent, so every one of these fails the test that opened it.
 *
 * `test` here is Playwright's with one fixture added that every test has (`auto`): it listens from the first request and, once the test body
 * has finished, waits for the page to go quiet and fails the test with what it heard. Use it in a spec that opens pages and expects them to
 * work. A spec that provokes errors on purpose (a form sent with nothing in it is answered 422, and the browser logs that) imports
 * `test` from '@playwright/test' instead.
 *
 * There is no list of things to ignore. A page that has to make a request that is answered with an error should not make it: say what the
 * server knows (a prop) rather than ask and be refused (the Discord role mappings page did that until this check found it). The one kind of
 * request that is not counted is one the page or the browser cancelled itself (`net::ERR_ABORTED`).
 */
export const test = base.extend<{ browserSaid: void }>({
    browserSaid: [
        async ({ page, baseURL }, use) => {
            const said = new Set<string>();
            const consoleMessages: Array<{ type: string; text: string; url: string }> = [];
            const refused = new Set<string>();
            const site = new URL(baseURL ?? 'http://127.0.0.1').host;

            page.on('console', (message) => {
                if (['error', 'warning'].includes(message.type())) {
                    consoleMessages.push({ type: message.type(), text: message.text().slice(0, 300), url: message.location().url });
                }
            });
            page.on('pageerror', (error) => said.add(`uncaught exception: ${error.message.slice(0, 300)}`));
            page.on('requestfailed', (request) => {
                // The page (or the browser, when the page was left) cancelled it: one visit replaced by the next one, a picture that was no longer wanted.
                if (request.failure()?.errorText === 'net::ERR_ABORTED') return;

                said.add(`request failed: ${request.method()} ${new URL(request.url()).pathname} (${request.failure()?.errorText})`);
            });
            // A page in the check talks to the site and to nothing else. A build made with a Pusher key in .env opens a WebSocket to Pusher's servers
            // from every signed-in page (the check then fails by chance, when a page is left before the connection is made); CI builds without one.
            page.on('websocket', (socket) => {
                const host = new URL(socket.url()).host;

                if (host !== site) {
                    said.add(`opened a connection to ${host}, not to the site (build without a Pusher key for this check: docs/accessibility.md, "Running it")`);
                }
            });
            page.on('response', (response) => {
                if (response.status() < 400) return;

                refused.add(response.url());
                said.add(`answered ${response.status()}: ${response.request().method()} ${new URL(response.url()).pathname}`);
            });

            await use();

            // A request the page makes after it has loaded (a list, a count) is part of what it says.
            await page.waitForLoadState('networkidle').catch(() => undefined);

            for (const message of consoleMessages) {
                // Chrome writes "Failed to load resource" for every answer of 400 or above; the line above says it better.
                if (!refused.has(message.url)) said.add(`console ${message.type}: ${message.text}`);
            }

            expect([...said], `${page.url()}: the browser said something (docs/accessibility.md, "What the browser says")`).toEqual([]);
        },
        { auto: true },
    ],
});

export { expect };
