import AxeBuilder from '@axe-core/playwright';
import { expect, type Page } from '@playwright/test';
import type { AxeResults, Result } from 'axe-core';

function report(violations: Result[]): string {
    return violations
        .map((violation) => {
            const targets = violation.nodes
                .slice(0, 5)
                .map((node) => {
                    // For a colour: the two colours and the ratio, which is what a fix is measured by.
                    const colours = node.any[0]?.data;
                    const measured = colours?.contrastRatio
                        ? ` (${colours.fgColor} on ${colours.bgColor}, ${colours.contrastRatio}:1, wants ${colours.expectedContrastRatio})`
                        : '';

                    return `  ${node.target.join(' ')}${measured}`;
                })
                .join('\n');

            return `${violation.impact} ${violation.id}: ${violation.help}\n${targets}`;
        })
        .join('\n\n');
}

/** What fails a page: everything axe reports except what it marks minor. */
const FAILING_IMPACTS: string[] = ['critical', 'serious', 'moderate'];

/**
 * The browser is tall enough for the whole of a page: axe cannot measure text that is below the bottom of the screen, or that is partly under
 * the sticky header it was scrolled up to (665 of the 874 pieces of text it could not measure in the dark theme were only that).
 * The configuration sets it (playwright.config.ts); a test checks that it is there, because a viewport set in the wrong place is replaced
 * by the device's own without a word.
 */
export const VIEWPORT = { width: 1280, height: 3000 };

/**
 * axe's own settings for the colour check, with one change: `ignorePseudo`.
 *
 * Every page has a backdrop (`bg-page::before`: two faint glows and a dot grid, 30rem tall) that is an absolutely positioned pseudo-element,
 * and axe will not say what the background of any text under such an element is ("pseudoContent"). It reported every piece of text on every
 * page as unknown, and so the colour check had never failed on anything, in either theme. With `ignorePseudo` it measures against the colour
 * of the surface, and leaves the glows out; they move the background by at most a fifth of its luminance, in the top corners only.
 *
 * A check's options are replaced when a run sets them, not merged, so all of them are here (axe-core 4.14).
 */
const CONTRAST_OPTIONS = {
    ignoreUnicode: true,
    ignoreLength: false,
    ignorePseudo: true,
    boldValue: 700,
    boldTextPt: 14,
    largeTextPt: 18,
    contrastRatio: { normal: { expected: 4.5 }, large: { expected: 3 } },
    pseudoSizeThreshold: 0.25,
    shadowOutlineEmMax: 0.2,
    textStrokeEmMin: 0.03,
};

const RUN_OPTIONS = {
    // `label-content-name-mismatch` is a rule axe keeps off unless it is asked for: a link or button whose name leaves out the words shown on it.
    rules: { 'label-content-name-mismatch': { enabled: true } },
    checks: { 'color-contrast': { options: CONTRAST_OPTIONS } },
};

/**
 * The share of the text on a page whose colours axe has to be able to measure. The rest is text it cannot (on a photograph, on a gradient,
 * partly under something else), which is not checked. The check once measured none of it; this is what stops that being unnoticed again.
 */
const MEASURED_AT_LEAST = 0.6;

function expectMeasured(results: AxeResults, name: string): void {
    const count = (rules: Result[]) => rules.filter((rule) => rule.id === 'color-contrast').reduce((sum, rule) => sum + rule.nodes.length, 0);
    const measured = count(results.passes) + count(results.violations);
    const unmeasured = count(results.incomplete);

    expect(
        measured / Math.max(1, measured + unmeasured),
        `${name}: axe could measure the colours of ${measured} pieces of text and not of ${unmeasured}`,
    ).toBeGreaterThanOrEqual(MEASURED_AT_LEAST);
}

function problemsIn(results: AxeResults): Result[] {
    return results.violations.filter((violation) => FAILING_IMPACTS.includes(violation.impact ?? ''));
}

/** Runs axe on the page on screen, in the light theme and then in the dark one, and fails with the elements it names (docs/accessibility.md). */
export async function expectNoProblems(page: Page, name: string): Promise<void> {
    await page.locator('#main-content, main').first().waitFor();

    expect(
        page.viewportSize(),
        `${name}: the browser is not ${VIEWPORT.width} by ${VIEWPORT.height}, so axe cannot reach the text below the screen`,
    ).toEqual(VIEWPORT);

    // A colour that is part of the way through a transition, or a card that is part of the way through fading in, is measured as what it is at that moment (a tint
    // that was 3.5:1 for a few hundred milliseconds failed a page now and then).
    await page.addStyleTag({ content: '*, *::before, *::after { transition: none !important; animation: none !important; }' });

    // The theme follows the browser's setting until someone picks one.
    await page.emulateMedia({ colorScheme: 'light' });
    await expect(page.locator('html'), `${name}: the page is dark in the light theme`).not.toHaveClass(/\bdark\b/);

    const light = await new AxeBuilder({ page }).options(RUN_OPTIONS).analyze();

    expect(problemsIn(light), `${name} (light theme)\n${report(problemsIn(light))}`).toEqual([]);
    expectMeasured(light, `${name} (light theme)`);

    await page.emulateMedia({ colorScheme: 'dark' });
    await expect(page.locator('html'), `${name}: the page did not turn dark`).toHaveClass(/\bdark\b/);

    // The other rules look at the structure of the page, which the theme does not change.
    const dark = await new AxeBuilder({ page }).options(RUN_OPTIONS).withRules(['color-contrast']).analyze();

    expect(problemsIn(dark), `${name} (dark theme)\n${report(problemsIn(dark))}`).toEqual([]);
    expectMeasured(dark, `${name} (dark theme)`);
}
