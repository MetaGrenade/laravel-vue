import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import type { Result } from 'axe-core';

/**
 * The public paths a visitor walks (docs/accessibility.md). The product, thread,
 * post and member are the records AccessibilityFlowSeeder creates.
 */
const pages: Array<{ name: string; path: string }> = [
    { name: 'home', path: '/' },
    { name: 'sign in', path: '/login' },
    { name: 'register', path: '/register' },
    { name: 'forgot password', path: '/forgot-password' },
    { name: 'pricing', path: '/pricing' },
    { name: 'shop', path: '/shop' },
    { name: 'cart', path: '/cart' },
    { name: 'product', path: '/shop/products/hoodie' },
    { name: 'forum', path: '/forum' },
    { name: 'thread', path: '/forum/announcements/welcome' },
    { name: 'blog', path: '/blogs' },
    { name: 'post', path: '/blogs/a-first-post' },
    { name: 'support', path: '/support' },
    { name: 'members', path: '/members' },
    { name: 'profile', path: '/members/1' },
    { name: 'leaderboard', path: '/leaderboard' },
];

function report(violations: Result[]): string {
    return violations
        .map((violation) => {
            const targets = violation.nodes
                .slice(0, 5)
                .map((node) => `  ${node.target.join(' ')}`)
                .join('\n');

            return `${violation.impact} ${violation.id}: ${violation.help}\n${targets}`;
        })
        .join('\n\n');
}

/** What fails a page: everything axe reports except what it marks minor. */
const FAILING_IMPACTS: string[] = ['critical', 'serious', 'moderate'];

async function expectNoProblems(page: Page, name: string): Promise<void> {
    await page.locator('#main-content, main').first().waitFor();

    // `label-content-name-mismatch` is a rule axe keeps off unless it is asked for: a link or button whose name leaves out the words shown on it.
    const results = await new AxeBuilder({ page }).options({ rules: { 'label-content-name-mismatch': { enabled: true } } }).analyze();
    const problems = results.violations.filter((violation) => FAILING_IMPACTS.includes(violation.impact ?? ''));

    expect(problems, `${name}\n${report(problems)}`).toEqual([]);
}

test.describe('main flows', () => {
    for (const entry of pages) {
        test(entry.name, async ({ page }) => {
            const response = await page.goto(entry.path);

            expect(response?.ok(), `${entry.name} returned ${response?.status()}`).toBe(true);
            await expectNoProblems(page, entry.name);
        });
    }

    test('checkout', async ({ page }) => {
        await page.goto('/shop/products/hoodie');
        await page.locator('#main-content').waitFor();
        await page.getByRole('button', { name: 'Necessary only' }).click();
        const added = page.waitForResponse(
            (response) => response.url().includes('/cart/items') && response.request().method() === 'POST' && response.status() < 400,
        );
        await page.getByRole('button', { name: 'Add to cart' }).click();
        await added;

        const response = await page.goto('/checkout');

        expect(response?.ok(), `checkout returned ${response?.status()}`).toBe(true);
        await expect(page).toHaveURL(/\/checkout$/);
        await expectNoProblems(page, 'checkout');
    });
});
