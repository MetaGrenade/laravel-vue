// The time-to-first-byte check can fail a deploy, so it has tests of its own: `npm run bundle:test` runs them with the other scripts'.

import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import http from 'node:http';
import { after, describe, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { gzipSync } from 'node:zlib';
import { check, measure, sample, summarise, uncompressed } from './measure-ttfb.mjs';

const script = fileURLToPath(new URL('./measure-ttfb.mjs', import.meta.url));
const servers = [];
const hits = new Map();
const html = '<p>x</p>'.repeat(1000);

/**
 * A small site: /slow waits 40 ms before it answers, /moved redirects, /missing is a 404, /packed is compressed, /plain is not, and
 * /flaky fails twice with a 500 (at once) and then is served (after 40 ms), as a page does that breaks now and then.
 */
async function site() {
    let flaky = 0;
    const server = http.createServer((request, response) => {
        hits.set(request.url, (hits.get(request.url) ?? 0) + 1);

        const answer = (status, headers, body = '') => {
            response.writeHead(status, headers);
            response.end(body);
        };

        switch (request.url) {
            case '/slow':
                return setTimeout(() => answer(200, { 'content-type': 'text/html' }, 'late'), 40);
            case '/flaky':
                return ++flaky <= 2
                    ? answer(500, { 'content-type': 'text/html' }, 'oops')
                    : setTimeout(() => answer(200, { 'content-type': 'text/html' }, 'fine'), 40);
            case '/moved':
                return answer(302, { location: '/slow' });
            case '/missing':
                return answer(404, { 'content-type': 'text/html' }, 'no');
            case '/packed':
                return answer(200, { 'content-type': 'text/html', 'content-encoding': 'gzip' }, gzipSync(html));
            case '/plain':
                return answer(200, { 'content-type': 'text/html' }, html);
            default:
                return answer(200, { 'content-type': 'text/html' }, 'ok');
        }
    });

    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    servers.push(server);

    return `http://127.0.0.1:${server.address().port}`;
}

after(() => servers.forEach((server) => server.close()));

describe('summarise', () => {
    test('takes the middle sample, the one nine in ten are under, and the quickest', () => {
        assert.deepEqual(summarise([50, 10, 30, 20, 40]), { median: 30, p90: 50, min: 10 });
    });

    test('nine in ten are under the p90: with enough samples it is not the slowest', () => {
        const times = Array.from({ length: 20 }, (_, i) => i + 1);

        assert.equal(summarise(times).p90, 18);
        assert.equal(summarise(times.slice(0, 10)).p90, 9);
    });

    test('the middle of an even number is between the two in the middle', () => {
        assert.equal(summarise([10, 20, 30, 40]).median, 25);
    });

    test('one slow request does not move the middle', () => {
        assert.equal(summarise([20, 21, 22, 23, 900]).median, 22);
    });

    test('has nothing to say about no samples', () => {
        assert.throws(() => summarise([]), /nothing to summarise/);
    });
});

describe('sample', () => {
    test('counts the time until the answer starts, and the bytes that came', async () => {
        const base = await site();
        const slow = await sample(new URL('/slow', base));
        const quick = await sample(new URL('/', base));

        assert.equal(slow.status, 200);
        assert.ok(slow.ttfb >= 35, `waited ${slow.ttfb} ms`);
        assert.ok(quick.ttfb < slow.ttfb);
        assert.equal(slow.bytes, 4);
    });

    test('reports how a page was compressed', async () => {
        const base = await site();

        assert.equal((await sample(new URL('/packed', base))).encoding, 'gzip');
        assert.equal((await sample(new URL('/plain', base))).encoding, null);
    });

    test('gives up on a server that never answers', async () => {
        const silent = http.createServer(() => {});
        await new Promise((resolve) => silent.listen(0, '127.0.0.1', resolve));
        servers.push(silent);

        await assert.rejects(sample(new URL(`http://127.0.0.1:${silent.address().port}/`), { timeout: 100 }), /no answer within 100 ms/);
    });
});

describe('measure', () => {
    test('asks for each page the warm-up and then the runs, and only the runs are the samples', async () => {
        const base = await site();
        hits.clear();

        const [page] = await measure(base, ['/slow'], { runs: 4, warmup: 2 });

        assert.equal(hits.get('/slow'), 6);
        assert.equal(page.path, '/slow');
        assert.ok(page.median >= 35 && page.min >= 35, 'every sample waited for the slow answer');
    });

    test('counts the answers that were not 200, not only the last one', async () => {
        const base = await site();
        const [flaky] = await measure(base, ['/flaky'], { runs: 3, warmup: 0 });

        assert.equal(flaky.status, 500, 'the answer to show is the one that failed, and not the 200 that came last');
        assert.equal(flaky.failed, 2);
        assert.equal(flaky.runs, 3);
    });

    test('times the answers that were served, not the errors that came back at once', async () => {
        const base = await site();
        const [flaky] = await measure(base, ['/flaky'], { runs: 3, warmup: 0 });

        assert.ok(flaky.min >= 35, `the median is ${flaky.median} ms and the fastest ${flaky.min} ms, which are the errors`);
    });

    test('a page that always answers 200 has not failed', async () => {
        const base = await site();
        const [plain] = await measure(base, ['/plain'], { runs: 3, warmup: 0 });

        assert.deepEqual([plain.status, plain.failed, plain.runs], [200, 0, 3]);
    });

    test('keeps the status, the size and the compression of the page', async () => {
        const base = await site();
        const [packed, plain] = await measure(base, ['/packed', '/plain'], { runs: 1, warmup: 0 });

        assert.equal(packed.encoding, 'gzip');
        assert.ok(packed.bytes < plain.bytes / 10, `${packed.bytes} on the wire against ${plain.bytes}`);
        assert.equal(plain.bytes, html.length);
    });
});

describe('check', () => {
    const page = (path, status, median, extra = {}) => ({
        path,
        status,
        failed: status === 200 ? 0 : 1,
        runs: 1,
        median,
        p90: median,
        min: median,
        bytes: 100,
        encoding: null,
        type: 'text/html',
        location: null,
        ...extra,
    });

    test('passes pages that answer in time', () => {
        assert.deepEqual(check([page('/', 200, 120), page('/forum', 200, 199)], 200), []);
    });

    test('a page exactly at the budget is within it', () => {
        assert.deepEqual(check([page('/', 200, 200)], 200), []);
        assert.equal(check([page('/', 200, 200.5)], 200).length, 1);
    });

    test('names the page that is too slow and by how much', () => {
        const problems = check([page('/', 200, 80), page('/forum', 200, 340.4)], 200);

        assert.equal(problems.length, 1);
        assert.match(problems[0], /\/forum starts answering after 340 ms .* over the 200 ms/);
    });

    test('a redirect is not a measurement: it says where to look instead', () => {
        const [problem] = check([page('/', 302, 10, { location: '/en' })], 200);

        assert.match(problem, /\/ answers 302, a redirect to \/en/);
    });

    test('a page that fails now and then is failing, and the message says how often', () => {
        const [problem] = check([page('/flaky', 500, 40, { failed: 2, runs: 3 })], 200);

        assert.match(problem, /^\/flaky answers 500 in 2 of 3 requests, not 200/);
    });

    test('a redirect that comes only now and then is named as one', () => {
        const [problem] = check([page('/', 302, 40, { failed: 1, runs: 3, location: '/en' })], 200);

        assert.match(problem, /^\/ answers 302 in 1 of 3 requests, a redirect to \/en/);
    });

    test('a page that fails every time does not say how often', () => {
        assert.doesNotMatch(check([page('/boom', 500, 3, { failed: 3, runs: 3 })], 200)[0], / of 3 requests/);
    });

    test('a page that fails is a problem however fast the failure is', () => {
        assert.match(check([page('/missing', 404, 3)], 200)[0], /answers 404, not 200/);
        assert.match(check([page('/boom', 500, 3)], 200)[0], /answers 500, not 200/);
    });
});

describe('uncompressed', () => {
    const page = (path, extra) => ({
        path,
        status: 200,
        failed: 0,
        runs: 1,
        bytes: 140_000,
        encoding: null,
        type: 'text/html; charset=utf-8',
        ...extra,
    });

    test('lists the large text pages that arrive as they were written', () => {
        const found = uncompressed([
            page('/'),
            page('/packed', { encoding: 'gzip' }),
            page('/small', { bytes: 300 }),
            page('/logo', { type: 'image/png' }),
        ]);

        assert.deepEqual(
            found.map((p) => p.path),
            ['/'],
        );
    });

    test('does not list a page that failed now and then', () => {
        assert.deepEqual(uncompressed([page('/flaky', { failed: 1, runs: 3 })]), []);
    });

    test('does not list a page that failed', () => {
        assert.deepEqual(uncompressed([page('/gone', { status: 404, failed: 1 })]), []);
    });
});

describe('the command', () => {
    // Asynchronous, so this process can go on answering the requests the command makes to the servers it started.
    const run = (...args) =>
        new Promise((resolve) => {
            const child = spawn(process.execPath, [script, ...args]);
            let stdout = '';
            let stderr = '';

            child.stdout.on('data', (chunk) => (stdout += chunk));
            child.stderr.on('data', (chunk) => (stderr += chunk));
            child.on('close', (status) => resolve({ status, stdout, stderr }));
        });

    test('exits 0 and says so when every page is quick', async () => {
        const base = await site();
        const result = await run(base, '--runs=2', '--warmup=1', '--path=/', '--path=/packed');

        assert.equal(result.status, 0, result.stderr);
        assert.match(result.stdout, /Every page starts answering in under 200 ms/);
        assert.match(result.stdout, /\/packed .* gzip/);
    });

    test('exits 1 and names the page when one is over the budget', async () => {
        const base = await site();
        const result = await run(base, '--runs=2', '--warmup=0', '--path=/slow', '--budget=10');

        assert.equal(result.status, 1);
        assert.match(result.stderr, /\/slow starts answering after \d+ ms/);
    });

    test('exits 1 for a page that failed twice in three requests though the last one was served', async () => {
        const base = await site();
        const result = await run(base, '--runs=3', '--warmup=0', '--path=/flaky');

        assert.equal(result.status, 1);
        assert.match(result.stderr, /\/flaky answers 500 in 2 of 3 requests/);
        assert.doesNotMatch(result.stdout, /Every page starts answering/);
    });

    test('says when a page arrives uncompressed, and still passes', async () => {
        const base = await site();
        const result = await run(base, '--runs=1', '--warmup=0', '--path=/plain');

        assert.equal(result.status, 0);
        assert.match(result.stdout, /1 of these arrive uncompressed: \/plain/);
    });

    test('exits 2 with the usage when it is not told which site', async () => {
        const result = await run();

        assert.equal(result.status, 2);
        assert.match(result.stderr, /Usage: npm run ttfb/);
    });

    test('exits 2 when a number is not a number', async () => {
        const base = await site();

        const none = await run(base, '--runs=0');
        const soon = await run(base, '--budget=soon');

        assert.equal(none.status, 2);
        assert.match(none.stderr, /--runs must be 1 or more/);
        assert.equal(soon.status, 2);
        assert.match(soon.stderr, /--budget 1 or more/);
    });

    test('exits 2 when nothing answers at that address', async () => {
        const result = await run('http://127.0.0.1:1', '--runs=1', '--warmup=0');

        assert.equal(result.status, 2);
        assert.match(result.stderr, /Could not reach/);
    });
});
