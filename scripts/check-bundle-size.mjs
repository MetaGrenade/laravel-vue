// Holds the built front end to a size budget (docs/bundle-budget.md): `npm run build`, then `npm run bundle:check`.
//
// Sizes are gzipped bytes, which is what a host sends. Two things are measured from Vite's manifest:
//   - what every visitor loads: the app entry and everything it imports statically, and the stylesheet;
//   - what a page adds to that: its own chunk and the chunks it shares with other pages (the layout, a form kit, the editor), counted once.
// Pages are loaded on demand (`import.meta.glob`), so a heavy library that only one page uses costs that page and no other.

import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { gzipSync } from 'node:zlib';

export const ENTRY_SCRIPT = 'resources/js/app.ts';
export const ENTRY_STYLE = 'resources/css/app.css';
export const PAGE_PREFIX = 'resources/js/pages/';

const bytes = (n) => n.toLocaleString('en-US');

/**
 * What the build weighs: the initial load, and what each page adds to it.
 *
 * @param {string} buildDir The directory Vite built into (it holds manifest.json).
 */
export function measure(buildDir) {
    const manifest = JSON.parse(readFileSync(join(buildDir, 'manifest.json'), 'utf8'));
    const sizes = new Map();
    const gzipped = (file) => {
        if (!sizes.has(file)) sizes.set(file, gzipSync(readFileSync(join(buildDir, file)), { level: 9 }).length);

        return sizes.get(file);
    };

    for (const entry of [ENTRY_SCRIPT, ENTRY_STYLE]) {
        if (!manifest[entry]) throw new Error(`${entry} is not in ${join(buildDir, 'manifest.json')}. Run \`npm run build\` first.`);
    }

    // Static imports only: a dynamic import is another request, made when it is needed.
    const closure = (key, seen = new Set()) => {
        if (seen.has(key)) return seen;
        seen.add(key);
        for (const next of manifest[key].imports ?? []) closure(next, seen);

        return seen;
    };

    const filesOf = (keys) => {
        const files = new Set();
        for (const key of keys) {
            files.add(manifest[key].file);
            for (const css of manifest[key].css ?? []) files.add(css);
        }

        return files;
    };

    const total = (files, wanted) =>
        [...files].filter((file) => file.endsWith('.css') === (wanted === 'css')).reduce((n, file) => n + gzipped(file), 0);

    const initialFiles = filesOf([...closure(ENTRY_SCRIPT), ENTRY_STYLE]);

    const pages = Object.keys(manifest)
        .filter((key) => key.startsWith(PAGE_PREFIX))
        .map((key) => {
            const added = [...filesOf(closure(key))].filter((file) => !initialFiles.has(file));

            return {
                name: key.slice(PAGE_PREFIX.length),
                bytes: added.reduce((n, file) => n + gzipped(file), 0),
                largest: added
                    .map((file) => ({ file: file.replace(/^assets\//, ''), bytes: gzipped(file) }))
                    .sort((a, b) => b.bytes - a.bytes)
                    .slice(0, 3),
            };
        })
        .sort((a, b) => b.bytes - a.bytes);

    // The middle page: a change to the layout or a shared component moves it, where a change to one page does not.
    const typical = pages.length > 0 ? pages[Math.floor(pages.length / 2)].bytes : 0;

    // Every script the build made, loaded at once or on demand, so a heavy library imported lazily is still seen. Heaviest first, each
    // with its name in the manifest (the source file for an entry, which stays the same when the hash in the file name changes).
    const scripts = Object.entries(manifest)
        .filter(([, chunk]) => chunk.file.endsWith('.js'))
        .map(([key, chunk]) => ({ key, file: chunk.file.replace(/^assets\//, ''), bytes: gzipped(chunk.file) }))
        .sort((a, b) => b.bytes - a.bytes);

    return { initial: { js: total(initialFiles, 'js'), css: total(initialFiles, 'css') }, typical, scripts, pages };
}

/**
 * Whether a build is inside its budget.
 *
 * @returns {string[]} What is over, each as a sentence; empty when the build passes.
 */
export function check(measured, budget) {
    const problems = [];
    const overrides = budget.page.overrides ?? {};
    const scriptOverrides = budget.chunk.overrides ?? {};

    for (const [name, override] of [...Object.entries(overrides), ...Object.entries(scriptOverrides)]) {
        if (!Number.isInteger(override.limit) || override.limit <= 0 || typeof override.why !== 'string' || override.why.trim() === '') {
            throw new Error(`The budget for ${name} needs a limit (bytes) and why it is allowed more than the others.`);
        }
    }

    for (const [what, used, limit] of [
        ['The JavaScript every page loads', measured.initial.js, budget.initial.js],
        ['The stylesheet every page loads', measured.initial.css, budget.initial.css],
    ]) {
        if (used > limit) problems.push(`${what} is ${bytes(used)} B gzipped, over the ${bytes(limit)} B it is allowed.`);
    }

    // Every script is held to the limit for any one, or to its own where it has one.
    for (const script of measured.scripts) {
        const own = scriptOverrides[script.key];
        const limit = own?.limit ?? budget.chunk.max;
        if (script.bytes <= limit) continue;

        problems.push(
            own
                ? `${script.key} (${script.file}) is ${bytes(script.bytes)} B gzipped, over the ${bytes(limit)} B it is allowed.`
                : `${script.file} is ${bytes(script.bytes)} B gzipped, over the ${bytes(limit)} B any one script is allowed.`,
        );
    }

    if (measured.typical > budget.page.typical) {
        problems.push(
            `The typical page (the middle one of ${measured.pages.length}) adds ${bytes(measured.typical)} B gzipped to the shared bundle, over the ${bytes(budget.page.typical)} B it is allowed. Something every page shares has grown.`,
        );
    }

    for (const page of measured.pages) {
        const limit = overrides[page.name]?.limit ?? budget.page.default;
        if (page.bytes <= limit) continue;

        const biggest = page.largest.map((chunk) => `${chunk.file} ${bytes(chunk.bytes)}`).join(', ');
        problems.push(
            `${page.name} adds ${bytes(page.bytes)} B gzipped to the shared bundle, over the ${bytes(limit)} B it is allowed (largest: ${biggest}).`,
        );
    }

    const built = new Set(measured.pages.map((page) => page.name));
    for (const name of Object.keys(overrides)) {
        if (!built.has(name)) problems.push(`The budget has a limit for ${name}, which is not a page any more. Remove it.`);
    }

    const builtScripts = new Set(measured.scripts.map((script) => script.key));
    for (const name of Object.keys(scriptOverrides)) {
        if (!builtScripts.has(name)) problems.push(`The budget has a limit for the script ${name}, which is not built any more. Remove it.`);
    }

    return problems;
}

/** The pages closest to their limit, as lines of text. */
function closest(measured, budget, count) {
    const limitOf = (page) => budget.page.overrides?.[page.name]?.limit ?? budget.page.default;

    return [...measured.pages]
        .sort((a, b) => b.bytes / limitOf(b) - a.bytes / limitOf(a))
        .slice(0, count)
        .map((page) => `  ${bytes(page.bytes).padStart(9)} of ${bytes(limitOf(page)).padStart(9)}  ${page.name}`);
}

function main(argv) {
    const option = (name, fallback) => argv.find((arg) => arg.startsWith(`--${name}=`))?.slice(name.length + 3) ?? fallback;
    const root = fileURLToPath(new URL('..', import.meta.url));
    const buildDir = option('build', join(root, 'public/build'));
    const budget = JSON.parse(readFileSync(option('budget', join(root, 'scripts/bundle-budget.json')), 'utf8'));

    const measured = measure(buildDir);
    const problems = check(measured, budget);

    console.log('Front-end size (gzipped bytes)');
    console.log(`  ${bytes(measured.initial.js).padStart(9)} of ${bytes(budget.initial.js).padStart(9)}  JavaScript every page loads`);
    console.log(`  ${bytes(measured.initial.css).padStart(9)} of ${bytes(budget.initial.css).padStart(9)}  stylesheet every page loads`);
    const scriptOverrides = budget.chunk.overrides ?? {};
    const largest = measured.scripts.find((script) => !scriptOverrides[script.key]);
    if (largest) {
        console.log(`  ${bytes(largest.bytes).padStart(9)} of ${bytes(budget.chunk.max).padStart(9)}  largest script (${largest.file})`);
    }
    for (const script of measured.scripts.filter((one) => scriptOverrides[one.key])) {
        const { limit } = scriptOverrides[script.key];
        console.log(`  ${bytes(script.bytes).padStart(9)} of ${bytes(limit).padStart(9)}  ${script.key}, a script with a limit of its own`);
    }
    console.log(`  ${bytes(measured.typical).padStart(9)} of ${bytes(budget.page.typical).padStart(9)}  what the typical page adds to those`);
    console.log(`\nWhat each of the ${measured.pages.length} pages adds to the shared bundle; the ones closest to their limit:`);
    console.log(closest(measured, budget, 8).join('\n'));

    if (problems.length === 0) {
        console.log('\nWithin budget.');

        return 0;
    }

    console.error(`\n${problems.length} over budget:`);
    for (const problem of problems) {
        console.error(`  - ${problem}`);
        if (process.env.GITHUB_ACTIONS) console.error(`::error title=Bundle size::${problem}`);
    }
    console.error(
        '\nIf the growth is on purpose, raise the number in scripts/bundle-budget.json in the same change and say why (docs/bundle-budget.md).',
    );

    return 1;
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    process.exitCode = main(process.argv.slice(2));
}
