import { expect, test, type Browser, type Page } from '@playwright/test';
import { createHmac } from 'node:crypto';

/**
 * The paths a person takes through the site, driven in a browser (docs/e2e-tests.md): what the feature tests cannot show, that the pages
 * the server sends and the scripts that run in them work together. The records come from SmokeFlowSeeder.
 *
 * Each test makes what it needs and signs in for itself, so none depends on another. What a test checks after it has typed something is
 * the saved record (a post, a comment, a ticket in the list), never the text it typed: that is still in the box if nothing was sent.
 */
const password = 'password';
const member = { email: 'avery@example.com', nickname: 'Avery Cole' };
const admin = { email: 'admin@example.com', nickname: 'Site Admin' };

/** A name that is new for every run, so a site that is kept between runs never says "already taken". */
const unique = (prefix: string) => `${prefix} ${Date.now().toString(36)}`;

async function chooseNecessaryCookiesOnly(page: Page): Promise<void> {
    const banner = page.getByRole('button', { name: 'Necessary only' });

    if (await banner.isVisible()) {
        await banner.click();
        await expect(banner).toBeHidden();
    }
}

async function signIn(page: Page, email: string): Promise<void> {
    await page.goto('/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
    await chooseNecessaryCookiesOnly(page);
}

async function signOut(page: Page): Promise<void> {
    await page.getByRole('button', { name: 'Account menu' }).click();
    await page.getByRole('menuitem', { name: 'Log out' }).click();
    await expect(page.getByRole('button', { name: 'Account menu' })).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Log in' }).first()).toBeVisible();
}

/**
 * A member of its own, made through the registration form and left signed in on this page: a test that changes how a member signs in
 * (two-factor, the password) must not touch the members the other tests use. Returns the address, to sign in with from another browser.
 */
async function registerMember(page: Page, prefix: string): Promise<string> {
    const nickname = unique(prefix);
    const email = `${nickname.toLowerCase().replace(/\s+/g, '-')}@example.com`;

    await page.goto('/register');
    await chooseNecessaryCookiesOnly(page);
    await page.getByLabel('Nickname').fill(nickname);
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByLabel('Confirm password').fill(password);
    await page.getByRole('button', { name: 'Create account' }).click();
    await expect(page.getByRole('button', { name: 'Account menu' })).toBeVisible();

    return email;
}

/** A second browser: its own cookies and so its own session, signed in as the member and left on the security page. */
async function signInFromAnotherBrowser(browser: Browser, baseURL: string | undefined, email: string): Promise<Page> {
    const context = await browser.newContext({ baseURL, ignoreHTTPSErrors: true });
    const other = await context.newPage();

    await signIn(other, email);
    await other.goto('/settings/security');
    await expect(other.getByRole('button', { name: 'Sign out other browsers' })).toBeVisible();

    return other;
}

/** The rich text editor is an editable region, not a form field, so it has no label to look it up by. */
async function typeInEditor(page: Page, text: string): Promise<void> {
    const editor = page.locator('.tiptap[contenteditable="true"]').first();

    await editor.click();
    await page.keyboard.type(text);
}

/** What an authenticator app shows for a secret (RFC 6238: a base32 secret, SHA-1, 30 seconds, 6 digits), to finish setting two-factor up. */
function authenticatorCode(secret: string): string {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    const bits = [...secret.toUpperCase().replace(/[^A-Z2-7]/g, '')].map((char) => alphabet.indexOf(char).toString(2).padStart(5, '0')).join('');
    const key = Buffer.from((bits.match(/.{8}/g) ?? []).map((byte) => parseInt(byte, 2)));
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 1000 / 30)));

    const hash = createHmac('sha1', key).update(counter).digest();
    const offset = hash[hash.length - 1] & 0x0f;

    return String(hash.readUInt32BE(offset) & 0x7fffffff)
        .slice(-6)
        .padStart(6, '0');
}

/**
 * Starts a two-factor setup and leaves the page showing the secret and the box for the code. Starting one asks for the password first
 * (the site sends the page that asks and then the page the member came from), so the button is pressed twice.
 */
async function startTwoFactorSetup(page: Page): Promise<void> {
    await page.goto('/settings/security');
    await page.getByRole('button', { name: 'Enable multi-factor authentication' }).click();
    await expect(page).toHaveURL(/\/confirm-password$/);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: 'Confirm password' }).click();
    await expect(page).toHaveURL(/\/settings\/security$/);
    await page.getByRole('button', { name: 'Enable multi-factor authentication' }).click();
    await expect(page.getByLabel('Verification code')).toBeVisible();
}

/**
 * Signs out and in again as a member who has two-factor on, and leaves the page on the security settings. The new session has not given
 * the password, so whatever asks for it asks again (what was given to set two-factor up is good for three hours). The code is the one
 * an authenticator app would show for the secret.
 */
