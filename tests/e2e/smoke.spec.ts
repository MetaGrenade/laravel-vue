import { expect, test, type Page } from '@playwright/test';
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

    test('turning two-factor off asks for the password first, and the page does it once it is given', async ({ page }) => {
        // A member of its own: with two-factor on every sign-in asks for a code, and a test that failed half way must not leave that on the
        // member the other tests sign in as.
        const nickname = unique('Careful');
        const email = `${nickname.toLowerCase().replace(/\s+/g, '-')}@example.com`;

        await page.goto('/register');
        await chooseNecessaryCookiesOnly(page);
        await page.getByLabel('Nickname').fill(nickname);
        await page.getByLabel('Email address').fill(email);
        await page.getByLabel('Password', { exact: true }).fill(password);
        await page.getByLabel('Confirm password').fill(password);
        await page.getByRole('button', { name: 'Create account' }).click();
        await expect(page.getByRole('button', { name: 'Account menu' })).toBeVisible();

        await page.goto('/settings/security');
        await page.getByRole('button', { name: 'Enable multi-factor authentication' }).click();
        await page.getByLabel('Verification code').fill(authenticatorCode(await page.locator('input[readonly]').first().inputValue()));
        await page.getByRole('button', { name: 'Confirm setup' }).click();
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
