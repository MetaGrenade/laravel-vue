import { expect, type Browser } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { root } from '../e2e/server';

/** The factory's password, for the member and the administrator that AccessibilitySignedInFlowSeeder makes. */
export const password = 'password';

/** Where the browser's cookies are kept once someone has signed in, so that a page does not start with the sign-in form. */
export const stateFile = (who: string) => path.join(root, 'test-results', `a11y-${who.replace(/\W+/g, '-')}.json`);

/** Signs in in a browser of its own and keeps the cookies in `stateFile(who)`, for a block of tests that opens its pages with them. */
export async function signInAndKeep(browser: Browser, baseURL: string | undefined, who: string, email: string): Promise<void> {
    // A browser of its own, signed out: it would otherwise be given the state this saves from it, which is not there yet.
    const context = await browser.newContext({ baseURL, ignoreHTTPSErrors: true, storageState: { cookies: [], origins: [] } });
    const page = await context.newPage();

    await page.goto('/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page).toHaveURL(/\/dashboard$/);

    // The cookie notice is a part of every page until it is answered; the visitor's pages are checked with it answered.
    const banner = page.getByRole('button', { name: 'Necessary only' });

    if (await banner.isVisible()) await banner.click();

    fs.mkdirSync(path.dirname(stateFile(who)), { recursive: true });
    await context.storageState({ path: stateFile(who) });
    await context.close();
}