async function signInAgainWithTwoFactor(page: Page, email: string, secret: string): Promise<void> {
    await signOut(page);
    await page.goto('/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page).toHaveURL(/\/two-factor-challenge$/);
    await page.getByLabel('Authentication code').fill(authenticatorCode(secret));
    await page.getByRole('button', { name: 'Continue' }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
    await page.goto('/settings/security');
}

test.describe('visitors', () => {
    test('the home page, the shop, the forum and the blog open and link to each other', async ({ page }) => {
        await page.goto('/');
        await chooseNecessaryCookiesOnly(page);
        await expect(page).toHaveTitle(/MetaForger/);

        await page.getByRole('link', { name: 'Shop', exact: true }).first().click();
        await expect(page).toHaveURL(/\/shop$/);
        await expect(page.getByRole('link', { name: /Hoodie/ }).first()).toBeVisible();

        await page.goto('/forum');
        await page.getByRole('link', { name: 'Announcements' }).first().click();
        await expect(page).toHaveURL(/\/forum\/announcements$/);
        await page.getByRole('link', { name: 'Welcome' }).first().click();
        await expect(page.getByRole('heading', { name: 'Welcome', level: 1 })).toBeVisible();
        await expect(page.getByText('Hello from the forum.')).toBeVisible();

        await page.goto('/blogs');
        await page.getByRole('link', { name: 'A first post' }).first().click();
        await expect(page).toHaveURL(/\/blogs\/a-first-post$/);
        await expect(page.getByText('The post itself.')).toBeVisible();
    });

    test('a blog post is shown with the text styles of the editor, so a list has its bullets', async ({ page }) => {
        await page.goto('/blogs/a-first-post');

        // Tailwind takes the bullets off every list. The rich text styles (.tiptap) put them back; a post without them is a column of lines.
        await expect(page.getByRole('listitem').filter({ hasText: 'A first point' })).toHaveCSS('list-style-type', 'disc');
    });

    test('in a blog post the headings step down in size under the page title, and the bar beside a quote can be seen, in light and dark', async ({
        page,
    }) => {
        for (const colorScheme of ['light', 'dark'] as const) {
            await page.emulateMedia({ colorScheme });
            await page.goto('/blogs/a-first-post');

            const text = page.locator('.tiptap', { has: page.getByRole('heading', { name: 'A first section' }) });
            const measured = await text.evaluate((root) => {
                const sizeOf = (element: Element | null) => parseFloat(getComputedStyle(element!).fontSize);
                const canvas = document.createElement('canvas');
                canvas.width = canvas.height = 1;
                const context = canvas.getContext('2d', { willReadFrequently: true })!;

                /** The colour that results from painting these on top of each other, as red, green, blue and alpha. */
                const paint = (...colours: string[]) => {
                    context.clearRect(0, 0, 1, 1);
                    for (const colour of colours) {
                        context.fillStyle = colour;
                        context.fillRect(0, 0, 1, 1);
                    }

                    return [...context.getImageData(0, 0, 1, 1).data];
                };
                const luminance = ([red, green, blue]: number[]) => {
                    const channel = (value: number) => (value / 255 <= 0.03928 ? value / 255 / 12.92 : ((value / 255 + 0.055) / 1.055) ** 2.4);

                    return 0.2126 * channel(red) + 0.7152 * channel(green) + 0.0722 * channel(blue);
                };

                // What the bar is drawn on: the first thing behind the text that is not see-through.
                let surface = 'rgb(255, 255, 255)';
                for (let node: Element | null = root; node; node = node.parentElement) {
                    const colour = getComputedStyle(node).backgroundColor;
                    if (paint(colour)[3] === 255) {
                        surface = colour;
                        break;
                    }
                }
                const bar = getComputedStyle(root.querySelector('blockquote')!).borderLeftColor;
                const [lighter, darker] = [luminance(paint(surface)), luminance(paint(surface, bar))].sort((a, b) => b - a);

                return {
                    title: sizeOf(document.querySelector('h1')),
                    h2: sizeOf(root.querySelector('h2')),
                    h3: sizeOf(root.querySelector('h3')),
                    paragraph: sizeOf(root.querySelector('p')),
                    contrast: (lighter + 0.05) / (darker + 0.05),
                };
            });

            // Sizes: the page title, then the headings in the text, then the text. Equal sizes were what showed a heading as a bold line.
            expect(measured.title, `${colorScheme}: the page title is larger than a heading in the text`).toBeGreaterThan(measured.h2);
            expect(measured.h2, `${colorScheme}: h2 is larger than h3`).toBeGreaterThan(measured.h3);
            expect(measured.h3, `${colorScheme}: h3 is larger than the text`).toBeGreaterThan(measured.paragraph);
            // 3 to 1 is what WCAG asks of a mark that is needed to see what something is; the old bar was 1.05 to 1 in light mode.
            expect(measured.contrast, `${colorScheme}: the quote bar against what it is drawn on`).toBeGreaterThanOrEqual(3);
        }
    });

    test('the search box finds a post by its title and opens it', async ({ page }) => {
        await page.goto('/');
        await chooseNecessaryCookiesOnly(page);

        await page.getByRole('button', { name: /Search…/ }).click();
        await page.getByPlaceholder('Search blogs, forum threads, and FAQs').fill('first');
        await page
            .getByRole('link', { name: /A first post/ })
            .first()
            .click();

        await expect(page).toHaveURL(/\/blogs\/a-first-post$/);
    });

    test('the search page lists what matches', async ({ page }) => {
        await page.goto('/search/results?q=welcome');

        await expect(page.getByRole('link', { name: /Welcome/ }).first()).toBeVisible();
    });

    test('the cookie banner is answered once and stays answered', async ({ page }) => {
        await page.goto('/');
        const banner = page.getByRole('button', { name: 'Necessary only' });

        await expect(banner).toBeVisible();
        await banner.click();
        await expect(banner).toBeHidden();

        await page.reload();
        await expect(page.getByRole('link', { name: 'Log in' }).first()).toBeVisible();
        await expect(banner).toBeHidden();
    });

    test('the cookie banner does not cover the end of the page, on a phone either', async ({ page }) => {
        // The banner is fixed to the bottom of the window and grows when the screen is narrow, so this is where a fixed-height gap fails.
        await page.setViewportSize({ width: 375, height: 812 });
        await page.goto('/');

        const banner = page.getByRole('region', { name: /Necessary cookies/ });
        await expect(banner).toBeVisible();

        await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));

        const footer = await page.locator('footer').last().boundingBox();
        const covering = await banner.boundingBox();

        expect(footer).not.toBeNull();
        expect(covering).not.toBeNull();
        // Nothing of the footer is under the banner: the footer ends where the banner begins, or above it.
        expect(footer!.y + footer!.height).toBeLessThanOrEqual(covering!.y + 1);
    });

    test('an address that is not there answers 404 on a page of the site', async ({ page }) => {
        const response = await page.goto('/forum/announcements/there-is-no-such-thread');

        expect(response?.status()).toBe(404);
        await expect(page.getByText('404').first()).toBeVisible();
        await expect(page.getByRole('link', { name: /home/i }).first()).toBeVisible();
    });
});

