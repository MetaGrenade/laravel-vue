import { expect, test, type Locator, type Page } from '@playwright/test';
import { expectNoProblems } from './axe';
import { signInAndKeep, stateFile } from './session';

/**
 * What a page shows only after a click or after a failure (docs/accessibility.md): a menu or a dialog that is open, a form with its errors.
 * The pages of main-flows.spec.ts and signed-in-flows.spec.ts are checked as they load, and none of this is on them.
 *
 * Each case opens a page, does what a person does, waits for what it shows, and runs the same check as every other page (tests/a11y/axe.ts)
 * with that on the screen.
 */
type State = {
    name: string;
    path: string;
    show: (page: Page) => Promise<void>;
    /** A menu is open, and the page behind it is aria-hidden: see WHILE_A_MENU_IS_OPEN in axe.ts. Only where axe reports it. */
    menuOpen?: boolean;
};

/** Waits until the page has finished the request a click or a submit made, and what it answered is on the screen. */
async function settled(page: Page): Promise<void> {
    await page.waitForLoadState('networkidle');
}

/** The browser stops a form that has a required field empty before it is sent; the errors the server gives are what these cases are for. */
async function sendAnyway(page: Page): Promise<void> {
    await page.locator('form').evaluateAll((forms) => forms.forEach((form) => (form.noValidate = true)));
}

/** A message under a field. Every field has the paragraph, empty and hidden, until the form has something to say. */
const anError = (where: Page | Locator): Locator => where.locator('p.text-destructive').filter({ hasText: /\S/ }).first();

const visitorStates: State[] = [
    {
        name: 'the search dialog',
        path: '/',
        show: async (page) => {
            await page.keyboard.press('Control+k');
            await expect(page.getByRole('dialog')).toBeVisible();
        },
    },
    {
        name: 'the colour theme menu',
        path: '/',
        menuOpen: true,
        show: async (page) => {
            await page.getByRole('button', { name: 'Change colour theme' }).filter({ visible: true }).first().click();
            await expect(page.getByRole('menu')).toBeVisible();
        },
    },
    {
        name: 'sign in with a password that is wrong',
        path: '/login',
        show: async (page) => {
            await page.getByLabel('Email address').fill('avery@example.com');
            await page.getByLabel('Password', { exact: true }).fill('not-the-password');
            await sendAnyway(page);
            await page.getByRole('button', { name: 'Log in' }).click();
            await expect(anError(page)).toBeVisible();
        },
    },
    {
        name: 'register with a form that is wrong',
        path: '/register',
        show: async (page) => {
            await page.getByLabel('Email address').fill('avery@example.com');
            await page.getByLabel('Password', { exact: true }).fill('a');
            await page.getByLabel('Confirm password').fill('b');
            await sendAnyway(page);
            await page.getByRole('button', { name: 'Create account' }).click();
            await expect(anError(page)).toBeVisible();
        },
    },
    {
        name: 'forgot password for an address nobody has',
        path: '/forgot-password',
        show: async (page) => {
            await page.getByLabel('Email address').fill('nobody@example.com');
            await page.getByRole('button', { name: 'Email password reset link' }).click();
            await settled(page);
        },
    },
];

