import { expect, test } from '@playwright/test';
import { expectFitsAPhone, PHONE } from './axe';
import { signInAndKeep, stateFile } from './session';
import { adminStates, memberStates, visitorStates, type State } from './states';

/**
 * The states of tests/a11y/states.ts (a dialog that is open, a form with its errors, the menu of a row) at PHONE width, with the check of
 * phone-flows.spec.ts: nothing sticks out of the screen, and axe finds nothing it marks critical, serious or moderate (docs/accessibility.md).
 *
 * A dialog is the part of a page most likely to be wider than a phone, and a form with its errors the part that is longer than it was.
 */
test.use({ viewport: PHONE });

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
                await expectFitsAPhone(page, `${state.path}: ${state.name}`, { menuOpen: state.menuOpen });
            });
        }
    });
}

inThePage('as a visitor', visitorStates);
inThePage('as a member', memberStates, { name: 'a member (phone states)', email: 'avery@example.com' });
inThePage('as an administrator', adminStates, { name: 'an administrator (phone states)', email: 'admin@example.com' });
