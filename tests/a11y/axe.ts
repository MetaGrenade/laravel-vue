import AxeBuilder from '@axe-core/playwright';
import { expect, type Page } from '@playwright/test';
import type { Result } from 'axe-core';

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

/** Runs axe on the page on screen and fails with the elements it names (docs/accessibility.md). */
export async function expectNoProblems(page: Page, name: string): Promise<void> {
    await page.locator('#main-content, main').first().waitFor();

    // `label-content-name-mismatch` is a rule axe keeps off unless it is asked for: a link or button whose name leaves out the words shown on it.
    const results = await new AxeBuilder({ page }).options({ rules: { 'label-content-name-mismatch': { enabled: true } } }).analyze();
    const problems = results.violations.filter((violation) => FAILING_IMPACTS.includes(violation.impact ?? ''));

    expect(problems, `${name}\n${report(problems)}`).toEqual([]);
}
