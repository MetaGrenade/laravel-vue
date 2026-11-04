// Checks the links in the documentation (CONTRIBUTING.md, "Checks"): `npm run docs:check`.
//
// It reads README.md, CHANGELOG.md, CONTRIBUTING.md, SECURITY.md and every Markdown file under docs/, and fails when a link or an image
// points at a file that is not there, or at a #heading that the page does not have. Addresses on the web are not followed: whether a
// website is up is not something a change to this repository decides.
//
// Headings are turned into anchors the way GitHub does it (lower case, punctuation dropped, spaces become hyphens, a second heading with
// the same words gets "-1"), and an `id="..."` in the HTML of a page counts too. Code (fenced blocks and `inline spans`) is not read for
// links: an example that shows how a link is written is not a link.

import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs';
import { dirname, join, relative, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

/** The Markdown files at the top of the repository that are read besides everything under docs/. */
export const ROOT_FILES = ['README.md', 'CHANGELOG.md', 'CONTRIBUTING.md', 'SECURITY.md'];

/** Folders under docs/ that hold pictures and nothing to read. */
const SKIPPED_FOLDERS = new Set(['assets']);

/**
 * The anchor GitHub gives a heading: the text without its Markdown, in lower case, with everything but letters, numbers, `_`, `-` and
 * spaces removed, and the spaces made hyphens ("Production & SSR Builds" is `production--ssr-builds`).
 */
export function slug(heading) {
    return heading
        .replace(/!?\[([^\]]*)\]\([^)]*\)/g, '$1')
        .replace(/<[^>]+>/g, '')
        .trim()
        .toLowerCase()
        .replace(/[^\p{L}\p{N}\p{M}_\- ]/gu, '')
        .replace(/ /g, '-');
}

/** Every Markdown file this check reads, as paths from the root, sorted. */
export function documents(root) {
    const found = ROOT_FILES.filter((file) => existsSync(join(root, file)));

    const walk = (folder) => {
        for (const entry of readdirSync(join(root, folder), { withFileTypes: true })) {
            const path = `${folder}/${entry.name}`;

            if (entry.isDirectory()) {
                if (!SKIPPED_FOLDERS.has(entry.name)) walk(path);
            } else if (entry.name.endsWith('.md')) {
                found.push(path);
            }
        }
    };

    if (existsSync(join(root, 'docs'))) walk('docs');

    return found.sort();
}

/** Blanks out `code spans` so that what is written inside them is not read as a link, keeping every other character where it was. */
function withoutCodeSpans(line) {
    return line.replace(/(`+)(?:(?!\1)[\s\S])*?\1/g, (span) => ' '.repeat(span.length));
}

/**
 * What a page has and what it points at.
 *
 * @returns {{anchors: Set<string>, links: {line: number, target: string}[]}}
 */
export function read(text) {
    const anchors = new Set();
    const links = [];
    const seen = new Map();
    let fence = null;

    text.split(/\r?\n/).forEach((raw, index) => {
        const line = index + 1;
        const opening = raw.match(/^\s*(`{3,}|~{3,})/);

        if (fence) {
            if (opening && opening[1][0] === fence[0] && opening[1].length >= fence.length && raw.trim() === opening[1]) fence = null;

            return;
        }

        if (opening) {
            fence = opening[1];

            return;
        }

        const heading = raw.match(/^ {0,3}#{1,6}\s+(.*?)(?:\s+#+)?\s*$/);

        if (heading) {
            const base = slug(heading[1]);
            const count = seen.get(base) ?? 0;

            seen.set(base, count + 1);
            anchors.add(count === 0 ? base : `${base}-${count}`);
        }

        const prose = withoutCodeSpans(raw);

        for (const id of prose.matchAll(/\b(?:id|name)\s*=\s*["']([^"']+)["']/g)) anchors.add(id[1]);

        // [text](target) and ![alt](target), the target with at most one level of parentheses inside it.
        for (const link of prose.matchAll(
            /!?\[(?:[^\]\\]|\\.)*\]\(\s*(<[^>]*>|[^()\s]*(?:\([^()\s]*\)[^()\s]*)*)(?:\s+(?:"[^"]*"|'[^']*'))?\s*\)/g,
        )) {
            links.push({ line, target: link[1].replace(/^<|>$/g, '') });
        }

        // [label]: target
        const definition = prose.match(/^ {0,3}\[[^\]]+\]:\s*(<[^>]*>|\S+)/);

        if (definition) links.push({ line, target: definition[1].replace(/^<|>$/g, '') });

        // <img src="..."> <a href="..."> <source srcset="..."> (a srcset is a list: `a.png 1x, b.png 2x`).
        for (const attribute of prose.matchAll(/\b(src|href|srcset)\s*=\s*"([^"]*)"/g)) {
            const values = attribute[1] === 'srcset' ? attribute[2].split(',').map((part) => part.trim().split(/\s+/)[0]) : [attribute[2]];

            for (const target of values) if (target) links.push({ line, target });
        }
    });

    return { anchors, links };
}