test.describe('accounts', () => {
    test('a visitor registers, is signed in, and can sign out and back in', async ({ page }) => {
        const nickname = unique('Newcomer');
        const email = `${nickname.toLowerCase().replace(/\s+/g, '-')}@example.com`;

        await page.goto('/register');
        await chooseNecessaryCookiesOnly(page);
        await page.getByLabel('Nickname').fill(nickname);
        await page.getByLabel('Email address').fill(email);
        await page.getByLabel('Password', { exact: true }).fill(password);
        await page.getByLabel('Confirm password').fill(password);
        await page.getByRole('button', { name: 'Create account' }).click();

        await expect(page).not.toHaveURL(/\/register$/);
        await expect(page.getByRole('button', { name: 'Account menu' })).toBeVisible();

        await signOut(page);
        await signIn(page, email);
        await expect(page.getByRole('button', { name: 'Account menu' })).toBeVisible();
    });

    test('a member signs in, sees the dashboard and signs out', async ({ page }) => {
        await signIn(page, member.email);
        await expect(page.getByRole('button', { name: 'Account menu' })).toBeVisible();

        await signOut(page);
    });

    test('a wrong password is refused and says so', async ({ page }) => {
        await page.goto('/login');
        await chooseNecessaryCookiesOnly(page);
        await page.getByLabel('Email address').fill(member.email);
        await page.getByLabel('Password', { exact: true }).fill('not-the-password');
        await page.getByRole('button', { name: 'Log in' }).click();

        await expect(page).toHaveURL(/\/login$/);
        await expect(page.getByText(/do not match|credentials/i)).toBeVisible();
        await expect(page.getByRole('button', { name: 'Account menu' })).toHaveCount(0);
    });

    test('the sign-in pages show their words, not the names of language keys, signed in or not', async ({ page }) => {
        // The words come with the page, from a language group that only these routes send (docs/i18n.md). A page that was not sent them
        // shows `auth.login.title` where its heading should be.
        const pages = [
            { path: '/login', heading: 'Log in to your account' },
            { path: '/register', heading: 'Create an account' },
            { path: '/forgot-password', heading: 'Forgot password' },
        ];

        for (const { path, heading } of pages) {
            await page.goto(path);
            await expect(page.getByRole('heading', { name: heading, level: 1 })).toBeVisible();
            await expect(page.getByRole('link', { name: 'Back to site' })).toBeVisible();
            expect(await page.locator('body').innerText(), `${path} shows a language key`).not.toMatch(/\b(?:auth|ui)\.[a-z_]+\.[a-z_]+/);
        }

        // The pages for a signed-in member are on another group of routes, which asks for the words too.
        await signIn(page, member.email);
        await page.goto('/confirm-password');
        await expect(page.getByRole('heading', { name: 'Confirm your password', level: 1 })).toBeVisible();
        await page.getByLabel('Password').fill(password);
        await page.getByRole('button', { name: 'Confirm password' }).click();
        await expect(page).not.toHaveURL(/\/confirm-password$/);
    });
});

