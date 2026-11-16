import { expect, test } from '@playwright/test';
import { expectNoProblems } from './axe';
import { signInAndKeep, stateFile } from './session';
import { adminStates, memberStates, visitorStates, type State } from './states';

/**
 * The states of tests/a11y/states.ts at desktop width, each checked as every other page is (docs/accessibility.md).
 */
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
