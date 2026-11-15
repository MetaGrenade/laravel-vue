import { expect, test } from '@playwright/test';
import { expectNoProblems } from './axe';
import { signInAndKeep, stateFile } from './session';

/**
 * The pages a signed-in person opens (docs/accessibility.md): a member's own pages, and the admin area for an administrator. The records
 * are those of AccessibilitySignedInFlowSeeder, each the first of its kind, so a page can be named by number (/acp/polls/1/edit); the
 * member is user 2 and the administrator user 1, because the administrator is made first.
 *
 * Every page is run through the same check as the public ones (tests/a11y/axe.ts), after it has finished loading what it asks for.
 */
const memberPages = [
    '/dashboard',
    '/forum',
    '/forum/announcements',
    '/forum/announcements/welcome',
    '/forum/announcements/threads/create',
    '/forum/following',
    '/messages',
    '/messages/1',
    '/orders',
    '/wishlist',
    '/shop/products/hoodie',
    '/cart',
    '/blogs/a-first-post',
    '/support',
    '/support/tickets/1',
    '/support/tickets/2',
    '/support/tickets/3',
    '/members/2',
    '/settings/profile',
    '/settings/account-standing',
    '/settings/addresses',
    '/settings/api-keys',
    '/settings/appearance',
    '/settings/billing',
    '/settings/billing/invoices',
    '/settings/billing/payment-methods',
    '/settings/discord-notifications',
    '/settings/notifications',
    '/settings/password',
    '/settings/privacy',
    '/settings/security',
    '/confirm-password',
];

const adminPages = [
    '/acp/dashboard',
    '/acp/users',
    '/acp/users/2/edit',
    '/acp/users/2/moderation',
    '/acp/acl',
    '/acp/acl/permissions/create',
    '/acp/acl/roles/create',
    '/acp/audit-log',
    '/acp/billing/invoices',
    '/acp/billing/plans',
    '/acp/billing/plans/create',
    '/acp/billing/plans/1/edit',
    '/acp/billing/usage',
    '/acp/billing/webhooks',
    '/acp/billing/webhooks/1',
    '/acp/blog-categories',
    '/acp/blog-categories/create',
    '/acp/blog-comments',
    '/acp/blog-tags',
    '/acp/blog-tags/create',
    '/acp/blogs',
    '/acp/blogs/create',
    '/acp/blogs/1/edit',
    '/acp/blogs/1/revisions',
    '/acp/commerce',
    '/acp/commerce/coupons',
    '/acp/commerce/coupons/create',
    '/acp/commerce/coupons/1/edit',
    '/acp/commerce/entitlements',
    '/acp/commerce/gift-cards',
    '/acp/commerce/gift-cards/create',
    '/acp/commerce/gift-cards/1',
    '/acp/commerce/orders',
    '/acp/commerce/products',
    '/acp/commerce/products/create',
    '/acp/commerce/products/1/edit',
    '/acp/commerce/reviews',
    '/acp/commerce/shipping',
    '/acp/commerce/tax-rates',
    '/acp/commerce/taxonomy',
    '/acp/commerce/tebex',
    '/acp/discord/role-mappings',
    '/acp/discord/sync',
    '/acp/forums',
    '/acp/forums/automod',
    '/acp/forums/boards/create',
    '/acp/forums/boards/1/edit',
    '/acp/forums/categories/create',
    '/acp/forums/categories/1/edit',
    '/acp/forums/prefixes',
    '/acp/forums/prefixes/create',
    '/acp/forums/reports',
    '/acp/forums/review',
    '/acp/legal',
    '/acp/polls',
    '/acp/polls/create',
    '/acp/polls/1/edit',
    '/acp/reputation/badges',
    '/acp/reputation/badges/create',
    '/acp/search-analytics',
    '/acp/support',
    '/acp/support/assignment-rules',
    '/acp/support/faq-categories',
    '/acp/support/faq-categories/create',
    '/acp/support/faqs/create',
    '/acp/support/faqs/1/edit',
    '/acp/support/sla',
    '/acp/support/teams',
    '/acp/support/templates',
    '/acp/support/ticket-categories',
    '/acp/support/ticket-categories/create',
    '/acp/support/tickets/create',
    '/acp/support/tickets/1',
    '/acp/support/tickets/2',
    '/acp/support/tickets/3',
    '/acp/support/tickets/1/edit',
    '/acp/system',
    '/acp/tokens',
    '/acp/trust-safety',
];

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