test.describe('members', () => {
    test('the settings pages and the header show their words, not the names of language keys', async ({ page }) => {
        // The frame of the settings pages (docs/i18n.md) is sent by the routes of those pages only, and the header's words go with every
        // page. A page that was not sent them shows `settings.title` where its heading should be.
        await signIn(page, member.email);

        const pages = [
            '/settings/profile',
            '/settings/password',
            '/settings/security',
            '/settings/api-keys',
            '/settings/notifications',
            '/settings/billing',
            '/settings/addresses',
        ];

        for (const path of pages) {
            await page.goto(path);
            await expect(page.getByRole('heading', { name: 'Settings', level: 1 })).toBeVisible();
            await expect(page.getByRole('navigation', { name: 'Settings sections' }).last().getByRole('link', { name: 'Profile' })).toBeVisible();
            expect(await page.locator('body').innerText(), `${path} shows a language key`).not.toMatch(/\b(?:ui|settings)\.[a-z_]+(?:\.[a-z_]+)?\b/);
        }

        await page.getByRole('button', { name: 'Account menu' }).click();
        await expect(page.getByRole('menuitem', { name: 'Settings' })).toBeVisible();
        await page.keyboard.press('Escape');

        await page.getByRole('button', { name: 'Open cart' }).click();
        await expect(page.getByRole('dialog').getByText('Your cart', { exact: true })).toBeVisible();
    });

    test('turning two-factor on asks for the password first, and nothing starts without it', async ({ page }) => {
        // Turning it on from a browser that was left signed in would put somebody else's authenticator on the account, so it asks.
        await registerMember(page, 'Starter');

        await page.goto('/settings/security');
        await expect(page.getByTestId('setup-asks-for-password')).toBeVisible();
        await page.getByRole('button', { name: 'Enable multi-factor authentication' }).click();
        await expect(page).toHaveURL(/\/confirm-password$/);
        await page.getByLabel('Password').fill('wrong-password');
        await page.getByRole('button', { name: 'Confirm password' }).click();
        await expect(page.getByText('The provided password is incorrect.')).toBeVisible();

        // Nothing was started: the page the member goes back to offers the button, and has no secret and no box for a code.
        await page.goto('/settings/security');
        await expect(page.getByRole('button', { name: 'Enable multi-factor authentication' })).toBeVisible();
        await expect(page.getByLabel('Verification code')).toHaveCount(0);

        // With the password it comes back to the same page, and still has not started: the button does that.
        await page.getByRole('button', { name: 'Enable multi-factor authentication' }).click();
        await expect(page).toHaveURL(/\/confirm-password$/);
        await page.getByLabel('Password').fill(password);
        await page.getByRole('button', { name: 'Confirm password' }).click();
        await expect(page).toHaveURL(/\/settings\/security$/);
        await expect(page.getByLabel('Verification code')).toHaveCount(0);
        await page.getByRole('button', { name: 'Enable multi-factor authentication' }).click();
        await expect(page.getByLabel('Verification code')).toBeVisible();
        await expect(page.locator('input[readonly]').first()).not.toHaveValue('');

        // Cancelling a setup that was never confirmed is one click and asks for nothing.
        await page.getByRole('button', { name: 'Cancel' }).click();
        await expect(page.getByRole('button', { name: 'Enable multi-factor authentication' })).toBeVisible();
        await expect(page).toHaveURL(/\/settings\/security$/);
    });

    test('turning two-factor off asks for the password first, and the page does it once it is given', async ({ page }) => {
        // A member of its own: with two-factor on every sign-in asks for a code, and a test that failed half way must not leave that on the
        // member the other tests sign in as.
        const email = await registerMember(page, 'Careful');

        await startTwoFactorSetup(page);
        const secret = await page.locator('input[readonly]').first().inputValue();
        await page.getByLabel('Verification code').fill(authenticatorCode(secret));
        await page.getByRole('button', { name: 'Confirm setup' }).click();
        await expect(page.getByText('Multi-factor authentication is enabled')).toBeVisible();

        // Setting it up was given the password, which is good for three hours: come back as a member who has not given it.
        await signInAgainWithTwoFactor(page, email, secret);
        await expect(page.getByText('Multi-factor authentication is enabled')).toBeVisible();

        // The button does not switch it off: the site sends the page that asks for the password, and two-factor stays on behind it.
        const disable = page.getByRole('button', { name: 'Disable multi-factor authentication' });
        await disable.click();
        await expect(page).toHaveURL(/\/confirm-password$/);
        await page.getByLabel('Password').fill('wrong-password');
        await page.getByRole('button', { name: 'Confirm password' }).click();
        await expect(page).toHaveURL(/\/confirm-password$/);
        await expect(page.getByText('The provided password is incorrect.')).toBeVisible();

        await page.getByLabel('Password').fill(password);
        await page.getByRole('button', { name: 'Confirm password' }).click();

        // Back on the page the member came from, still on; now the same button does it.
        await expect(page).toHaveURL(/\/settings\/security$/);
        await expect(page.getByText('Multi-factor authentication is enabled')).toBeVisible();
        await disable.click();
        await expect(page.getByRole('button', { name: 'Enable multi-factor authentication' })).toBeVisible();
        await expect(page).toHaveURL(/\/settings\/security$/);
    });

    test('the recovery codes come once, and again only after the password is given', async ({ page }) => {
        // A member of its own, as in the test above.
        const email = await registerMember(page, 'Keeper');

        await startTwoFactorSetup(page);
        const secret = await page.locator('input[readonly]').first().inputValue();
        await page.getByLabel('Verification code').fill(authenticatorCode(secret));
        await page.getByRole('button', { name: 'Confirm setup' }).click();
        await expect(page.getByText('Multi-factor authentication is enabled')).toBeVisible();

        const card = page.getByTestId('recovery-codes');
        const codes = card.locator('li > span:first-child');
        const shownCodes = async () => (await codes.allInnerTexts()).map((code) => code.trim());

        // Right after setup the eight codes are on the page, with the words that say they are shown only now.
        await expect(codes).toHaveCount(8);
        const first = await shownCodes();
        expect(first.every((code) => /^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/.test(code))).toBe(true);
        await expect(card.getByText('they are shown only this once')).toBeVisible();

        // Loading the page again hides them, and they are not in the page source either: only how many are left.
        await page.reload();
        await expect(card.getByTestId('recovery-codes-left')).toContainText('8 recovery codes are left');
        await expect(codes).toHaveCount(0);
        expect(await page.content(), 'the page source after a reload').not.toContain(first[0]);

        // Setting it up was given the password, which is good for three hours: come back as a member who has not given it.
        await signInAgainWithTwoFactor(page, email, secret);

        // Asking for them asks for the password first, and a wrong one shows nothing.
        await card.getByRole('button', { name: 'Show recovery codes' }).click();
        await expect(page).toHaveURL(/\/confirm-password$/);
        await page.getByLabel('Password').fill('wrong-password');
        await page.getByRole('button', { name: 'Confirm password' }).click();
        await expect(page.getByText('The provided password is incorrect.')).toBeVisible();
        await page.getByLabel('Password').fill(password);
        await page.getByRole('button', { name: 'Confirm password' }).click();

        // Then it comes back to the security page with the same eight, for that one load.
        await expect(page).toHaveURL(/\/settings\/security$/);
        await expect(codes).toHaveCount(8);
        expect(await shownCodes()).toEqual(first);
        await page.reload();
        await expect(codes).toHaveCount(0);

        // The password was given a moment ago, so now it goes straight there; "Hide" puts them away without leaving the page.
        await card.getByRole('button', { name: 'Show recovery codes' }).click();
        await expect(codes).toHaveCount(8);
        await card.getByRole('button', { name: 'Hide recovery codes' }).click();
        await expect(codes).toHaveCount(0);
        await expect(card.getByTestId('recovery-codes-left')).toBeVisible();
        await expect(page).toHaveURL(/\/settings\/security$/);

        // A new set is shown once as well, and it is not the old one.
        await card.getByRole('button', { name: 'Generate new recovery codes' }).click();
        await expect(codes).toHaveCount(8);
        expect(await shownCodes()).not.toEqual(first);
        await page.reload();
        await expect(codes).toHaveCount(0);
    });

    test('changing the password signs the other browser out and keeps this one', async ({ page, browser, baseURL }) => {
        // A member of its own, whose password this test changes.
        const email = await registerMember(page, 'Rotated');
        const other = await signInFromAnotherBrowser(browser, baseURL, email);

        await page.goto('/settings/password');
        await page.getByLabel('Current password').fill(password);
        await page.getByLabel('New password').fill('a-new-password-1');
        await page.getByLabel('Confirm password').fill('a-new-password-1');
        await page.getByRole('button', { name: 'Save password' }).click();
        await expect(page.getByText('Saved.')).toBeVisible();

        // This browser carries on, on the next page and the one after.
        await page.getByRole('link', { name: 'Security', exact: true }).first().click();
        await expect(page).toHaveURL(/\/settings\/security$/);
        await expect(page.getByRole('button', { name: 'Sign out other browsers' })).toBeVisible();

        // The other one is still showing the page it had. The next link it follows is answered with the sign-in page, not an error.
        await other.getByRole('link', { name: 'Profile', exact: true }).first().click();
        await expect(other).toHaveURL(/\/login$/);
        await expect(other.getByRole('button', { name: 'Log in' })).toBeVisible();
        await expect(other.getByRole('button', { name: 'Account menu' })).toHaveCount(0);

        await other.context().close();
    });

    test('"Sign out other browsers" asks for the password, then signs the other browser out and clears it from the list', async ({
        page,
        browser,
        baseURL,
    }) => {
        const email = await registerMember(page, 'Guarded');
        const other = await signInFromAnotherBrowser(browser, baseURL, email);

        await page.goto('/settings/security');
        const signOutOthers = page.getByRole('button', { name: 'Sign out other browsers' });
        const listed = page.getByText('Session ID:');

        // Two browsers are signed in, and both are listed (the test site keeps its sessions in the database).
        await expect(listed).toHaveCount(2);
        await expect(signOutOthers).toBeDisabled();

        // A wrong password changes nothing: the other browser's next page opens as before.
        await page.getByLabel('Your password').fill('wrong-password');
        await signOutOthers.click();
        await expect(page.getByText('The password is incorrect.')).toBeVisible();
        await expect(page.getByLabel('Your password')).toHaveValue('');
        await expect(listed).toHaveCount(2);
        await other.getByRole('link', { name: 'Password', exact: true }).first().click();
        await expect(other).toHaveURL(/\/settings\/password$/);

        await page.getByLabel('Your password').fill(password);
        await signOutOthers.click();

        // The notice is what the page said happened; the list is what the site says is true.
        await expect(page.getByText('Other browsers signed out')).toBeVisible();
        await expect(listed).toHaveCount(1);
        await expect(page.getByRole('button', { name: 'Account menu' })).toBeVisible();

        // The password that was accepted is not left in the box (the button waits for a new one).
        await expect(page.getByLabel('Your password')).toHaveValue('');
        await expect(signOutOthers).toBeDisabled();

        await other.getByRole('link', { name: 'Security', exact: true }).first().click();
        await expect(other).toHaveURL(/\/login$/);
        await expect(other.getByRole('button', { name: 'Account menu' })).toHaveCount(0);

        await other.context().close();
    });

    test('a new email address asks for the password, and the profile keeps it only with the right one', async ({ page }) => {
        // A member of its own: the address is what the other tests sign in with.
        const email = await registerMember(page, 'Moving');
        const newEmail = email.replace('@example.com', '-new@example.com');

        await page.goto('/settings/profile');
        const address = page.getByLabel('Email address');
        const asked = page.getByLabel('Your password', { exact: true });

        // The password is asked for only when the address typed is another one (spaces and case make no difference).
        await expect(asked).toHaveCount(0);
        await address.fill(newEmail);
        await expect(asked).toBeVisible();
        await address.fill(`  ${email.toUpperCase()} `);
        await expect(asked).toHaveCount(0);
        await address.fill(newEmail);

        // Saving without it, and with a wrong one, keeps the address the account has.
        await page.getByRole('button', { name: 'Save', exact: true }).click();
        await expect(page.getByText('The current password field is required.')).toBeVisible();
        await asked.fill('wrong-password');
        await page.getByRole('button', { name: 'Save', exact: true }).click();
        await expect(page.getByText('The password is incorrect.')).toBeVisible();
        await expect(asked).toHaveValue('');
        await page.reload();
        await expect(address).toHaveValue(email);

        // The right one saves it: what the page shows after a reload is what the account has.
        await address.fill(newEmail);
        await asked.fill(password);
        await page.getByRole('button', { name: 'Save', exact: true }).click();
        await expect(page.getByText('Saved.')).toBeVisible();
        await expect(asked).toHaveCount(0);

        // The password that was accepted is not kept: a third address asks again, with an empty box.
        await address.fill(`again-${newEmail}`);
        await expect(asked).toBeVisible();
        await expect(asked).toHaveValue('');
        await page.reload();
        await expect(address).toHaveValue(newEmail);
    });

    test('a member starts a forum thread and replies to it', async ({ page }) => {
        const title = unique('Smoke thread');
        const reply = unique('A reply');

        await signIn(page, member.email);
        await page.goto('/forum/announcements/threads/create');
        await page.getByLabel('Thread title').fill(title);
        await typeInEditor(page, 'The first message of the thread.');
        await page.getByRole('button', { name: 'Publish thread' }).first().click();

        await expect(page.getByRole('heading', { name: title, level: 1 })).toBeVisible();
        await expect(page.locator('[id^="post-"]').filter({ hasText: 'The first message of the thread.' })).toHaveCount(1);

        await typeInEditor(page, reply);
        await page.getByRole('button', { name: 'Submit Reply' }).click();

        await expect(page.locator('[id^="post-"]').filter({ hasText: reply })).toHaveCount(1);
    });

    test('a member comments on a blog post', async ({ page }) => {
        const comment = unique('A comment');

        await signIn(page, member.email);
        await page.goto('/blogs/a-first-post');
        await page.getByPlaceholder('Share your thoughts...').fill(comment);
        await page.getByRole('button', { name: 'Post Comment' }).click();

        await expect(page.locator('[id^="comment-"]').filter({ hasText: comment })).toHaveCount(1);
    });

    test('a member opens a support ticket and sees it in the list', async ({ page }) => {
        const subject = unique('Smoke ticket');

        await signIn(page, member.email);
        await page.goto('/support');
        await page.getByLabel('Subject').fill(subject);
        await page.getByPlaceholder('Describe your issue...').fill('Something to look at.');
        await page.getByRole('button', { name: 'Submit Ticket' }).click();

        // The form is emptied only when the ticket was saved, and the ticket is then in the list.
        await expect(page.getByLabel('Subject')).toHaveValue('');
        await expect(page.getByText(subject).first()).toBeVisible();
    });

    test('a member turns on a Discord notification, saves, and the page shows it kept', async ({ page }) => {
        await signIn(page, member.email);
        await page.goto('/settings/discord-notifications');

        // The categories are listed in the order of config/discord.php, and their switches have no name of their own: the second is
        // "Order shipped updates".
        const switches = page.getByRole('switch');
        await expect(switches).toHaveCount(3);
        await expect(switches.nth(1)).not.toBeChecked();

        for (const turnedOn of [true, false]) {
            await switches.nth(1).click();

            const saved = page.waitForResponse(
                (response) => response.request().method() === 'PUT' && response.url().endsWith('/discord/user/notification-preferences'),
            );
            await page.getByRole('button', { name: 'Save preferences' }).click();
            expect((await saved).request().postDataJSON()).toEqual({
                preferences: { support_ticket_reply: false, order_shipped: turnedOn, account_moderation: false },
            });

            // What the site kept, not what is on the screen.
            await page.reload();
            await expect(switches).toHaveCount(3);
            await expect(switches.nth(1)).toBeChecked({ checked: turnedOn });
        }
    });

    test('a member cannot open the admin area and is told so on a page of the site', async ({ page }) => {
        await signIn(page, member.email);
        const response = await page.goto('/acp/dashboard');

        expect(response?.status()).toBe(403);
        await expect(page.getByText('403').first()).toBeVisible();
    });
});