const memberStates: State[] = [
    {
        name: 'the account menu',
        path: '/dashboard',
        menuOpen: true,
        show: async (page) => {
            await page.getByRole('button', { name: /Account menu/ }).click();
            await expect(page.getByRole('menu')).toBeVisible();
        },
    },
    {
        name: 'the password form with its errors',
        path: '/settings/password',
        show: async (page) => {
            await page.getByLabel('Current password').fill('not-the-password');
            await page.getByLabel('New password', { exact: true }).fill('a');
            await page.getByLabel('Confirm password').fill('b');
            await sendAnyway(page);
            await page.getByRole('button', { name: 'Save password' }).click();
            await expect(anError(page)).toBeVisible();
        },
    },
    {
        name: 'the dialog that deletes the account',
        path: '/settings/profile',
        show: async (page) => {
            await page.getByRole('button', { name: 'Delete account' }).click();
            await expect(page.getByRole('dialog')).toBeVisible();
        },
    },
    {
        name: 'the dialog that adds an address',
        path: '/settings/addresses',
        show: async (page) => {
            await page.getByRole('button', { name: 'Add address' }).click();
            await expect(page.getByRole('dialog')).toBeVisible();
        },
    },
    {
        name: 'the dialog that adds an address, with its errors',
        path: '/settings/addresses',
        show: async (page) => {
            await page.getByRole('button', { name: 'Add address' }).click();
            await sendAnyway(page);
            await page.getByRole('button', { name: 'Save address' }).click();
            await expect(anError(page.getByRole('dialog'))).toBeVisible();
        },
    },
    {
        name: 'the dialog that creates an API key',
        path: '/settings/api-keys',
        show: async (page) => {
            await page.getByRole('button', { name: 'Create key' }).click();
            await expect(page.getByRole('dialog')).toBeVisible();
        },
    },
    {
        name: 'the form for a new thread, with its errors',
        path: '/forum/announcements/threads/create',
        show: async (page) => {
            await sendAnyway(page);
            await page.getByRole('button', { name: 'Publish thread' }).first().click();
            await expect(anError(page)).toBeVisible();
        },
    },
    {
        name: 'the notifications',
        path: '/dashboard',
        menuOpen: true,
        show: async (page) => {
            await page.getByRole('button', { name: 'Notifications' }).click();
            await expect(page.getByRole('menu', { name: 'Notifications' })).toBeVisible();
        },
    },
    {
        name: 'the profile form with its errors',
        path: '/settings/profile',
        show: async (page) => {
            await page.getByLabel('Nickname').fill('');
            await sendAnyway(page);
            await page.getByRole('button', { name: 'Save', exact: true }).click();
            await expect(anError(page)).toBeVisible();
        },
    },
    {
        name: 'the menu of a post',
        path: '/forum/announcements/welcome',
        menuOpen: true,
        show: async (page) => {
            await page
                .getByRole('button', { name: /^Actions for the post/ })
                .first()
                .click();
            await expect(page.getByRole('menu')).toBeVisible();
        },
    },
];

/** The `…` button at the end of the first row of a list opens a menu of what can be done with it. */
const rowMenu = (path: string, of: string): State => ({
    name: `the menu of the first ${of} in the list`,
    path,
    menuOpen: true,
    show: async (page) => {
        await page
            .getByRole('button', { name: /^Actions for/ })
            .first()
            .click();
        await expect(page.getByRole('menu')).toBeVisible();
    },
});

/** A page for a new record, sent with nothing in it. */
const emptyForm = (path: string, of: string): State => ({
    name: `the form for a new ${of}, with its errors`,
    path,
    show: async (page) => {
        await sendAnyway(page);
        await page.locator('main button[type=submit]:visible').first().click();
        await expect(anError(page)).toBeVisible();
    },
});

const adminStates: State[] = [
    rowMenu('/acp/users', 'member'),
    rowMenu('/acp/blogs', 'blog post'),
    rowMenu('/acp/support', 'ticket'),
    rowMenu('/acp/acl', 'role'),
    rowMenu('/acp/forums', 'forum category'),
    {
        name: 'the dialog that asks before a member is deleted',
        path: '/acp/users',
        show: async (page) => {
            await page
                .getByRole('button', { name: /^Actions for/ })
                .first()
                .click();
            await page.getByRole('menuitem', { name: 'Delete' }).click();
            await expect(page.getByRole('alertdialog')).toBeVisible();
        },
    },
    emptyForm('/acp/blogs/create', 'blog post'),
    emptyForm('/acp/acl/roles/create', 'role'),
    emptyForm('/acp/polls/create', 'poll'),
    emptyForm('/acp/support/faqs/create', 'FAQ'),
    emptyForm('/acp/commerce/products/create', 'product'),
    emptyForm('/acp/commerce/coupons/create', 'coupon'),
    emptyForm('/acp/billing/plans/create', 'plan'),
];

function inThePage(name: string, states: State[], who?: { name: string; email: string }): void {
    test.describe(name, () => {
        if (who) {
            test.use({ storageState: stateFile(who.name) });
            test.beforeAll(async ({ browser, baseURL }) => signInAndKeep(browser, baseURL, who.name, who.email));
        }

        for (const state of states) {
            test(`${state.path}: ${state.name}`, async ({ page }) => {
                const response = await page.goto(state.path);

                expect(response?.ok(), `${state.path} returned ${response?.status()}`).toBe(true);
                await page.waitForLoadState('networkidle');
                await state.show(page);
                await expectNoProblems(page, `${state.path}: ${state.name}`, { menuOpen: state.menuOpen });
            });
        }
    });
}

inThePage('as a visitor', visitorStates);
inThePage('as a member', memberStates, { name: 'a member (states)', email: 'avery@example.com' });
inThePage('as an administrator', adminStates, { name: 'an administrator (states)', email: 'admin@example.com' });
