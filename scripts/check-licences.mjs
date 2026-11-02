// Checks the licences of what the application ships and keeps THIRD-PARTY-NOTICES.md in step with it (docs/licences.md):
//   npm run licences:check     fails when a dependency is under a licence the policy does not allow, or the notices are out of date
//   npm run licences:notices   writes THIRD-PARTY-NOTICES.md
//
// What is read: composer.lock for the PHP packages (the ones `composer install --no-dev` installs) and package-lock.json for the npm
// packages that `dependencies` in package.json reach, plus the few build-time packages whose files end up in the output (`alsoShipped`).
// The licence of a package is what it says about itself; its text is the LICENSE file in vendor/ or node_modules/. Both folders must be
// installed. The policy (scripts/licence-policy.json) holds the decisions: which licences are allowed, which package is an exception and why.

import { createHash } from 'node:crypto';
import { existsSync, readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

export const NOTICES_FILE = 'THIRD-PARTY-NOTICES.md';
export const POLICY_FILE = 'scripts/licence-policy.json';

const MIT_TEXT = `MIT License

{copyright}

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
`;

/** The standard text of the licences a package may be given when it ships none of its own (`fallbackTexts` in the policy). */
const STANDARD_TEXTS = { MIT: MIT_TEXT };

// ---- Licence expressions ----------------------------------------------------------------------------------------------------------------

/**
 * Reads an SPDX expression: `MIT`, `(MIT OR Apache-2.0)`, `MIT AND Zlib`, `GPL-2.0-only WITH Classpath-exception-2.0`.
 * AND binds tighter than OR, as in the specification.
 *
 * @returns {{id: string} | {or: object[]} | {and: object[]}}
 */
export function parseExpression(text) {
    const tokens = text
        .replace(/([()])/g, ' $1 ')
        .split(/\s+/)
        .filter(Boolean);
    const fail = () => new Error(`Cannot read the licence expression "${text}".`);
    const operator = (token) => token?.toUpperCase();
    let at = 0;

    const leaf = () => {
        const token = tokens[at++];

        if (token === undefined || token === ')' || ['AND', 'OR', 'WITH'].includes(operator(token))) throw fail();

        if (token === '(') {
            const inner = either();

            if (tokens[at++] !== ')') throw fail();

            return inner;
        }

        if (operator(tokens[at]) === 'WITH') {
            at++;
            const exception = tokens[at++];

            if (exception === undefined || exception === ')') throw fail();

            return { id: token, exception };
        }

        return { id: token };
    };

    const both = () => {
        const parts = [leaf()];

        while (operator(tokens[at]) === 'AND') {
            at++;
            parts.push(leaf());
        }

        return parts.length === 1 ? parts[0] : { and: parts };
    };

    const either = () => {
        const parts = [both()];

        while (operator(tokens[at]) === 'OR') {
            at++;
            parts.push(both());
        }

        return parts.length === 1 ? parts[0] : { or: parts };
    };

    const tree = either();

    if (at !== tokens.length) throw fail();

    return tree;
}

/**
 * The licence a package can be used under, given the licences that are allowed: the first alternative of an OR that is allowed, every
 * part of an AND. `null` when there is none. An exception to a licence (`WITH ...`) only widens what it is attached to, so the licence
 * itself decides.
 *
 * @param {object} tree What parseExpression returned.
 * @param {Set<string>} allowed
 */
export function resolveLicence(tree, allowed) {
    if ('id' in tree) return allowed.has(tree.id) ? tree.id : null;

    if ('or' in tree) {
        for (const part of tree.or) {
            const chosen = resolveLicence(part, allowed);

            if (chosen !== null) return chosen;
        }

        return null;
    }

    const chosen = tree.and.map((part) => resolveLicence(part, allowed));

    return chosen.every((one) => one !== null) ? chosen.join(' AND ') : null;
}

/** The names a package gives for its licence, joined as alternatives: `license` is a string or `{type}`, old packages have a `licenses` list. */
export function npmLicenceNames(meta) {
    const nameOf = (one) => (typeof one === 'string' ? one : one?.type);
    const given = meta.license !== undefined ? [meta.license] : Array.isArray(meta.licenses) ? meta.licenses : [];

    return given.map(nameOf).filter(Boolean);
}

const compare = (a, b) => (a < b ? -1 : a > b ? 1 : 0);
const expressionOf = (names, aliases) => names.map((name) => aliases[name] ?? name).join(' OR ');

// ---- What is shipped ---------------------------------------------------------------------------------------------------------------------

/** Where a package from the lock file is looked for first: inside the package that needs it, then in each folder above it. */
function locate(packages, from, name) {
    let base = from;

    for (;;) {
        const candidate = `${base ? `${base}/` : ''}node_modules/${name}`;

        if (packages[candidate]) return candidate;

        if (base === '') return null;

        const cut = base.lastIndexOf('/node_modules/');
        base = cut === -1 ? '' : base.slice(0, cut);
    }
}

/** The npm packages the front end is made of: everything `dependencies` reaches, and the named build-time packages with what they need. */
export function npmClosure(lock, alsoShipped = []) {
    const packages = lock.packages;
    const root = packages[''] ?? {};
    const found = new Map();
    const missing = [];

    const visit = (path) => {
        if (found.has(path)) return;

        found.set(path, packages[path]);

        const entry = packages[path];

        for (const kind of ['dependencies', 'optionalDependencies', 'peerDependencies']) {
            for (const name of Object.keys(entry[kind] ?? {})) {
                // An optional peer is whatever the project installed anyway (typescript, for one); it is not something the package brings.
                if (kind === 'peerDependencies' && entry.peerDependenciesMeta?.[name]?.optional) continue;

                const next = locate(packages, path, name);

                if (next !== null) visit(next);
                else if (kind === 'dependencies') missing.push(`${name} (needed by ${path.replace(/^.*node_modules\//, '')})`);
            }
        }
    };

    for (const name of [...Object.keys(root.dependencies ?? {}), ...alsoShipped]) {
        const path = locate(packages, '', name);

        if (path === null) missing.push(`${name} (named in package.json or the policy)`);
        else visit(path);
    }

    return { paths: [...found.keys()].sort(), missing };
}

const LICENCE_FILE = /^(licen[sc]e|copying|unlicense)([-_.][\w.-]*)?$/i;
const NOTICE_FILE = /^notice([-_.][\w.-]*)?$/i;
const CODE_EXTENSION = /\.(php|m?js|cjs|ts|json|ya?ml|xml|html?|css|map|png|jpe?g|gif|svg)$/i;

/** The licence and notice files of a package folder, with the line endings of the repository. */
export function readTexts(dir) {
    return readdirSync(dir, { withFileTypes: true })
        .filter((entry) => entry.isFile() && (LICENCE_FILE.test(entry.name) || NOTICE_FILE.test(entry.name)))
        .filter((entry) => !CODE_EXTENSION.test(entry.name))
        .map((entry) => entry.name)
        .sort()
        .map((file) => ({
            file,
            kind: NOTICE_FILE.test(file) ? 'notice' : 'licence',
            text: readFileSync(join(dir, file), 'utf8').replace(/^﻿/, '').replace(/\r\n?/g, '\n'),
        }));
}

/**
 * Every package that is shipped, with the licence it is used under.
 *
 * @returns {{packages: object[], problems: string[], skipped: string[]}}
 */
export function collect(root, policy) {
    const allowed = new Set(policy.allowed);
    const aliases = policy.aliases ?? {};
    const problems = [];
    const skipped = [];
    const packages = [];
    // The project's own packages, which are not third-party and are under the project's licence.
    const ownPackages = new Set(policy.firstParty ?? []);
    const ownSeen = new Set();

    const add = (ecosystem, name, version, names, dir, optional) => {
        const key = `${ecosystem}:${name}`;

        if (ownPackages.has(key)) {
            ownSeen.add(key);

            return;
        }

        if (!existsSync(dir)) {
            if (optional) skipped.push(`${key} (optional, not installed on this machine)`);
            else problems.push(`${key} is not installed in ${dir}. Run \`${ecosystem === 'composer' ? 'composer install' : 'npm ci'}\` first.`);

            return;
        }

        const expression = expressionOf(names, aliases);
        const exception = policy.exceptions?.[key];
        let licence = null;

        try {
            licence = expression === '' ? null : resolveLicence(parseExpression(expression), allowed);
        } catch (error) {
            problems.push(`${key}: ${error.message}`);

            return;
        }

        let excepted = false;

        if (licence === null && exception && exception.licence === expression) {
            licence = expression;
            excepted = true;
        }

        if (licence === null) {
            const hint = exception ? ` (the exception in the policy is for ${exception.licence})` : '';
            problems.push(
                `${key} ${version} is under ${expression === '' ? 'no licence it names' : expression}, which the policy does not allow${hint}.`,
            );
        }

        let texts = readTexts(dir);
        const fallback = policy.fallbackTexts?.[key];

        if (texts.length === 0 && fallback) {
            if (fallback.licence !== licence || !STANDARD_TEXTS[fallback.licence]) {
                problems.push(`${key}: the policy gives the text of ${fallback.licence}, but the package is under ${licence ?? expression}.`);
            } else {
                texts = [
                    {
                        file: `(${fallback.source})`,
                        kind: 'licence',
                        text: STANDARD_TEXTS[fallback.licence].replace('{copyright}', fallback.copyright),
                    },
                ];
            }
        } else if (texts.length === 0 && licence !== null) {
            problems.push(`${key} has no licence file. Add its text to \`fallbackTexts\` in ${POLICY_FILE}, with where it was read.`);
        }

        packages.push({ ecosystem, name, version, licence, expression, excepted, texts, reason: excepted ? exception.reason : null });
    };

    const composer = JSON.parse(readFileSync(join(root, 'composer.lock'), 'utf8'));

    for (const one of composer.packages.filter((one) => one.type !== 'metapackage')) {
        add('composer', one.name, one.version, one.license ?? [], join(root, 'vendor', one.name), false);
    }

    const npm = JSON.parse(readFileSync(join(root, 'package-lock.json'), 'utf8'));
    const { paths, missing } = npmClosure(npm, policy.alsoShipped);

    for (const one of missing) problems.push(`npm: ${one} is not in package-lock.json. Run \`npm install\`.`);

    for (const path of paths) {
        const entry = npm.packages[path];
        const dir = join(root, path);
        const name = path.replace(/^.*node_modules\//, '');
        const meta = existsSync(join(dir, 'package.json')) ? JSON.parse(readFileSync(join(dir, 'package.json'), 'utf8')) : {};

        add('npm', name, entry.version, npmLicenceNames(meta), dir, entry.optional === true);
    }

    // A decision in the policy about a package that is no longer shipped is a decision nobody reads any more.
    const shipped = (key) => ownSeen.has(key) || packages.some((one) => `${one.ecosystem}:${one.name}` === key);

    for (const [section, keys] of [
        ['exceptions', Object.keys(policy.exceptions ?? {})],
        ['fallbackTexts', Object.keys(policy.fallbackTexts ?? {})],
        ['firstParty', [...ownPackages]],
    ]) {
        for (const key of keys) {
            if (!shipped(key)) problems.push(`${POLICY_FILE} has an entry in \`${section}\` for ${key}, which is not shipped. Remove it.`);
        }
    }

    return { packages, problems, skipped };
}

// ---- The notices -------------------------------------------------------------------------------------------------------------------------

/**
 * A line that is somebody's notice: `Copyright ...` with a year, `(c)` or `©`; `© ...`; or `(c) 2020 ...`. A bare `(c) ` is not enough, because
 * that is also how Apache-2.0 numbers its clause 4(c) ("(c) You must retain, in the Source form of any Derivative Works").
 */
const COPYRIGHT_LINE = /^\s*(?:copyright\b.*?(?:\d{4}|\(c\)|©)|\(c\)\s+(?:\d{4}|copyright\b)|©)/i;
/** The licence's own template (`Copyright [yyyy] [name of copyright owner]`) is not anybody's notice. */
const TEMPLATE_LINE = /[<[{](?:year|yyyy|fullname|owner|name[^>\]}]*|copyright holders?)[>\]}]/i;

