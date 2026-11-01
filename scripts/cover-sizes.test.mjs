// What the page tells the browser about how wide a cover is drawn: `npm run bundle:test`.
// The helper is TypeScript; Node strips the types when it imports the file.

import assert from 'node:assert/strict';
import { describe, test } from 'node:test';
import { THREE_ACROSS, fixedHeightSizes, ratioBoxSizes } from '../resources/js/lib/coverSizes.ts';

// The featured post and the post itself: a box of fixed height across the screen.
const FEATURED = [
    { minScreen: 640, width: '100vw', height: 288 },
    { width: '100vw', height: 256 },
];

describe('a cover in a box of fixed height', () => {
    test('is scaled to the box height, so a wide cover needs more than the width of the screen', () => {
        // 1600 x 400 on a 390 pixel screen fills 256 pixels of height at 1024 pixels wide, not 390.
        assert.equal(fixedHeightSizes(4, FEATURED), '(min-width: 640px) max(100vw, 1152px), max(100vw, 1024px)');
    });

    test('needs only the box width when the cover is no wider than the box', () => {
        // 1080 x 1350: 256 x 0.8 is 205 pixels, far under the width of any screen. max() leaves the screen width in charge.
        assert.equal(fixedHeightSizes(0.8, FEATURED), '(min-width: 640px) max(100vw, 231px), max(100vw, 205px)');
    });

    test('rounds up, so the file is never a pixel short', () => {
        assert.equal(fixedHeightSizes(3.3333, [{ width: '100vw', height: 256 }]), 'max(100vw, 854px)');
    });

    test('has only the width of the box to go by when the proportions are not known', () => {
        assert.equal(fixedHeightSizes(null, FEATURED), '(min-width: 640px) 100vw, 100vw');
        assert.equal(fixedHeightSizes(undefined, FEATURED), '(min-width: 640px) 100vw, 100vw');
        assert.equal(fixedHeightSizes(0, FEATURED), '(min-width: 640px) 100vw, 100vw');
        assert.equal(fixedHeightSizes(Number.NaN, FEATURED), '(min-width: 640px) 100vw, 100vw');
    });

    test('keeps a box that stops growing at a width of its own', () => {
        const post = [
            { minScreen: 1280, width: '1200px', height: 256 },
            { width: '100vw', height: 256 },
        ];

        assert.equal(fixedHeightSizes(6, post), '(min-width: 1280px) max(1200px, 1536px), max(100vw, 1536px)');
    });
});

describe('a cover in a box with a ratio of its own', () => {
    test('keeps the share of the screen for a cover no wider than the box', () => {
        // The cards in the list are 16:7.
        assert.equal(ratioBoxSizes(0.8, 16 / 7, THREE_ACROSS), '(min-width: 1024px) 33vw, (min-width: 640px) 50vw, 100vw');
        assert.equal(ratioBoxSizes(16 / 7, 16 / 7, THREE_ACROSS), '(min-width: 1024px) 33vw, (min-width: 640px) 50vw, 100vw');
    });

    test('grows by how much wider than the box the cover is', () => {
        // 4 : 1 in a 16:7 card is 1.75 times as wide as the card.
        assert.equal(ratioBoxSizes(4, 16 / 7, THREE_ACROSS), '(min-width: 1024px) 57.75vw, (min-width: 640px) 87.5vw, 175vw');
    });

    test('works for the 16:9 boxes of the recommended posts', () => {
        assert.equal(ratioBoxSizes(16 / 9, 16 / 9, THREE_ACROSS), '(min-width: 1024px) 33vw, (min-width: 640px) 50vw, 100vw');
        assert.equal(ratioBoxSizes(2, 16 / 9, THREE_ACROSS), '(min-width: 1024px) 37.13vw, (min-width: 640px) 56.25vw, 112.5vw');
    });

    test('has only the share of the screen to go by when the proportions are not known', () => {
        assert.equal(ratioBoxSizes(null, 16 / 7, THREE_ACROSS), '(min-width: 1024px) 33vw, (min-width: 640px) 50vw, 100vw');
    });
});
