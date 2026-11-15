import { expect, test, type Page } from '@playwright/test';
import { expectFitsAPhone, PHONE } from './axe';
import { adminPages, memberPages, publicPages } from './pages';
import { signInAndKeep, stateFile } from './session';

/**
 * The same pages as the other specs, and the menus a phone opens, at PHONE width (docs/accessibility.md): the page does not scroll sideways, and
 * axe finds nothing it marks critical, serious or moderate, with the rule for a control smaller than 24 by 24 pixels.
 *
 * Most of what is wrong at this width is not in the desktop run at all: a table wider than the screen, a row of header buttons that does not fit,
 * a grid that will not shrink below its longest word.
 */
test.use({ viewport: PHONE });

type State = { name: string; path: string; show: (page: Page) => Promise<void>; menuOpen?: boolean };

const visitorStates: State[] = [
    {
        name: 'the navigation menu',
        path: '/',
        show: async (page) => {
            await page.getByRole('button', { name: 'Open navigation menu' }).click();
            await expect(page.getByRole('dialog')).toBeVisible();
        },
    },
    {
        name: 'the search',
        path: '/',
        show: async (page) => {
            await page.getByRole('button', { name: 'Search', exact: true }).click();
            await expect(page.getByRole('dialog')).toBeVisible();
        },
    },
    {
        name: 'the cart',
        path: '/',
        show: async (page) => {
            await page.getByRole('button', { name: 'Open cart' }).click();
            await expect(page.getByRole('dialog')).toBeVisible();
        },
    },
];

const memberStates: State[] = [
    {
        name: 'the navigation menu',
        path: '/dashboard',
        show: async (page) => {
            await page.getByRole('button', { name: 'Open navigation menu' }).click();
            await expect(page.getByRole('dialog')).toBeVisible();
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
        name: 'the account menu',
        path: '/dashboard',
        menuOpen: true,
        show: async (page) => {
            await page.getByRole('button', { name: /Account menu/ }).click();
            await expect(page.getByRole('menu')).toBeVisible();
        },
    },
];

function opens(states: State[]): void {
    for (const state of states) {
        test(`${state.path}: ${state.name}`, async ({ page }) => {
            await page.goto(state.path);
            await page.waitForLoadState('networkidle');
            await state.show(page);
            await expectFitsAPhone(page, `${state.path}: ${state.name}`, { menuOpen: state.menuOpen });
        });
    }
}

function pagesOf(paths: string[]): void {
    for (const path of paths) {
        test(path, async ({ page }) => {
            const response = await page.goto(path);

            expect(response?.ok(), `${path} returned ${response?.status()}`).toBe(true);
            expect(new URL(page.url()).pathname, `${path} sent the browser somewhere else`).toBe(path);

            // Lists and tables are filled by requests the page makes once it has loaded (a deferred prop, a count).
            await page.waitForLoadState('networkidle');
            await expectFitsAPhone(page, path);
        });
    }
}

const member = { name: 'a member (phone)', email: 'avery@example.com' };
const administrator = { name: 'an administrator (phone)', email: 'admin@example.com' };

test.describe('as a visitor', () => {
    pagesOf(publicPages.map((entry) => entry.path));
    opens(visitorStates);
});

test.describe('as a member', () => {
    test.use({ storageState: stateFile(member.name) });
    test.beforeAll(async ({ browser, baseURL }) => signInAndKeep(browser, baseURL, member.name, member.email));

    pagesOf(memberPages);
    opens(memberStates);
});

test.describe('as an administrator', () => {
    test.use({ storageState: stateFile(administrator.name) });
    test.beforeAll(async ({ browser, baseURL }) => signInAndKeep(browser, baseURL, administrator.name, administrator.email));

    pagesOf(adminPages);
});
