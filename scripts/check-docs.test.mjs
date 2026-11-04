import assert from 'node:assert/strict';
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { after, describe, test } from 'node:test';
import { check, documents, read, run, slug } from './check-docs.mjs';

const made = [];

after(() => made.forEach((dir) => rmSync(dir, { recursive: true, force: true })));

/** A project folder with the given files. */
function project(files) {
    const root = mkdtempSync(join(tmpdir(), 'docs-'));
    made.push(root);

    for (const [path, content] of Object.entries(files)) {
        mkdirSync(dirname(join(root, path)), { recursive: true });
        writeFileSync(join(root, path), content);
    }

    return root;
}

const problemsOf = (files) => check(project(files)).problems.map(({ file, line, message }) => `${file}:${line} ${message}`);

describe('slug', () => {
    test('follows the way GitHub makes an anchor', () => {
        assert.equal(slug('Production & SSR Builds'), 'production--ssr-builds');
        assert.equal(slug('The `.env` file'), 'the-env-file');
        assert.equal(slug('What is *not* cached?'), 'what-is-not-cached');
        assert.equal(slug('[A link](https://example.com) in a heading'), 'a-link-in-a-heading');
        assert.equal(slug('Über uns'), 'über-uns');
        assert.equal(slug('snake_case and kebab-case'), 'snake_case-and-kebab-case');
    });
});

describe('read', () => {
    test('gives repeated headings a number, like GitHub', () => {
        const { anchors } = read('# Setup\n\n## Notes\n\n## Notes\n\n## Notes\n');

        assert.deepEqual([...anchors].sort(), ['notes', 'notes-1', 'notes-2', 'setup']);
    });

    test('reads the id and name attributes of HTML as anchors', () => {
        const { anchors } = read('<a id="top"></a>\n<a name="legacy"></a>\n');

        assert.ok(anchors.has('top'));
        assert.ok(anchors.has('legacy'));
    });

    test('finds links, images, definitions and HTML sources with their line', () => {
        const { links } = read(
            [
                'See [the guide](docs/guide.md#start) and ![logo](docs/assets/logo.png "The logo").',
                '',
                '[ref]: docs/reference.md',
                '<img src="a.png" srcset="b.png 1x, c.png 2x">',
                '<a href="x.md#y">y</a>',
            ].join('\n'),
        );

        assert.deepEqual(links, [
            { line: 1, target: 'docs/guide.md#start' },
            { line: 1, target: 'docs/assets/logo.png' },
            { line: 3, target: 'docs/reference.md' },
            { line: 4, target: 'a.png' },
            { line: 4, target: 'b.png' },
            { line: 4, target: 'c.png' },
            { line: 5, target: 'x.md#y' },
        ]);
    });

    test('does not read code: fenced blocks and inline spans show links, they are not links', () => {
        const { links, anchors } = read(
            [
                'Write it as `[text](missing.md)`.',
                '',
                '```md',
                '[text](also-missing.md)',
                '# Not a heading',
                '```',
                '',
                '~~~',
                '[x](y.md)',
                '~~~',
                '',
                '[real](real.md)',
            ].join('\n'),
        );

        assert.deepEqual(links, [{ line: 12, target: 'real.md' }]);
        assert.equal(anchors.size, 0);
    });

    test('a longer fence is closed by a fence at least as long, and not by a shorter one', () => {
        const { links } = read(['````md', '```', '[x](inside.md)', '```', '````', '[y](outside.md)'].join('\n'));

        assert.deepEqual(links, [{ line: 6, target: 'outside.md' }]);
    });

    test('takes a target with parentheses and an angle-bracket target', () => {
        const { links } = read('[a](docs/Some_(thing).md) [b](<docs/with space.md>)');

        assert.deepEqual(
            links.map((link) => link.target),
            ['docs/Some_(thing).md', 'docs/with space.md'],
        );
    });
});

describe('documents', () => {
    test('reads the files at the top, everything under docs/, and not the pictures', () => {
        const root = project({
            'README.md': '',
            'CHANGELOG.md': '',
            'NOTES.md': '',
            'docs/a.md': '',
            'docs/deep/b.md': '',
            'docs/assets/c.md': '',
            'docs/pic.png': '',
        });

        assert.deepEqual(documents(root), ['CHANGELOG.md', 'README.md', 'docs/a.md', 'docs/deep/b.md']);
    });
});