test.describe('shop', () => {
    test('a visitor puts a product in the cart, changes how many, takes it out again and reaches checkout', async ({ page }) => {
        await page.goto('/shop/products/hoodie');
        await chooseNecessaryCookiesOnly(page);
        await page.getByRole('button', { name: 'Add to cart' }).click();

        await page.goto('/cart');
        await expect(page.getByRole('heading', { name: 'Your cart' })).toBeVisible();
        await expect(page.getByText('$10.00').first()).toBeVisible();

        await page.getByLabel('Quantity for Hoodie').fill('2');
        await page.getByLabel('Quantity for Hoodie').blur();
        await expect(page.getByText('$20.00').first()).toBeVisible();

        await page.goto('/checkout');
        await expect(page).toHaveURL(/\/checkout$/);
        await expect(page.getByLabel('Email address')).toBeVisible();

        await page.goto('/cart');
        await page.getByRole('button', { name: 'Remove Hoodie' }).click();
        await expect(page.getByText('Your cart is empty.')).toBeVisible();
    });

    test('a free product is ordered without paying, and the order is shown', async ({ page }) => {
        await page.goto('/shop/products/starter-guide');
        await chooseNecessaryCookiesOnly(page);
        await page.getByRole('button', { name: 'Add to cart' }).click();

        await expect(page.getByRole('button', { name: 'Open cart' })).toBeVisible();
        await page.goto('/checkout');
        await page.getByLabel('Email address').fill(`buyer-${Date.now().toString(36)}@example.com`);
        await page.getByRole('button', { name: 'Place your order' }).click();

        await expect(page).toHaveURL(/\/checkout\/complete\//);
        await expect(page.getByRole('heading', { name: 'Thank you for your order' })).toBeVisible();
        await expect(page.getByText(/Order MF-\d+ is confirmed/)).toBeVisible();
        await expect(page.getByText('Paid', { exact: true })).toBeVisible();
        await expect(page.getByText('Starter guide').first()).toBeVisible();
    });
});

test.describe('staff', () => {
    test('an administrator opens the admin area and finds a member', async ({ page }) => {
        await signIn(page, admin.email);
        await page.goto('/acp/dashboard');
        await expect(page).toHaveURL(/\/acp\/dashboard$/);
        await expect(page.getByText(admin.nickname).first()).toBeVisible();

        await page.goto('/acp/users');
        await expect(page.getByText(member.email)).toBeVisible();
    });

    test('a site with no sign-in provider set up offers no provider button, and the system settings say what each still needs', async ({
        page,
        request,
    }) => {
        // A visitor sees the email form and no "Continue with" button, on either page. (The settings of the test site have no credentials.)
        for (const path of ['/login', '/register']) {
            await page.goto(path);
            await expect(page.getByLabel('Email address')).toBeVisible();
            // ("Continue with" on the sign-in page, "Sign up with" on the registration page.)
            await expect(page.getByRole('button', { name: /^(Continue|Sign up) with (Google|Discord|Steam)/ })).toHaveCount(0);
        }

        // The addresses of a provider that is not set up lead nowhere (404), rather than to the provider's own error page.
        expect((await request.get('/auth/oauth/google/redirect', { maxRedirects: 0 })).status()).toBe(404);

        // The administrator is told why: it is the credentials that are missing, and which.
        await signIn(page, admin.email);
        await page.goto('/acp/system');
        await expect(page.getByTestId('oauth-provider-google-missing')).toContainText('GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET');
        await expect(page.getByTestId('oauth-provider-discord-missing')).toContainText('DISCORD_CLIENT_ID and DISCORD_CLIENT_SECRET');
        await expect(page.getByTestId('oauth-provider-steam-missing')).toContainText('STEAM_API_KEY');
    });

    test('an administrator turns two-factor off for a member who lost their app, and the member signs in with the password alone', async ({
        page,
    }) => {
        // A member of its own, who sets two-factor up and then cannot use it (they are signed out, which is all a lost phone is to the site).
        const email = await registerMember(page, 'Locked');
        await startTwoFactorSetup(page);
        await page.getByLabel('Verification code').fill(authenticatorCode(await page.locator('input[readonly]').first().inputValue()));
        await page.getByRole('button', { name: 'Confirm setup' }).click();
        await expect(page.getByText('Multi-factor authentication is enabled')).toBeVisible();
        await signOut(page);

        // The member is found in the list and opened. The card says what the account has.
        await signIn(page, admin.email);
        await page.goto(`/acp/users?search=${encodeURIComponent(email)}`);
        await page
            .getByRole('row')
            .filter({ hasText: email })
            .getByRole('link', { name: /^Locked/ })
            .click();
        const card = page.getByTestId('reset-two-factor-card');
        await expect(card.getByTestId('two-factor-state')).toContainText('Two-factor is on');

        // A reason is asked for: one that is too short is refused by the server, with nothing changed.
        await card.getByRole('button', { name: 'Turn two-factor off' }).click();
        const dialog = page.getByRole('dialog');
        await dialog.getByLabel('Why are you turning it off?').fill('abc');
        await dialog.getByRole('button', { name: 'Turn two-factor off' }).click();
        await expect(dialog.getByText('Say a little more about why')).toBeVisible();
        await expect(card.getByTestId('two-factor-state')).toContainText('Two-factor is on');

        // With a reason it is done, the card says so, and there is nothing left to turn off.
        await dialog.getByLabel('Why are you turning it off?').fill('Ticket 4411: lost the phone');
        await dialog.getByRole('button', { name: 'Turn two-factor off' }).click();
        await expect(page.getByText('Two-factor was turned off for this account')).toBeVisible();
        await expect(dialog).toBeHidden();
        await expect(card.getByTestId('two-factor-state')).toContainText('Two-factor is not set up');
        await expect(card.getByRole('button', { name: 'Turn two-factor off' })).toBeDisabled();

        // The member signs in with the password alone: no code is asked for (signIn waits for the dashboard).
        await signOut(page);
        await signIn(page, email);
        await expect(page).toHaveURL(/\/dashboard$/);
    });

    test('an administrator adds a Discord role mapping, and its switches show what was saved and save what is flipped', async ({ page }) => {
        // The roles of the Discord server come from Discord, which a test cannot reach (the site answers 422 without a server id): the page
        // asks the site for them, so the answer is given here. Everything else is the site's own.
        await page.route('**/discord/admin/discord-roles', (route) => route.fulfill({ json: { data: [{ id: '4242424242', name: 'Moderators' }] } }));

        await signIn(page, admin.email);
        await page.goto('/acp/discord/role-mappings');

        const syncTo = page.getByRole('switch', { name: 'Sync to Discord' });
        const syncFrom = page.getByRole('switch', { name: /^Sync from Discord/ });
        // A mapping's own switches have no name; they are the two switches of its row, "To Discord" and then "From Discord".
        const row = page.getByRole('row').filter({ hasText: '4242424242' });

        // The form starts as the site means it to: sync to Discord on, from Discord off. A switch that is not tied to the form shows off.
        await expect(syncTo).toBeChecked();
        await expect(syncFrom).not.toBeChecked();

        await page.getByLabel('Spatie role').selectOption('moderator');
        await expect(page.getByLabel('Discord role').locator('option', { hasText: 'Moderators' })).toHaveCount(1);
        await page.getByLabel('Discord role').selectOption('4242424242');

        // Flip both, so that neither is what the form would send had the switches done nothing.
        await syncTo.click();
        await syncFrom.click();
        await expect(syncTo).not.toBeChecked();
        await expect(syncFrom).toBeChecked();

        const created = page.waitForRequest((request) => request.method() === 'POST' && request.url().endsWith('/discord/admin/role-mappings'));
        await page.getByRole('button', { name: 'Add mapping' }).click();
        expect((await created).postDataJSON()).toMatchObject({ sync_to_discord: false, sync_from_discord: true });

        // The row is drawn from what the site saved.
        await expect(row).toBeVisible();
        await expect(row.getByRole('switch').nth(0)).not.toBeChecked();
        await expect(row.getByRole('switch').nth(1)).toBeChecked();

        // A flipped switch is sent, and a reload shows what the site kept.
        for (const [index, field] of [
            [0, 'sync_to_discord'],
            [1, 'sync_from_discord'],
        ] as const) {
            const updated = page.waitForResponse(
                (response) => response.request().method() === 'PUT' && /\/discord\/admin\/role-mappings\/\d+$/.test(response.url()),
            );
            await row.getByRole('switch').nth(index).click();
            expect((await updated).request().postDataJSON()).toEqual({ [field]: index === 0 });
        }

        await page.reload();
        await expect(row).toBeVisible();
        await expect(row.getByRole('switch').nth(0)).toBeChecked();
        await expect(row.getByRole('switch').nth(1)).not.toBeChecked();

        // Leave the table as it was found.
        await row.getByRole('button', { name: 'Delete' }).click();
        await expect(row).toHaveCount(0);
    });
});
