import assert from 'node:assert/strict';
import { mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { after, describe, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import {
    collect,
    npmClosure,
    npmLicenceNames,
    parseExpression,
    readTexts,
    renderNotices,
    resolveLicence,
    run,
    splitText,
} from './check-licences.mjs';

const made = [];

after(() => made.forEach((dir) => rmSync(dir, { recursive: true, force: true })));

/** A project folder with the given files; an object is written as JSON. */
function project(files) {
    const root = mkdtempSync(join(tmpdir(), 'licences-'));
    made.push(root);

    for (const [path, content] of Object.entries(files)) {
        mkdirSync(dirname(join(root, path)), { recursive: true });
        writeFileSync(join(root, path), typeof content === 'string' ? content : JSON.stringify(content, null, 2));
    }

    return root;
}

const mit = (owner) =>
    `MIT License\n\nCopyright (c) 2020 ${owner}\n\nPermission is hereby granted, free of charge, to any person obtaining a copy of this software.\n`;
const policy = (more = {}) => ({ allowed: ['MIT', 'ISC', 'Apache-2.0', 'BSD-3-Clause'], aliases: { 'Apache 2.0': 'Apache-2.0' }, ...more });

/** One PHP package and two npm packages (one needs the other), plus a tool that only builds. */
function base({ policyFile = policy(), extra = {} } = {}) {
    return project({
        'scripts/licence-policy.json': policyFile,
        'composer.lock': {
            packages: [{ name: 'acme/lib', version: '1.2.0', license: ['MIT'] }],
            'packages-dev': [{ name: 'acme/test', version: '1.0.0', license: ['GPL-3.0-only'] }],
        },
        'vendor/acme/lib/LICENSE': mit('Acme'),
        'package-lock.json': {
            packages: {
                '': { dependencies: { left: '^1.0.0' }, devDependencies: { tool: '^1.0.0' } },
                'node_modules/left': { version: '1.0.0', dependencies: { right: '^1.0.0' } },
                'node_modules/right': { version: '1.4.0' },
                'node_modules/tool': { version: '1.0.0', dev: true },
            },
        },
        'node_modules/left/package.json': { name: 'left', license: 'MIT' },
        'node_modules/left/LICENSE': mit('Left Co'),
        'node_modules/right/package.json': { name: 'right', license: 'ISC' },
        'node_modules/right/LICENSE':
            'ISC License\n\nCopyright (c) 2019 Right Co\n\nPermission to use, copy, modify, and/or distribute this software for any purpose.\n',
        'node_modules/tool/package.json': { name: 'tool', license: 'GPL-3.0-only' },
        ...extra,
    });
}

const problemsOf = (root, policyFile) => collect(root, policyFile).problems;

describe('licence expressions', () => {
    const allowed = new Set(['MIT', 'ISC', 'Apache-2.0']);
    const resolved = (text) => resolveLicence(parseExpression(text), allowed);

    test('a licence is allowed or it is not', () => {
        assert.equal(resolved('MIT'), 'MIT');
        assert.equal(resolved('GPL-3.0-only'), null);
    });

    test('OR takes the first allowed alternative, so the project can choose', () => {
        assert.equal(resolved('(MIT OR Apache-2.0)'), 'MIT');
        assert.equal(resolved('GPL-2.0-only OR BSD-3-Clause OR ISC'), 'ISC');
        assert.equal(resolved('GPL-2.0-only OR GPL-3.0-only'), null);
    });

    test('AND needs every part to be allowed', () => {
        assert.equal(resolved('MIT AND ISC'), 'MIT AND ISC');
        assert.equal(resolved('MIT AND GPL-3.0-only'), null);
    });

    test('AND binds tighter than OR, and brackets change that', () => {
        assert.equal(resolved('GPL-3.0-only AND MIT OR ISC'), 'ISC');
        assert.equal(resolved('GPL-3.0-only AND (MIT OR ISC)'), null);
    });

    test('an exception attached with WITH does not make a licence allowed', () => {
        assert.equal(resolved('GPL-2.0-only WITH Classpath-exception-2.0'), null);
        assert.equal(resolved('MIT WITH LLVM-exception'), 'MIT');
    });

    test('lower case operators, which old packages use, are read', () => {
        assert.equal(resolved('GPL-3.0-only or MIT'), 'MIT');
    });

    test('an expression that cannot be read is an error, not a pass', () => {
        for (const text of ['', '(MIT', 'MIT OR', 'MIT ISC', 'AND MIT', 'MIT)', 'MIT WITH'])
            assert.throws(() => parseExpression(text), /Cannot read/, text);
    });
});

describe('what a package says about its licence', () => {
    test('a string, an object, and the old list that means "either"', () => {
        assert.deepEqual(npmLicenceNames({ license: 'MIT' }), ['MIT']);
        assert.deepEqual(npmLicenceNames({ license: { type: 'ISC' } }), ['ISC']);
        assert.deepEqual(npmLicenceNames({ licenses: [{ type: 'MIT' }, { type: 'Apache 2.0' }] }), ['MIT', 'Apache 2.0']);
        assert.deepEqual(npmLicenceNames({}), []);
    });

    test('a name in the policy aliases is read as the licence it stands for', () => {
        const root = base({
            policyFile: policy(),
            extra: {
                'node_modules/right/package.json': {
                    name: 'right',
                    licenses: [{ type: 'Apache 2.0', url: 'http://www.apache.org/licenses/LICENSE-2.0.html' }],
                },
            },
        });

        assert.deepEqual(problemsOf(root, policy()), []);
        assert.equal(collect(root, policy()).packages.find((one) => one.name === 'right').licence, 'Apache-2.0');
    });
});

describe('which npm packages are shipped', () => {
    const lock = (packages) => ({ packages: { '': { dependencies: { a: '^1' }, devDependencies: { dev: '^1' } }, ...packages } });
    const names = (result) => result.paths.map((path) => path.replace(/^.*node_modules\//, ''));

    test('what dependencies reach is in, what only devDependencies reach is out', () => {
        const result = npmClosure(
            lock({
                'node_modules/a': { dependencies: { b: '^1' } },
                'node_modules/b': {},
                'node_modules/dev': { dev: true, dependencies: { c: '^1' } },
                'node_modules/c': { dev: true },
            }),
        );

        assert.deepEqual(names(result), ['a', 'b']);
    });

    test('a package finds its own copy of a dependency before the one at the top', () => {
        const result = npmClosure(
            lock({
                'node_modules/a': { dependencies: { b: '^2' } },
                'node_modules/a/node_modules/b': { version: '2.0.0' },
                'node_modules/b': { version: '1.0.0' },
            }),
        );

        assert.deepEqual(result.paths, ['node_modules/a', 'node_modules/a/node_modules/b']);
    });

    test('a nested package falls back to the folders above it', () => {
        const result = npmClosure(
            lock({
                'node_modules/a': { dependencies: { b: '^1' } },
                'node_modules/b': { dependencies: { c: '^1' } },
                'node_modules/b/node_modules/c': { dependencies: { d: '^1' } },
                'node_modules/d': {},
            }),
        );

        assert.deepEqual(names(result), ['a', 'b', 'c', 'd']);
    });

    test('an optional peer that the project installed for itself is not brought by the package', () => {
        const result = npmClosure(
            lock({
                'node_modules/a': { peerDependencies: { typescript: '*', vue: '*' }, peerDependenciesMeta: { typescript: { optional: true } } },
                'node_modules/typescript': {},
                'node_modules/vue': {},
            }),
        );

        assert.deepEqual(names(result), ['a', 'vue']);
    });

    test('build-time packages named in the policy are in, with what they need', () => {
        const result = npmClosure(lock({ 'node_modules/a': {}, 'node_modules/css': { dependencies: { helper: '^1' } }, 'node_modules/helper': {} }), [
            'css',
        ]);

        assert.deepEqual(names(result), ['a', 'css', 'helper']);
    });

    test('a required dependency that is not locked is reported, an optional one is not', () => {
        const result = npmClosure(lock({ 'node_modules/a': { dependencies: { gone: '^1' }, optionalDependencies: { 'only-on-linux': '^1' } } }));

        assert.deepEqual(names(result), ['a']);
        assert.equal(result.missing.length, 1);
        assert.match(result.missing[0], /gone/);
    });
});

describe('the audit', () => {
    test('allowed licences are no problem, and what only builds or tests is not looked at', () => {
        const result = collect(base(), policy());

        assert.deepEqual(result.problems, []);
        assert.deepEqual(result.packages.map((one) => `${one.ecosystem}:${one.name}`).sort(), ['composer:acme/lib', 'npm:left', 'npm:right']);
    });

    test('a licence that is not allowed is a problem that names the package and the licence', () => {
        const root = base({ extra: { 'node_modules/right/package.json': { name: 'right', license: 'GPL-3.0-only' } } });

        const problems = problemsOf(root, policy());

        assert.equal(problems.length, 1);
        assert.match(problems[0], /npm:right 1\.4\.0 is under GPL-3\.0-only/);
    });

    test('a package with no licence it names is a problem', () => {
        const root = base({ extra: { 'node_modules/right/package.json': { name: 'right' } } });

        assert.match(problemsOf(root, policy())[0], /npm:right .* no licence it names/);
    });

    test('an exception lets one package through, for the licence it was written for and no other', () => {
        const gpl = { 'node_modules/right/package.json': { name: 'right', license: 'LGPL-2.1' } };
        const excepted = policy({ exceptions: { 'npm:right': { licence: 'LGPL-2.1', reason: 'Used unmodified.' } } });

        const allowed = collect(base({ policyFile: excepted, extra: gpl }), excepted);

        assert.deepEqual(allowed.problems, []);
        assert.equal(allowed.packages.find((one) => one.name === 'right').excepted, true);

        const later = base({ policyFile: excepted, extra: { 'node_modules/right/package.json': { name: 'right', license: 'GPL-3.0-only' } } });

        assert.match(
            problemsOf(later, excepted)[0],
            /GPL-3\.0-only, which the policy does not allow \(the exception in the policy is for LGPL-2\.1\)/,
        );
    });

    test('an exception, a text or a first-party entry for a package that is not shipped is a problem', () => {
        const stale = policy({
            exceptions: { 'npm:removed': { licence: 'EPL-2.0', reason: 'x' } },
            fallbackTexts: { 'composer:gone/lib': { licence: 'MIT', copyright: 'Copyright (c) 2020 Gone', source: 'x' } },
            firstParty: ['composer:own/lib'],
        });

        const problems = problemsOf(base({ policyFile: stale }), stale);

        assert.equal(problems.length, 3);
        assert.match(problems.join('\n'), /`exceptions` for npm:removed/);
        assert.match(problems.join('\n'), /`fallbackTexts` for composer:gone\/lib/);
        assert.match(problems.join('\n'), /`firstParty` for composer:own\/lib/);
    });

    test("the project's own package is not third-party and is left out", () => {
        const own = policy({ firstParty: ['composer:acme/lib'] });
        const result = collect(base({ policyFile: own }), own);

        assert.deepEqual(result.problems, []);
        assert.equal(
            result.packages.some((one) => one.name === 'acme/lib'),
            false,
        );
    });

    test('a package that is not installed says which command to run; an optional one is only skipped', () => {
        const root = project({
            'composer.lock': { packages: [{ name: 'acme/lib', version: '1.0.0', license: ['MIT'] }] },
            'package-lock.json': {
                packages: {
                    '': { dependencies: { left: '^1' } },
                    'node_modules/left': { version: '1.0.0', optionalDependencies: { native: '^1' } },
                    'node_modules/native': { version: '1.0.0', optional: true },
                },
            },
            'node_modules/left/package.json': { name: 'left', license: 'MIT' },
            'node_modules/left/LICENSE': mit('Left Co'),
        });

        const result = collect(root, policy());

        assert.equal(result.problems.length, 1);
        assert.match(result.problems[0], /composer:acme\/lib is not installed .* `composer install`/);
        assert.deepEqual(result.skipped, ['npm:native (optional, not installed on this machine)']);
    });

    test('a package without a licence file is a problem until the policy holds its text', () => {
        const root = base();
        rmSync(join(root, 'node_modules/right/LICENSE'));
        writeFileSync(join(root, 'node_modules/right/README.md'), 'No licence here.');

        assert.match(problemsOf(root, policy())[0], /npm:right has no licence file/);

        const given = policy({ fallbackTexts: { 'npm:right': { licence: 'ISC', copyright: 'x', source: 'x' } } });
        // There is no standard text for ISC in the script, so this stays a problem rather than inventing one.
        assert.match(problemsOf(root, given)[0], /npm:right: the policy gives the text of ISC/);
    });

    test('a standard text is written from the policy for a package that ships none', () => {
        const root = base();
        rmSync(join(root, 'node_modules/left/LICENSE'));
        const given = policy({
            fallbackTexts: { 'npm:left': { licence: 'MIT', copyright: 'Copyright (c) 2015 Left Co', source: 'https://example.com/left' } },
        });

        const result = collect(root, given);
        const text = result.packages.find((one) => one.name === 'left').texts[0].text;

        assert.deepEqual(result.problems, []);
        assert.match(text, /^MIT License\n\nCopyright \(c\) 2015 Left Co\n\nPermission is hereby granted/);
        assert.doesNotMatch(text, /\{copyright\}/);
    });

    test('a text for a licence the package is not under is a problem', () => {
        const root = base({ extra: { 'node_modules/left/package.json': { name: 'left', license: 'ISC' } } });
        rmSync(join(root, 'node_modules/left/LICENSE'));
        const given = policy({ fallbackTexts: { 'npm:left': { licence: 'MIT', copyright: 'x', source: 'x' } } });

        assert.match(problemsOf(root, given)[0], /npm:left: the policy gives the text of MIT, but the package is under ISC/);
    });
});

describe('licence files', () => {
    test('LICENSE, COPYING, LICENSE.LGPL and NOTICE are read; code that has "license" in its name is not', () => {
        const root = project({
            'p/LICENSE': 'a',
            'p/COPYING.txt': 'b',
            'p/LICENSE.LGPL': 'c',
            'p/LICENSE-MIT': 'd',
            'p/NOTICE': 'e',
            'p/license.php': '<?php',
            'p/licenses.json': '{}',
            'p/README.md': 'f',
        });

        assert.deepEqual(
            readTexts(join(root, 'p')).map((one) => `${one.kind}:${one.file}`),
            ['licence:COPYING.txt', 'licence:LICENSE', 'licence:LICENSE-MIT', 'licence:LICENSE.LGPL', 'notice:NOTICE'],
        );
    });

    test('Windows line endings and a byte order mark are removed, so the text is the same everywhere', () => {
        const root = project({ 'p/LICENSE': '﻿line one\r\nline two\r\n' });

        assert.equal(readTexts(join(root, 'p'))[0].text, 'line one\nline two\n');
    });
});

describe('splitting a text into its copyright lines and the rest', () => {
    test('copyright lines are taken out, one space between words, and the rest is trimmed', () => {
        const { copyright, body } = splitText('MIT License\n\nCopyright (c)   2020   Acme\n© 2021 Other\n\nPermission is hereby granted.\n');

        assert.deepEqual(copyright, ['Copyright (c) 2020 Acme', '© 2021 Other']);
        assert.equal(body, 'MIT License\n\nPermission is hereby granted.');
    });

    test('words about copyright in the licence itself, and the Apache template, stay in the text', () => {
        const text =
            'Redistributions of source code must retain the above\n   copyright notice, this list of conditions\nTHIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS\n   Copyright [yyyy] [name of copyright owner]\nCopyright (c) <year> <copyright holders>';
        const { copyright, body } = splitText(text);

        assert.deepEqual(copyright, []);
        assert.equal(body, text);
    });

    test('the numbered clauses of the Apache licence stay in the text, including "(c) You must retain"', () => {
        const clauses = [
            '4. Redistribution. You may reproduce and distribute copies of the',
            '      Work or Derivative Works thereof in any medium, with or without',
            '      modifications, and in Source or Object form, provided that You',
            '      meet the following conditions:',
            '',
            '      (a) You must give any other recipients of the Work or',
            '          Derivative Works a copy of this License; and',
            '',
            '      (b) You must cause any modified files to carry prominent notices',
            '          stating that You changed the files; and',
            '',
            '      (c) You must retain, in the Source form of any Derivative Works',
            '          that You distribute, all copyright, patent, trademark, and',
            '          attribution notices from the Source form of the Work,',
            '          excluding those notices that do not pertain to any part of',
            '          the Derivative Works; and',
            '',
            '      (d) If the Work includes a "NOTICE" text file as part of its',
            '          distribution, then any Derivative Works that You distribute must',
            '          include a readable copy of the attribution notices contained',
            '          within such NOTICE file.',
        ].join('\n');
        const { copyright, body } = splitText(`${clauses}\n\nCopyright 2016 Acme Co\n`);

        assert.deepEqual(copyright, ['Copyright 2016 Acme Co']);
        assert.equal(body, clauses);
    });

    test('a notice that starts with (c) is still taken out when a year or the word Copyright follows', () => {
        const { copyright, body } = splitText('(c) 2020 Acme\n(C) Copyright 2019 Other\n(c) You must keep this line.\n');

        assert.deepEqual(copyright, ['(c) 2020 Acme', '(C) Copyright 2019 Other']);
        assert.equal(body, '(c) You must keep this line.');
    });
});

describe('the notices', () => {
    const found = (root) => collect(root, policy());

    test('packages under the same text share it, and each keeps its own copyright line', () => {
        const root = base({
            extra: {
                'node_modules/right/package.json': { name: 'right', license: 'MIT' },
                'node_modules/right/LICENSE': mit('Right Co'),
            },
        });
        const notices = renderNotices(found(root).packages, policy());

        assert.equal([...notices.matchAll(/^### MIT \(/gm)].length, 1);
        assert.match(notices, /Used by 3 packages: acme\/lib, left, right\./);
        assert.match(notices, /\| MIT \| Copyright \(c\) 2020 Acme \|/);
        assert.match(notices, /\| MIT \| Copyright \(c\) 2020 Left Co \|/);
        assert.match(notices, /\| MIT \| Copyright \(c\) 2020 Right Co \|/);
    });

    test('a different text is a different entry', () => {
        const notices = renderNotices(found(base()).packages, policy());

        assert.equal([...notices.matchAll(/^### (MIT|ISC) \(/gm)].length, 2);
    });

    test('the same input gives the same file whatever order it came in, and no version is in it', () => {
        const packages = found(base()).packages;

        assert.equal(renderNotices(packages, policy()), renderNotices([...packages].reverse(), policy()));
        assert.doesNotMatch(renderNotices(packages, policy()), /1\.2\.0|1\.4\.0/);
    });

    test('two versions of a package are one row, and the row names both licences', () => {
        const root = base({
            extra: {
                'package-lock.json': {
                    packages: {
                        '': { dependencies: { left: '^1', old: '^1' } },
                        'node_modules/left': { version: '1.0.0', dependencies: { right: '^2' } },
                        'node_modules/left/node_modules/right': { version: '2.0.0' },
                        'node_modules/old': { version: '1.0.0', dependencies: { right: '^1' } },
                        'node_modules/right': { version: '1.4.0' },
                    },
                },
                'node_modules/old/package.json': { name: 'old', license: 'MIT' },
                'node_modules/old/LICENSE': mit('Old Co'),
                'node_modules/left/node_modules/right/package.json': { name: 'right', license: 'BSD-3-Clause' },
                'node_modules/left/node_modules/right/LICENSE':
                    'BSD 3-Clause License\n\nCopyright (c) 2022 Right Co\n\nRedistribution and use in source and binary forms are permitted.\n',
            },
        });
        const notices = renderNotices(found(root).packages, policy());

        assert.equal([...notices.matchAll(/^\| \[right\]/gm)].length, 1);
        assert.match(notices, /\| BSD-3-Clause, ISC \|/);
        // Each text is headed with the licence it is, not with the two the row names.
        assert.deepEqual([...notices.matchAll(/^### (.+) \([0-9a-f]{6}\)$/gm)].map((match) => match[1]).sort(), ['BSD-3-Clause', 'ISC', 'MIT']);
    });

    test('an exception is named in its own section and linked from its row', () => {
        const excepted = policy({ exceptions: { 'npm:right': { licence: 'LGPL-2.1', reason: 'Used unmodified, as installed.' } } });
        const root = base({ policyFile: excepted, extra: { 'node_modules/right/package.json': { name: 'right', license: 'LGPL-2.1' } } });
        const notices = renderNotices(collect(root, excepted).packages, excepted);

        assert.match(notices, /## Packages that ask for more than a notice/);
        assert.match(notices, /- \*\*right\*\* \(LGPL-2\.1\): Used unmodified, as installed\./);
        assert.match(notices, /\[LGPL-2\.1\]\(#exceptions\)/);
    });

    test('a pipe in a copyright line does not break the table, and a fence is longer than any run of backticks in a text', () => {
        const root = base({ extra: { 'node_modules/left/LICENSE': 'Copyright (c) 2020 A | B\n\nUse ```code``` freely.\n' } });
        const notices = renderNotices(found(root).packages, policy());

        assert.match(notices, /Copyright \(c\) 2020 A \\\| B/);
        assert.match(notices, /^````\nUse ```code``` freely\.\n````$/m);
    });

    test('assets from the policy are listed', () => {
        const assets = policy({
            assets: [
                {
                    name: 'Some font',
                    licence: 'OFL-1.1',
                    copyright: 'Copyright 2021 The Authors',
                    source: 'https://example.com/font',
                    where: 'Served from our own origin.',
                },
            ],
        });
        const notices = renderNotices(found(base()).packages, assets);

        assert.match(
            notices,
            /### Some font\n\n- Licence: OFL-1\.1\n- Notice: Copyright 2021 The Authors\n- Source: https:\/\/example\.com\/font\n- Use: Served from our own origin\./,
        );
    });
});

describe('the command', () => {
    const lines = () => {
        const out = [];

        return { out, log: (line) => out.push(line) };
    };

    test('--write makes the file, and the check then passes', () => {
        const root = base();
        const { out, log } = lines();

        assert.equal(run(['--write'], root, log), 0);
        assert.match(readFileSync(join(root, 'THIRD-PARTY-NOTICES.md'), 'utf8'), /^# Third-party notices/);

        assert.equal(run([], root, log), 0);
        assert.match(out.at(-1), /Licences are in order: 1 PHP and 2 npm packages, 0 by exception\. THIRD-PARTY-NOTICES\.md is up to date\./);
    });

    test('a missing file fails the check and says how to make it', () => {
        const { out, log } = lines();

        assert.equal(run([], base(), log), 1);
        assert.match(out.join('\n'), /THIRD-PARTY-NOTICES\.md is out of date[\s\S]*npm run licences:notices/);
    });

    test('a new dependency fails the check by name until the notices are written again', () => {
        const root = base();
        run(['--write'], root, () => {});

        const lock = JSON.parse(readFileSync(join(root, 'composer.lock'), 'utf8'));
        lock.packages.push({ name: 'acme/new', version: '1.0.0', license: ['MIT'] });
        writeFileSync(join(root, 'composer.lock'), JSON.stringify(lock));
        mkdirSync(join(root, 'vendor/acme/new'), { recursive: true });
        writeFileSync(join(root, 'vendor/acme/new/LICENSE'), mit('New Co'));

        const { out, log } = lines();

        assert.equal(run([], root, log), 1);
        assert.match(out.join('\n'), /new in the lock files: acme\/new/);

        assert.equal(
            run(['--write'], root, () => {}),
            0,
        );
        assert.equal(
            run([], root, () => {}),
            0,
        );
    });

    test('a package that is gone fails the check by name', () => {
        const root = base();
        run(['--write'], root, () => {});
        writeFileSync(join(root, 'composer.lock'), JSON.stringify({ packages: [] }));

        const { out, log } = lines();

        assert.equal(run([], root, log), 1);
        assert.match(out.join('\n'), /no longer shipped: acme\/lib/);
    });

    test('a changed licence text fails the check even when no package came or went', () => {
        const root = base();
        run(['--write'], root, () => {});
        writeFileSync(join(root, 'vendor/acme/lib/LICENSE'), mit('Acme').replace('Permission', 'Changed permission'));

        const { out, log } = lines();

        assert.equal(run([], root, log), 1);
        assert.match(out.join('\n'), /a licence or a licence text changed/);
    });

    test('a file with Windows line endings, as git on Windows may check it out, is the same file', () => {
        const root = base();
        run(['--write'], root, () => {});
        const file = join(root, 'THIRD-PARTY-NOTICES.md');
        writeFileSync(file, readFileSync(file, 'utf8').replace(/\n/g, '\r\n'));

        assert.equal(
            run([], root, () => {}),
            0,
        );
    });

    test('a licence that is not allowed fails, lists the problem, and writes nothing', () => {
        const root = base({ extra: { 'node_modules/right/package.json': { name: 'right', license: 'GPL-3.0-only' } } });
        const { out, log } = lines();

        assert.equal(run(['--write'], root, log), 1);
        assert.match(out.join('\n'), /Licence check failed \(1\):\n {2}- npm:right 1\.4\.0 is under GPL-3\.0-only/);
        assert.throws(() => readFileSync(join(root, 'THIRD-PARTY-NOTICES.md')), /ENOENT/);
    });
});

describe('the policy of this project', () => {
    const file = JSON.parse(readFileSync(join(dirname(fileURLToPath(import.meta.url)), 'licence-policy.json'), 'utf8'));

    test('allows permissive licences only: a copyleft licence comes in by an exception, with a reason', () => {
        for (const id of file.allowed) assert.doesNotMatch(id, /GPL|MPL|EPL|CDDL|SSPL|OSL|CC-BY|proprietary/i, id);

        for (const [key, one] of Object.entries(file.exceptions)) {
            assert.ok(one.licence, `${key} names the licence it is an exception for`);
            assert.ok(one.reason.length > 40, `${key} says why`);
            assert.ok(!file.allowed.includes(one.licence), `${key} would not need an exception`);
        }
    });

    test('every text it holds for a package is a licence the script has a standard text for', () => {
        for (const [key, one] of Object.entries(file.fallbackTexts)) {
            assert.equal(one.licence, 'MIT', key);
            assert.match(one.copyright, /^Copyright /, key);
            assert.match(one.source, /^https:\/\//, key);
        }
    });
});