const EXTERNAL = /^(?:[a-z][a-z0-9+.-]*:|\/\/)/i;

function decode(text) {
    try {
        return decodeURIComponent(text);
    } catch {
        return text;
    }
}

/**
 * Looks at every link of every document and returns what is wrong, as `{file, line, message}`, in the order of the files.
 *
 * @returns {{problems: {file: string, line: number, message: string}[], documents: number, links: number}}
 */
export function check(root) {
    const files = documents(root);
    const pages = new Map(files.map((file) => [file, read(readFileSync(join(root, file), 'utf8'))]));
    const problems = [];
    let checked = 0;

    /** The anchors of a Markdown file that is not one of the documents (read when a link needs it), or null when it is no Markdown. */
    const anchorsOf = (path) => {
        if (pages.has(path)) return pages.get(path).anchors;

        if (!path.endsWith('.md')) return null;

        const page = read(readFileSync(join(root, path), 'utf8'));

        pages.set(path, page);

        return page.anchors;
    };

    for (const file of files) {
        for (const { line, target } of pages.get(file).links) {
            if (EXTERNAL.test(target)) continue;

            checked++;

            const [location, ...rest] = target.split('#');
            const hash = rest.length > 0 ? decode(rest.join('#')) : null;
            const path = decode(location.split('?')[0]);

            let destination = file;

            if (path !== '') {
                const absolute = path.startsWith('/') ? resolve(root, `.${path}`) : resolve(root, dirname(file), path);
                destination = relative(root, absolute).replace(/\\/g, '/');

                if (destination.startsWith('..') || !existsSync(absolute)) {
                    problems.push({ file, line, message: `${target} is not a file or a folder of this repository` });

                    continue;
                }

                if (statSync(absolute).isDirectory()) continue;
            }

            if (hash === null || hash === '') continue;

            const anchors = anchorsOf(destination);

            // A link to a line of a source file (#L10) or to anything that is not Markdown has no heading to check.
            if (anchors === null || anchors.has(hash)) continue;

            const near = [...anchors].find((anchor) => anchor.toLowerCase() === hash.toLowerCase());

            problems.push({
                file,
                line,
                message: near
                    ? `${target}: the page has #${near}, and anchors are case sensitive`
                    : `${target}: ${destination === file ? 'this page' : destination} has no heading or id "${hash}"`,
            });
        }
    }

    return { problems, documents: files.length, links: checked };
}

export function run(args, root, log = console.log) {
    const { problems, documents: count, links } = check(root);

    for (const { file, line, message } of problems) log(`${file}:${line}: ${message}`);

    if (problems.length > 0) {
        log(`\n${problems.length} broken ${problems.length === 1 ? 'link' : 'links'} in ${count} documents.`);

        return 1;
    }

    log(`Documentation links are in order: ${links} links in ${count} documents.`);

    return 0;
}

if (import.meta.url === pathToFileURL(process.argv[1] ?? '').href) {
    process.exitCode = run(process.argv.slice(2), resolve(fileURLToPath(new URL('..', import.meta.url))));
}
