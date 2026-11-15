import { expect, test } from '@playwright/test';
import { expectNoProblems } from './axe';
import { adminPages, memberPages } from './pages';
import { signInAndKeep, stateFile } from './session';

/**
 * The pages a signed-in person opens (docs/accessibility.md, the lists are in pages.ts): a member's own pages, and the admin area for an
 * administrator.
 *
 * Every page is run through the same check as the public ones (tests/a11y/axe.ts), after it has finished loading what it asks for.
 */
function signedInAs(who: string, email: string, paths: string[]): void {
    test.describe(`signed in as ${who}`, () => {
        test.use({ storageState: stateFile(who) });

        test.beforeAll(async ({ browser, baseURL }) => signInAndKeep(browser, baseURL, who, email));

        for (const url of paths) {
            test(url, async ({ page }) => {
                const response = await page.goto(url);

                expect(response?.ok(), `${url} returned ${response?.status()}`).toBe(true);
                expect(new URL(page.url()).pathname, `${url} sent the browser somewhere else`).toBe(url);

                // Lists and tables are filled by requests the page makes once it has loaded (a deferred prop, a count).
                await page.waitForLoadState('networkidle');
                await expectNoProblems(page, url);
            });
        }
    });
}

signedInAs('a member', 'avery@example.com', memberPages);
signedInAs('an administrator', 'admin@example.com', adminPages);