/** A licence text as the copyright lines it names and the rest of it, which is what packages under one licence have in common. */
export function splitText(text) {
    const copyright = [];
    const rest = [];

    for (const line of text.split('\n')) {
        if (COPYRIGHT_LINE.test(line) && !TEMPLATE_LINE.test(line)) copyright.push(line.trim().replace(/\s+/g, ' '));
        else rest.push(line.replace(/\s+$/, ''));
    }

    return {
        copyright,
        body: rest
            .join('\n')
            .replace(/\n{3,}/g, '\n\n')
            .trim(),
    };
}

const hashOf = (body) => createHash('sha1').update(body.toLowerCase().replace(/\s+/g, ' ').trim()).digest('hex').slice(0, 6);
const slug = (text) =>
    text
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-|-$/g, '');
const cell = (text) => text.replace(/\|/g, '\\|').replace(/\n/g, ' ');
const fenceFor = (body) => '`'.repeat(Math.max(3, ...[...body.matchAll(/`+/g)].map((run) => run[0].length + 1)));

const LINKS = {
    composer: (name) => `https://packagist.org/packages/${name}`,
    npm: (name) => `https://www.npmjs.com/package/${name}`,
};

/** THIRD-PARTY-NOTICES.md for the packages `collect` found. The same input always gives the same text: no dates, no versions. */
export function renderNotices(packages, policy) {
    // One line per package name. Versions are left out so a routine update does not change the file; a change of licence or of text does.
    const lines = new Map();

    for (const one of [...packages].sort((a, b) => compare(a.name, b.name) || compare(a.version, b.version))) {
        const key = `${one.ecosystem}:${one.name}`;
        const line = lines.get(key) ?? { ...one, parts: [], licences: new Set() };

        // Two versions of a package may be under different licences (d3-array moved from BSD-3-Clause to ISC): the row names both.
        line.licences.add(one.licence ?? one.expression);

        // The licence belongs to the text, not to the row: a row that names two licences has a text under each.
        for (const text of one.texts) line.parts.push({ ...text, ...splitText(text.text), licence: one.licence ?? one.expression });

        lines.set(key, line);
    }

    for (const line of lines.values()) line.licence = [...line.licences].sort(compare).join(', ');

    // The licence texts, once each. A package's own copyright lines are in its row.
    const groups = new Map();

    for (const line of lines.values()) {
        const anchors = new Set();
        const copyright = new Set();

        for (const part of line.parts) {
            const key = hashOf(part.body);
            const group = groups.get(key) ?? {
                key,
                label: part.kind === 'notice' ? 'Notice' : part.licence,
                body: part.body,
                members: new Set(),
            };

            group.members.add(`${line.ecosystem}:${line.name}`);
            groups.set(key, group);
            anchors.add(key);

            for (const owner of part.copyright) copyright.add(owner);
        }

        line.groupKeys = [...anchors];
        line.copyright = [...copyright];
    }

    const ordered = [...groups.values()].sort((a, b) => b.members.size - a.members.size || compare(a.label, b.label) || compare(a.key, b.key));
    const anchorOf = (group) => `text-${slug(group.label)}-${group.key}`;
    const groupByKey = new Map(ordered.map((group) => [group.key, group]));

    const out = [];
    const total = (ecosystem) => [...lines.values()].filter((line) => line.ecosystem === ecosystem);

    out.push(
        '# Third-party notices',
        '',
        'MetaForge is built on the software and the assets listed here. Each is used under its own licence, and this file is the notice those licences ask for.',
        '',
        '> This file is generated by `npm run licences:notices` from `composer.lock`, `package-lock.json`, the installed packages and `scripts/licence-policy.json`. Do not edit it by hand; CI fails when it is out of date. See [docs/licences.md](docs/licences.md).',
        '',
        `The PHP packages are the ${total('composer').length} that \`composer install --no-dev\` installs. The JavaScript packages are the ${total('npm').length} that the front end is built from (\`dependencies\` in \`package.json\` and what they need). Tools that only build or test the project are not shipped and are not listed.`,
        '',
        '## Licences in use',
        '',
    );

    const tally = new Map();

    for (const line of lines.values()) tally.set(line.licence ?? line.expression, (tally.get(line.licence ?? line.expression) ?? 0) + 1);

    for (const [licence, count] of [...tally].sort((a, b) => b[1] - a[1] || compare(a[0], b[0]))) out.push(`- ${licence}: ${count}`);

    const excepted = [...lines.values()].filter((line) => line.excepted);

    if (excepted.length > 0) {
        out.push('', '<a id="exceptions"></a>', '', '## Packages that ask for more than a notice', '');
        out.push(
            'These are under a licence that the project allows only by a written exception (`exceptions` in `scripts/licence-policy.json`).',
            '',
        );

        for (const line of excepted) out.push(`- **${line.name}** (${line.licence}): ${line.reason}`);
    }

    for (const [ecosystem, title] of [
        ['composer', 'PHP packages (Composer)'],
        ['npm', 'JavaScript packages (npm)'],
    ]) {
        out.push('', `## ${title}`, '', '| Package | Licence | Copyright | Text |', '| --- | --- | --- | --- |');

        for (const line of total(ecosystem)) {
            const licence = line.excepted ? `[${line.licence}](#exceptions)` : (line.licence ?? line.expression);
            const copyright = line.copyright.map(cell).join('<br>');
            const texts = line.groupKeys.map((key) => `[text](#${anchorOf(groupByKey.get(key))})`).join(', ');

            out.push(`| [${line.name}](${LINKS[ecosystem](line.name)}) | ${cell(licence)} | ${copyright} | ${texts} |`);
        }
    }

    if ((policy.assets ?? []).length > 0) {
        out.push('', '## Fonts and images', '');

        for (const asset of policy.assets) {
            out.push(
                `### ${asset.name}`,
                '',
                `- Licence: ${asset.licence}${asset.licenceText ? ` (${asset.licenceText})` : ''}`,
                `- Notice: ${asset.copyright}`,
                `- Source: ${asset.source}`,
                `- Use: ${asset.where}`,
                '',
            );
        }

        out.pop();
    }

    out.push(
        '',
        '## Licence texts',
        '',
        'Each text appears once, without the copyright lines, which are in the tables above next to the package they belong to.',
    );

    for (const group of ordered) {
        const members = [...group.members].map((member) => member.replace(/^[a-z]+:/, '')).sort();
        const fence = fenceFor(group.body);

        out.push(
            '',
            `<a id="${anchorOf(group)}"></a>`,
            '',
            `### ${group.label} (${group.key})`,
            '',
            `Used by ${members.length === 1 ? '1 package' : `${members.length} packages`}: ${members.join(', ')}.`,
            '',
            fence,
            group.body,
            fence,
        );
    }

    return `${out.join('\n')}\n`;
}

// ---- The command -------------------------------------------------------------------------------------------------------------------------

const namesIn = (notices) => new Set([...notices.matchAll(/^\| \[([^\]]+)\]\(/gm)].map((match) => match[1]));

/**
 * @param {string[]} argv `--write` writes the notices; without it they are only compared with the file.
 * @param {string} root The project folder.
 * @param {(line: string) => void} log
 * @returns {number} The exit code.
 */
export function run(argv, root, log = console.log) {
    const policy = JSON.parse(readFileSync(join(root, POLICY_FILE), 'utf8'));
    const { packages, problems, skipped } = collect(root, policy);

    for (const one of skipped) log(`skipped: ${one}`);

    if (problems.length > 0) {
        log(`Licence check failed (${problems.length}):`);

        for (const problem of problems) log(`  - ${problem}`);

        log(`\nA new licence is a decision: see docs/licences.md. Allowed licences and exceptions are in ${POLICY_FILE}.`);

        return 1;
    }

    const notices = renderNotices(packages, policy);
    const file = join(root, NOTICES_FILE);

    if (argv.includes('--write')) {
        writeFileSync(file, notices);
        log(`Wrote ${NOTICES_FILE}: ${namesIn(notices).size} packages.`);

        return 0;
    }

    const current = existsSync(file) ? readFileSync(file, 'utf8').replace(/\r\n?/g, '\n') : '';

    if (current !== notices) {
        const before = namesIn(current);
        const after = namesIn(notices);
        const added = [...after].filter((name) => !before.has(name));
        const removed = [...before].filter((name) => !after.has(name));

        log(`${NOTICES_FILE} is out of date.`);

        if (added.length > 0) log(`  new in the lock files: ${added.join(', ')}`);
        if (removed.length > 0) log(`  no longer shipped: ${removed.join(', ')}`);
        if (added.length === 0 && removed.length === 0) log('  a licence or a licence text changed.');

        log('\nRun `npm run licences:notices` and commit the result.');

        return 1;
    }

    // By name: a package installed at two versions is counted once, as it is in the notices.
    const count = (keep) => new Set(packages.filter(keep).map((one) => `${one.ecosystem}:${one.name}`)).size;
    log(
        `Licences are in order: ${count((one) => one.ecosystem === 'composer')} PHP and ${count((one) => one.ecosystem === 'npm')} npm packages, ${count((one) => one.excepted)} by exception. ${NOTICES_FILE} is up to date.`,
    );

    return 0;
}

if (import.meta.url === pathToFileURL(process.argv[1] ?? '').href) {
    process.exitCode = run(process.argv.slice(2), resolve(fileURLToPath(new URL('..', import.meta.url))));
}
