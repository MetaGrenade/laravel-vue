import { expect, test, type Page } from '@playwright/test';

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

test.describe('visitors', () => {
    test('the home page, the shop, the forum and the blog open and link to each other', async ({ page }) => {
        await page.goto('/');
        await chooseNecessaryCookiesOnly(page);
        await expect(page).toHaveTitle(/MetaForge/);

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

    test('the search box finds a post by its title and opens it', async ({ page }) => {
        await page.goto('/');
        await chooseNecessaryCookiesOnly(page);

        await page.getByRole('button', { name: /Search…/ }).click();
        await page.getByPlaceholder('Search blogs, forum threads, and FAQs').fill('first');
        await page.getByRole('link', { name: /A first post/ }).first().click();

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
});

test.describe('members', () => {
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
});
