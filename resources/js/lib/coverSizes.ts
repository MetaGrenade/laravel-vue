// The `sizes` of a blog cover, for the browser to choose one of its files by (docs/images.md).
//
// A cover is drawn with `object-cover`: it is scaled until it fills the box on both sides and the rest is cut off. A wide cover in
// a box that is short for its width is scaled to the box's height, so it is wider than the box and needs more pixels across than
// the box has. Saying only how wide the box is would make the browser pick a file that has to be stretched.

/** A box of fixed height, whose width depends on the screen. Listed from the widest screen down; the last has no `minScreen`. */
export type FixedHeightStep = {
    /** Applies from this screen width, in pixels. */
    minScreen?: number;
    /** How wide the box is, as a CSS length: `100vw`, `1200px`. */
    width: string;
    /** How tall the box is, in pixels. */
    height: number;
};

/** A box that keeps a ratio of its own, whose width is a share of the screen. Listed from the widest screen down. */
export type RatioBoxStep = {
    minScreen?: number;
    /** How wide the box is, as a share of the screen width (33 is a third). */
    vw: number;
};

/** The cover at the top of a post and of its preview: a box 16rem tall (h-64) across the page, which stops growing at 1200 pixels. */
export const POST_COVER_BOX: FixedHeightStep[] = [
    { minScreen: 1280, width: '1200px', height: 256 },
    { width: '100vw', height: 256 },
];

/** The cards of a grid that is three across on a wide screen, two on a medium one and one on a phone. */
export const THREE_ACROSS: RatioBoxStep[] = [{ minScreen: 1024, vw: 33 }, { minScreen: 640, vw: 50 }, { vw: 100 }];

const known = (aspect: number | null | undefined): aspect is number => typeof aspect === 'number' && Number.isFinite(aspect) && aspect > 0;

const forScreen = (minScreen: number | undefined, length: string) => (minScreen ? `(min-width: ${minScreen}px) ${length}` : length);

/**
 * For a cover that fills a box of fixed height. The picture is as wide as the box or as wide as it takes to fill the box's
 * height, whichever is more.
 *
 * @param aspect The cover's width over its height. Without it the box's width is all there is to go by.
 */
export function fixedHeightSizes(aspect: number | null | undefined, steps: FixedHeightStep[]): string {
    return steps
        .map(({ minScreen, width, height }) => forScreen(minScreen, known(aspect) ? `max(${width}, ${Math.ceil(height * aspect)}px)` : width))
        .join(', ');
}

/**
 * For a cover that fills a box with a ratio of its own (width over height). A cover wider than the box is scaled to the box's
 * height, so it is as many times wider than the box as it is wider than the box's ratio.
 */
export function ratioBoxSizes(aspect: number | null | undefined, boxRatio: number, steps: RatioBoxStep[]): string {
    const wider = known(aspect) && aspect > boxRatio ? aspect / boxRatio : 1;

    return steps.map(({ minScreen, vw }) => forScreen(minScreen, `${Math.round(vw * wider * 100) / 100}vw`)).join(', ');
}