describe('check', () => {
    test('is silent about a correct set of documents', () => {
        const root = project({
            'README.md': '# Home\n\nSee [install](docs/install.md#production--ssr-builds), [the picture](docs/assets/logo.png) and [here](#home).\n',
            'docs/install.md': '# Install\n\n## Production & SSR Builds\n\nBack to the [README](../README.md#home) or [the folder](../docs).\n',
            'docs/assets/logo.png': 'x',
        });
        const result = check(root);

        assert.deepEqual(result.problems, []);
        assert.equal(result.documents, 2);
        assert.equal(result.links, 5);
    });

    test('names a file that is not there, with its line', () => {
        assert.deepEqual(problemsOf({ 'README.md': '# Home\n\n[gone](docs/gone.md)\n' }), [
            'README.md:3 docs/gone.md is not a file or a folder of this repository',
        ]);
    });

    test('names a heading that is not there, on this page and on another', () => {
        assert.deepEqual(
            problemsOf({
                'README.md': '# Home\n\n[a](#nowhere) [b](docs/x.md#missing) [c](docs/x.md#there)\n',
                'docs/x.md': '## There\n',
            }),
            [
                'README.md:3 #nowhere: this page has no heading or id "nowhere"',
                'README.md:3 docs/x.md#missing: docs/x.md has no heading or id "missing"',
            ],
        );
    });

    test('says so when only the case of an anchor is wrong', () => {
        assert.deepEqual(problemsOf({ 'README.md': '## Setup\n\n[x](#Setup)\n' }), [
            'README.md:3 #Setup: the page has #setup, and anchors are case sensitive',
        ]);
    });

    test('resolves links relative to the page and a leading slash from the root', () => {
        const root = project({
            'docs/a/one.md': '[up](../top.md) [root](/README.md) [sibling](two.md)\n',
            'docs/a/two.md': '',
            'docs/top.md': '',
            'README.md': '',
        });

        assert.deepEqual(check(root).problems, []);
    });

    test('does not let a link leave the repository', () => {
        assert.deepEqual(problemsOf({ 'README.md': '[out](../outside.md)\n' }), [
            'README.md:1 ../outside.md is not a file or a folder of this repository',
        ]);
    });

    test('does not follow addresses on the web, mail links or protocol-relative links', () => {
        assert.deepEqual(problemsOf({ 'README.md': '[a](https://example.com/x) [b](mailto:a@b.c) [c](//cdn.example.com/x.css) [d](tel:123)\n' }), []);
    });

    test('knows a heading with a query or an encoded character in the link', () => {
        const root = project({
            'README.md': '[a](docs/x.md?plain=1#caf%C3%A9) [b](docs/my%20file.md)\n',
            'docs/x.md': '## Café\n',
            'docs/my file.md': '',
        });

        assert.deepEqual(check(root).problems, []);
    });

    test('does not look for a heading in a link to a line of source code or to a picture', () => {
        const root = project({ 'README.md': '[a](app/Foo.php#L10) [b](docs/p.png#x)\n', 'app/Foo.php': '<?php', 'docs/p.png': 'x' });

        assert.deepEqual(check(root).problems, []);
    });

    test('reads a Markdown file that is outside docs/ when a link points into it', () => {
        const root = project({ 'README.md': '[a](notes/n.md#there) [b](notes/n.md#gone)\n', 'notes/n.md': '# There\n' });

        assert.deepEqual(
            check(root).problems.map((problem) => problem.message),
            ['notes/n.md#gone: notes/n.md has no heading or id "gone"'],
        );
    });
});

describe('run', () => {
    test('returns 0 and says how much it looked at', () => {
        const lines = [];
        const code = run([], project({ 'README.md': '[x](docs/a.md)\n', 'docs/a.md': '' }), (line) => lines.push(line));

        assert.equal(code, 0);
        assert.deepEqual(lines, ['Documentation links are in order: 1 links in 2 documents.']);
    });

    test('returns 1 and lists each broken link', () => {
        const lines = [];
        const code = run([], project({ 'README.md': '[x](nope.md)\n[y](#nope)\n' }), (line) => lines.push(line));

        assert.equal(code, 1);
        assert.deepEqual(lines, [
            'README.md:1: nope.md is not a file or a folder of this repository',
            'README.md:2: #nope: this page has no heading or id "nope"',
            '\n2 broken links in 1 documents.',
        ]);
    });
});
