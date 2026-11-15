import { expect, test } from '@playwright/test';
import { expectNoProblems } from './axe';
import { publicPages } from './pages';

/** The public paths a visitor walks (docs/accessibility.md, the list is in pages.ts). */
test.describe('main flows', () => {
    for (const entry of publicPages) {
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
