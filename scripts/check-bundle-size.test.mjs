// The size check is what fails the build, so it has tests of its own: `npm run bundle:test`.

import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { after, describe, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { gzipSync } from 'node:zlib';
import { check, measure } from './check-bundle-size.mjs';

const script = fileURLToPath(new URL('./check-bundle-size.mjs', import.meta.url));
const made = [];

// How much text each file of the fixture build holds. Text that does not compress well (below), so a longer file is a bigger one.
const LENGTH = {
    'app.js': 3000,
    'vendor.js': 9000,
    'app.css': 5000,
    'layout.js': 4000,
    'shared.js': 2500,
    'editor.js': 20000,
    'Light.js': 1000,
    'Light.css': 600,
    'Heavy.js': 1500,
    'Other.js': 500,
    'lazy.js': 30000,
};

const content = (name) => {
    let seed = [...name].reduce((n, letter) => (n * 31 + letter.charCodeAt(0)) >>> 0, 7);
    let text = '';

    while (text.length < LENGTH[name]) {
        seed = (Math.imul(seed, 1664525) + 1013904223) >>> 0;
        text += seed.toString(16).padStart(8, '0');
    }

    return text.slice(0, LENGTH[name]);
};

/** What the check should find for a file: computed here from the same text, not typed in. */
const gz = (name) => gzipSync(content(name), { level: 9 }).length;

/**
 * A small build:
 *   app.js        - the entry; imports vendor.js; loads Light on demand
 *   app.css       - the stylesheet
 *   Light         - imports vendor and layout, has a stylesheet
 *   Heavy         - imports layout and editor; both of them import shared.js (one chunk, reached two ways)
 *   Other         - imports layout; loads lazy.js when it needs it
 */
function fixture() {
    const dir = mkdtempSync(join(tmpdir(), 'bundle-'));
    made.push(dir);
    mkdirSync(join(dir, 'assets'));

    for (const name of Object.keys(LENGTH)) writeFileSync(join(dir, 'assets', name), content(name));

    const manifest = {
        'resources/js/app.ts': { file: 'assets/app.js', isEntry: true, imports: ['_vendor'], dynamicImports: ['resources/js/pages/Light.vue'] },
        _vendor: { file: 'assets/vendor.js' },
        'resources/css/app.css': { file: 'assets/app.css', isEntry: true },
        _layout: { file: 'assets/layout.js', imports: ['_vendor', '_shared'] },
        _shared: { file: 'assets/shared.js' },
        _editor: { file: 'assets/editor.js', imports: ['_shared'] },
        'resources/js/pages/Light.vue': { file: 'assets/Light.js', isDynamicEntry: true, imports: ['_vendor', '_layout'], css: ['assets/Light.css'] },
        'resources/js/pages/Heavy.vue': { file: 'assets/Heavy.js', isDynamicEntry: true, imports: ['_layout', '_editor'] },
        'resources/js/pages/Other.vue': { file: 'assets/Other.js', isDynamicEntry: true, imports: ['_layout'], dynamicImports: ['_lazy'] },
        _lazy: { file: 'assets/lazy.js' },
    };
    writeFileSync(join(dir, 'manifest.json'), JSON.stringify(manifest));

    return dir;
}

/** A budget with exactly as much room as the build needs: nothing over, and one byte less is. */
function budgetFor(measured, page = {}) {
    return {
        initial: { js: measured.initial.js, css: measured.initial.css },
        chunk: { max: measured.largestChunk.bytes },
        page: { typical: measured.typical, default: measured.pages[0].bytes, overrides: {}, ...page },
    };
}

after(() => made.forEach((dir) => rmSync(dir, { recursive: true, force: true })));

describe('measure', () => {
    test('the initial load is the entry and what it imports statically, and the stylesheet', () => {
        const { initial } = measure(fixture());

        assert.equal(initial.js, gz('app.js') + gz('vendor.js'));
        assert.equal(initial.css, gz('app.css'));
    });

    test('a page adds its own chunk, its stylesheet and the chunks it shares, but not what every page loads', () => {
        const light = measure(fixture()).pages.find((page) => page.name === 'Light.vue');

        // vendor.js is in the initial load already
        assert.equal(light.bytes, gz('Light.js') + gz('Light.css') + gz('layout.js') + gz('shared.js'));
    });

    test('a chunk reached by two routes is counted once', () => {
        const heavy = measure(fixture()).pages.find((page) => page.name === 'Heavy.vue');

        assert.equal(heavy.bytes, gz('Heavy.js') + gz('layout.js') + gz('editor.js') + gz('shared.js'));
    });

    test('a script loaded on demand is not part of the page that loads it, but is the largest script if it is', () => {
        const measured = measure(fixture());
        const other = measured.pages.find((page) => page.name === 'Other.vue');

        assert.equal(other.bytes, gz('Other.js') + gz('layout.js') + gz('shared.js'));
        assert.equal(measured.largestChunk.file, 'lazy.js');
        assert.equal(measured.largestChunk.bytes, gz('lazy.js'));
    });

    test('pages are listed heaviest first, with their largest chunks', () => {
        const { pages } = measure(fixture());

        assert.deepEqual(
            pages.map((page) => page.name),
            ['Heavy.vue', 'Light.vue', 'Other.vue'],
        );
        assert.equal(pages[0].largest[0].file, 'editor.js');
    });

    test('a build without the entry says to build first', () => {
        const dir = mkdtempSync(join(tmpdir(), 'bundle-'));
        made.push(dir);
        writeFileSync(join(dir, 'manifest.json'), '{}');

        assert.throws(() => measure(dir), /npm run build/);
    });
});

describe('check', () => {
    test('a build exactly at its budget passes', () => {
        const measured = measure(fixture());

        assert.deepEqual(check(measured, budgetFor(measured)), []);
    });

    test('the JavaScript every page loads, over by a byte, fails', () => {
        const measured = measure(fixture());
        const budget = budgetFor(measured);
        budget.initial.js -= 1;

        const problems = check(measured, budget);

        assert.equal(problems.length, 1);
        assert.match(problems[0], /JavaScript every page loads/);
    });

    test('the stylesheet every page loads, over by a byte, fails', () => {
        const measured = measure(fixture());
        const budget = budgetFor(measured);
        budget.initial.css -= 1;

        assert.match(check(measured, budget).join('\n'), /stylesheet every page loads/);
    });

    test('a script over the limit for any one fails, and says which', () => {
        const measured = measure(fixture());
        const budget = budgetFor(measured);
        budget.chunk.max -= 1;

        assert.match(check(measured, budget).join('\n'), /lazy\.js .* any one script/);
    });

    test('the typical page growing fails even though no single page is over its own limit', () => {
        const measured = measure(fixture());
        const budget = budgetFor(measured);
        budget.page.typical -= 1;

        const problems = check(measured, budget);

        assert.equal(problems.length, 1);
        assert.match(problems[0], /typical page/);
    });

    test('a page over the default fails and is named with what made it heavy', () => {
        const measured = measure(fixture());
        const budget = budgetFor(measured);
        budget.page.default -= 1;

        const problems = check(measured, budget);

        assert.equal(problems.length, 1);
        assert.match(problems[0], /^Heavy\.vue adds/);
        assert.match(problems[0], /editor\.js/);
    });

    test('a page with a limit of its own is held to it, not to the default', () => {
        const measured = measure(fixture());
        const heavy = measured.pages[0].bytes;
        const budget = budgetFor(measured, {
            default: heavy - 1,
            overrides: { 'Heavy.vue': { limit: heavy, why: 'The editor.' } },
        });

        assert.deepEqual(check(measured, budget), []);

        budget.page.overrides['Heavy.vue'].limit = heavy - 1;
        assert.match(check(measured, budget).join('\n'), /Heavy\.vue adds/);
    });

    test('a limit for a page that is not built any more fails, so the file does not collect old entries', () => {
        const measured = measure(fixture());
        const budget = budgetFor(measured, { overrides: { 'Gone.vue': { limit: 5000, why: 'It was heavy.' } } });

        assert.match(check(measured, budget).join('\n'), /Gone\.vue, which is not a page any more/);
    });

    test('a limit with no reason is refused', () => {
        const measured = measure(fixture());

        assert.throws(() => check(measured, budgetFor(measured, { overrides: { 'Heavy.vue': { limit: 9000, why: ' ' } } })), /why/);
        assert.throws(() => check(measured, budgetFor(measured, { overrides: { 'Heavy.vue': { limit: 'lots', why: 'x' } } })), /limit/);
    });
});

describe('the command', () => {
    const run = (dir, budget) => {
        const file = join(dir, 'budget.json');
        writeFileSync(file, JSON.stringify(budget));

        return spawnSync(process.execPath, [script, `--build=${dir}`, `--budget=${file}`], { encoding: 'utf8' });
    };

    test('exits 0 and says so when the build is within budget', () => {
        const dir = fixture();
        const result = run(dir, budgetFor(measure(dir)));

        assert.equal(result.status, 0, result.stderr);
        assert.match(result.stdout, /Within budget/);
    });

    test('exits 1 and names what is over, and how to raise it on purpose, when it is not', () => {
        const dir = fixture();
        const budget = budgetFor(measure(dir));
        budget.page.default -= 1;
        const result = run(dir, budget);

        assert.equal(result.status, 1);
        assert.match(result.stderr, /Heavy\.vue adds/);
        assert.match(result.stderr, /bundle-budget\.json/);
    });
});
