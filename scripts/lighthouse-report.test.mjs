import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import fs from 'node:fs';
import http from 'node:http';
import net from 'node:net';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { after, describe, test } from 'node:test';
import { fileURLToPath, pathToFileURL } from 'node:url';
import {
    PAGES,
    STABLE_AUDITS,
    argumentsFor,
    audit,
    auditWithRetry,
    inUse,
    isTransientLighthouseFailure,
    stableProblems,
    summarise,
    table,
    waitForSite,
    warm,
} from './lighthouse-report.mjs';

const script = path.join(path.dirname(fileURLToPath(import.meta.url)), 'lighthouse-report.mjs');
const made = [];
const servers = [];

after(async () => {
    made.forEach((dir) => fs.rmSync(dir, { recursive: true, force: true }));
    // A server that stalls on purpose still holds the connection a test gave up on, and close() waits for it.
    servers.forEach((server) => server.closeAllConnections?.());
    await Promise.all(servers.map((server) => new Promise((resolve) => server.close(resolve))));
});

function folder() {
    const dir = fs.mkdtempSync(path.join(tmpdir(), 'lighthouse-'));
    made.push(dir);

    return dir;
}

/** A report with every stable audit passing, the way a good page comes back. */
function report(more = {}) {
    return {
        categories: { performance: { score: 0.987 }, accessibility: { score: 1 }, 'best-practices': { score: 0.96 }, seo: { score: 0.69 } },
        audits: {
            'first-contentful-paint': { numericValue: 1100, score: 0.99 },
            'largest-contentful-paint': { numericValue: 1180, score: 1 },
            'total-blocking-time': { numericValue: 59.4, score: 1 },
            'cumulative-layout-shift': { numericValue: 0.0123, score: 1 },
            'total-byte-weight': { numericValue: 1265697, score: 1 },
            'image-aspect-ratio': { score: 1, scoreDisplayMode: 'binary' },
            'image-size-responsive': { score: 1, scoreDisplayMode: 'binary' },
            'unsized-images': { score: 1, scoreDisplayMode: 'metricSavings' },
            'http-status-code': { score: 1, scoreDisplayMode: 'binary' },
            'document-latency-insight': { score: 1, scoreDisplayMode: 'metricSavings', details: { items: { usesCompression: { value: true } } } },
        },
        ...more,
    };
}

/** The report with one audit replaced. */
function with_(id, audit) {
    const base = report();

    return { ...base, audits: { ...base.audits, [id]: audit } };
}

/** The same page sent as the built-in PHP server sends it, which does not compress. */
const uncompressed = () =>
    with_('document-latency-insight', { score: 0.5, scoreDisplayMode: 'metricSavings', details: { items: { usesCompression: { value: false } } } });
const failedImage = (id, urls) => with_(id, { score: 0, scoreDisplayMode: 'binary', details: { items: urls.map((url) => ({ url, node: {} })) } });

describe('the numbers taken from a report', () => {
    test('scores are whole numbers out of a hundred, and the timings are as Lighthouse measured them', () => {
        assert.deepEqual(summarise(report()), {
            performance: 99,
            accessibility: 100,
            bestPractices: 96,
            seo: 69,
            fcp: 1100,
            lcp: 1180,
            tbt: 59.4,
            cls: 0.0123,
            bytes: 1265697,
        });
    });

    test('what a report does not have is null, not zero', () => {
        const summary = summarise({ categories: { performance: { score: null } }, audits: {} });

        assert.equal(summary.performance, null);
        assert.equal(summary.seo, null);
        assert.equal(summary.lcp, null);
    });
});

describe('what fails a run', () => {
    test('a good page has nothing to fix', () => {
        assert.deepEqual(stableProblems(report()), []);
        assert.deepEqual(stableProblems(report(), { server: true }), []);
    });

    test('an image shown at another aspect ratio than its file names the image', () => {
        const problems = stableProblems(
            failedImage('image-aspect-ratio', ['http://127.0.0.1:8200/brand/logo-light.png', 'http://127.0.0.1:8200/brand/logo-light.png']),
        );

        assert.equal(problems.length, 1);
        assert.match(problems[0], /another aspect ratio than their file has.*: \/brand\/logo-light\.png\.$/);
    });

    test('an image shown larger than its file, and an image without a width and a height, fail too', () => {
        assert.match(stableProblems(failedImage('image-size-responsive', ['http://x/a.png']))[0], /larger than their file.*: \/a\.png\./);
        assert.match(stableProblems(failedImage('unsized-images', ['http://x/b.png']))[0], /without a width and a height.*: \/b\.png\./);
    });

    test('a page that answers with an error fails', () => {
        assert.match(stableProblems(with_('http-status-code', { score: 0, scoreDisplayMode: 'binary' }))[0], /error status/);
    });

    test('an audit that does not apply, or was not run, does not fail', () => {
        assert.deepEqual(stableProblems(with_('image-aspect-ratio', { score: null, scoreDisplayMode: 'notApplicable' })), []);
        assert.deepEqual(stableProblems(with_('image-aspect-ratio', { score: 0, scoreDisplayMode: 'informative' })), []);
        assert.deepEqual(stableProblems({ audits: {} }), []);
    });

    test('nothing that moves with the machine fails: not a score, not a time, not what a server could do for the page', () => {
        const slow = with_('largest-contentful-paint', { numericValue: 9000, score: 0 });
        slow.categories.performance.score = 0.1;
        slow.audits['cache-insight'] = { score: 0, scoreDisplayMode: 'metricSavings' };
        slow.audits['render-blocking-insight'] = { score: 0, scoreDisplayMode: 'metricSavings' };
        slow.audits['image-delivery-insight'] = { score: 0, scoreDisplayMode: 'metricSavings' };

        assert.deepEqual(stableProblems(slow), []);
        assert.deepEqual(stableProblems(slow, { server: true }), []);
    });

    test('a page sent without compression fails only for a real site: the built-in server never compresses', () => {
        assert.deepEqual(stableProblems(uncompressed()), []);
        assert.match(stableProblems(uncompressed(), { server: true })[0], /without compression/);
    });

    test('a page Lighthouse could not load is one problem, with its reason', () => {
        const problems = stableProblems({ runtimeError: { code: 'NO_FCP', message: 'The page did not paint any content.' }, audits: {} });

        assert.deepEqual(problems, ['Lighthouse could not audit the page: The page did not paint any content.']);
    });

    test('the audits that can fail are binary ones about the page itself', () => {
        assert.deepEqual(
            STABLE_AUDITS.map((one) => one.id),
            ['image-aspect-ratio', 'image-size-responsive', 'unsized-images', 'http-status-code'],
        );
    });
});

describe('transient Lighthouse failures', () => {
    test('are recognised from the message Lighthouse prints', () => {
        assert.equal(isTransientLighthouseFailure('exit 1\n(NO_NAVSTART)'), true);
        assert.equal(isTransientLighthouseFailure('recording the trace over your page load'), true);
        assert.equal(isTransientLighthouseFailure('exit 1\nChrome could not start'), false);
    });
});

describe('the command given to Lighthouse', () => {
    test('asks for both reports, the four categories and a headless Chrome, on mobile', () => {
        const args = argumentsFor('http://127.0.0.1:8200/', '/tmp/out/home');

        assert.equal(args[0], 'http://127.0.0.1:8200/');
        assert.ok(args.includes('--output=json') && args.includes('--output=html'));
        assert.ok(args.includes('--output-path=/tmp/out/home'));
        assert.ok(args.includes('--only-categories=performance,accessibility,best-practices,seo'));
        assert.ok(args.includes('--chrome-flags=--headless=new'));
        assert.ok(!args.some((arg) => arg.startsWith('--preset')));
    });

    test('a runner that is thrown away after the job turns the sandbox off', () => {
        assert.ok(argumentsFor('http://x/', 'o', { sandbox: false }).includes('--chrome-flags=--headless=new --no-sandbox --disable-dev-shm-usage'));
    });

    test('desktop is a preset', () => {
        assert.ok(argumentsFor('http://x/', 'o', { preset: 'desktop' }).includes('--preset=desktop'));
    });
});

describe('the table', () => {
    const rows = [
        { name: 'home', summary: summarise(report()) },
        { name: 'shop', summary: summarise({ categories: {}, audits: {} }) },
    ];

    test('lines up for a terminal, and shows what is missing as a dash', () => {
        const lines = table(rows).split('\n');

        assert.equal(lines.length, 3);
        assert.match(lines[1], /^ {2}home +99 +100 +96 +69 +1\.1 s +1\.2 s +59 ms +0\.012 +1,236 kB$/);
        assert.match(lines[2], /^ {2}shop +- +- +- +- +- +- +- +- +-$/);
        assert.equal(new Set(lines.map((line) => line.length)).size, 1, 'the columns are as wide as the widest cell');
    });

    test('is a Markdown table for a job summary', () => {
        const lines = table(rows, { markdown: true }).split('\n');

        assert.equal(lines[0], '| Page | Performance | Accessibility | Best practices | SEO | FCP | LCP | TBT | CLS | Transfer |');
        assert.match(lines[1], /^\| --- \| ---: \|/);
        assert.equal(lines[2], '| home | 99 | 100 | 96 | 69 | 1.1 s | 1.2 s | 59 ms | 0.012 | 1,236 kB |');
    });
});

/** A site that answers as the test says; each answer is read by the client to the end, as the script does. */
/** A port nothing listens on. (Port 1 is one that fetch refuses to use, which would say something else.) */
async function freePort() {
    const server = http.createServer();

    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));

    const { port } = server.address();

    await new Promise((resolve) => server.close(resolve));

    return port;
}

async function site(handler) {
    const server = http.createServer(handler);

    servers.push(server);
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));

    return `http://127.0.0.1:${server.address().port}`;
}

describe('waiting for the site', () => {
    test('waits through answers that are not 200', async () => {
        let asked = 0;
        const base = await site((request, response) => {
            response.statusCode = ++asked < 3 ? 503 : 200;
            response.end('ok');
        });

        await waitForSite(base, { timeout: 5000, interval: 10 });

        assert.equal(asked, 3);
    });

    test('says what it last saw when the site never answers 200', async () => {
        const base = await site((request, response) => {
            response.statusCode = 503;
            response.end('not yet');
        });

        await assert.rejects(waitForSite(base, { timeout: 200, interval: 20 }), /did not answer 200 within 0\.2 s \(answered 503\)/);
    });

    test('says so when nothing is listening', async () => {
        await assert.rejects(waitForSite(`http://127.0.0.1:${await freePort()}`, { timeout: 200, interval: 20 }), /ECONNREFUSED/);
    });

    test('stops at once when the application it waits for has stopped', async () => {
        await assert.rejects(
            waitForSite('http://127.0.0.1:1', { timeout: 60_000, giveUp: () => 'The application stopped with exit code 1.' }),
            /stopped with exit code 1/,
        );
    });

    // A server that takes the connection and then stalls: fetch() would wait for ever, and a wait that only looks at its clock between
    // two requests would never get to look. Each of these is a test that ends in a failed assertion instead of a hang, because the
    // time a request is allowed is in the assertion (and `node --test` is not asked to wait for longer than that).
    const stalls = {
        'before it sends any answer': () => {},
        'after the headers, in the middle of the body': (request, response) => {
            response.writeHead(200, { 'content-length': '1000' });
            response.write('x');
        },
    };

    for (const [when, handler] of Object.entries(stalls)) {
        test(`a page that stalls ${when} is given up on at the deadline`, async () => {
            const base = await site(handler);
            const started = Date.now();

            await assert.rejects(warm(base, { timeout: 150 }), (error) => {
                assert.match(error.message, /^no complete answer within 0\.15 s$/);
                assert.equal(error.cause.name, 'TimeoutError');

                return true;
            });
            assert.ok(Date.now() - started < 5000, `it waited ${Date.now() - started} ms`);
        });
    }

    test('a wait does not outlast its own deadline for a request that never ends', async () => {
        const base = await site(() => {});
        const started = Date.now();

        // The request is allowed 30 s by default; only the 0.4 s that are left of the wait can stop it.
        await assert.rejects(
            waitForSite(base, { timeout: 400, interval: 20 }),
            /did not answer 200 within 0\.4 s \(no complete answer within 0\.\d+ s\)/,
        );
        assert.ok(Date.now() - started < 5000, `it waited ${Date.now() - started} ms`);
    });

    test('a request that stalls is given up on and asked again, inside the wait', async () => {
        let asked = 0;
        const base = await site((request, response) => {
            if (++asked > 1) response.end('ok');
        });

        await waitForSite(base, { timeout: 10_000, interval: 10, requestTimeout: 150 });

        assert.equal(asked, 2);
    });

    test('knows that something is using an address if it answers, or takes the connection and says nothing, and not if it refuses', async () => {
        assert.equal(await inUse(await site((request, response) => response.end('ok'))), true);
        assert.equal(await inUse(await site(() => {}), { timeout: 150 }), true);
        assert.equal(await inUse(`http://127.0.0.1:${await freePort()}`), false);
    });

    // PHP's built-in server ends its answers by closing the connection, with no length. On Node 24 a body that is never read then stops
    // the HTTP client with `assert(!this.paused)` (it stopped the install job in CI once, see scripts/check-install.test.mjs), so the
    // client here is a process of its own, and a crash is its exit code.
    for (const size of [300, 16384, 40000]) {
        test(`reads a page of ${size} bytes that ends by closing the connection`, async () => {
            const server = net.createServer((socket) => {
                socket.on('error', () => {});
                socket.on('data', () => {
                    socket.write('HTTP/1.1 200 OK\r\nContent-Type: text/html\r\nConnection: close\r\n\r\n');
                    socket.end('x'.repeat(size));
                });
            });

            servers.push(server);
            await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));

            const code = `import { warm } from ${JSON.stringify(pathToFileURL(script).href)}; console.log(await warm(process.argv[1]));`;
            const result = await new Promise((resolve) =>
                execFile(
                    process.execPath,
                    ['--input-type=module', '-e', code, `http://127.0.0.1:${server.address().port}/`],
                    { timeout: 30_000 },
                    (error, stdout, stderr) => resolve({ status: error ? (error.code ?? 1) : 0, stdout, stderr }),
                ),
            );

            assert.equal(result.status, 0, result.stderr);
            assert.equal(result.stdout.trim(), '200');
        });
    }
});

/** A stand-in for Lighthouse's command: copies the report the test names to where it was asked to write. */
function fakeCli(dir) {
    const file = path.join(dir, 'fake-lighthouse.mjs');

    fs.writeFileSync(
        file,
        `import fs from 'node:fs';
import path from 'node:path';
const args = process.argv.slice(2);
const output = args.find((arg) => arg.startsWith('--output-path=')).slice('--output-path='.length);
fs.appendFileSync(process.env.FAKE_LOG, JSON.stringify(args) + '\\n');
if (process.env.FAKE_FAIL) {
    console.error('Chrome could not start');
    process.exit(1);
}
if (process.env.FAKE_TRANSIENT_FAILS) {
    const countFile = process.env.FAKE_TRANSIENT_COUNT ?? path.join(path.dirname(process.env.FAKE_LOG ?? '.'), 'transient-count');
    let left = Number(fs.readFileSync(countFile, 'utf8'));
    if (left > 0) {
        fs.writeFileSync(countFile, String(left - 1));
        console.error('Runtime error encountered: Something went wrong with recording the trace over your page load. Please run Lighthouse again. (NO_NAVSTART)');
        process.exit(1);
    }
}
if (process.env.FAKE_HANG) {
    setInterval(() => {}, 1000);
} else {
    fs.writeFileSync(output + '.report.json', fs.readFileSync(process.env.FAKE_LHR, 'utf8'));
    fs.writeFileSync(output + '.report.html', '<html></html>');
}
`,
    );

    return file;
}

describe('one page', () => {
    test('is audited by the command, and its report is read back', async () => {
        const dir = folder();
        const lhr = path.join(dir, 'lhr.json');
        fs.writeFileSync(lhr, JSON.stringify(report()));
        Object.assign(process.env, { FAKE_LHR: lhr, FAKE_LOG: path.join(dir, 'log') });

        const result = await audit('http://127.0.0.1:8200/blogs', 'blog', { out: dir, cli: fakeCli(dir), sandbox: false });

        assert.equal(summarise(result).performance, 99);
        assert.ok(fs.existsSync(path.join(dir, 'blog.report.html')), 'the HTML report is kept');
        assert.match(
            fs.readFileSync(path.join(dir, 'log'), 'utf8'),
            /"http:\/\/127\.0\.0\.1:8200\/blogs".*"--chrome-flags=--headless=new --no-sandbox --disable-dev-shm-usage"/,
        );
    });

    test('a trace that never started is tried again', async () => {
        const dir = folder();
        const lhr = path.join(dir, 'lhr.json');
        fs.writeFileSync(lhr, JSON.stringify(report()));
        const countFile = path.join(dir, 'transient-count');
        fs.writeFileSync(countFile, '1');
        Object.assign(process.env, {
            FAKE_LHR: lhr,
            FAKE_LOG: path.join(dir, 'log'),
            FAKE_TRANSIENT_FAILS: '1',
            FAKE_TRANSIENT_COUNT: countFile,
        });

        try {
            const result = await auditWithRetry('http://127.0.0.1:8200/', 'home', { out: dir, cli: fakeCli(dir), sandbox: false });

            assert.equal(summarise(result).performance, 99);
            assert.equal(fs.readFileSync(path.join(dir, 'log'), 'utf8').trim().split('\n').length, 2);
        } finally {
            delete process.env.FAKE_TRANSIENT_FAILS;
            delete process.env.FAKE_TRANSIENT_COUNT;
        }
    });

    test('a command that fails says what it printed', async () => {
        const dir = folder();
        Object.assign(process.env, { FAKE_LOG: path.join(dir, 'log'), FAKE_FAIL: '1' });

        try {
            await assert.rejects(audit('http://x/', 'home', { out: dir, cli: fakeCli(dir) }), /exit 1\nChrome could not start/);
        } finally {
            delete process.env.FAKE_FAIL;
        }
    });

    test('a command that does not finish is ended', async () => {
        const dir = folder();
        Object.assign(process.env, { FAKE_LOG: path.join(dir, 'log'), FAKE_HANG: '1' });

        try {
            await assert.rejects(audit('http://x/', 'home', { out: dir, cli: fakeCli(dir), timeout: 300 }), /no result within 0\.3 s/);
        } finally {
            delete process.env.FAKE_HANG;
        }
    });
});

/** The script itself, as `npm run lighthouse` runs it, against a stand-in site and a stand-in for Lighthouse's command. */
function runScript(args, env = {}) {
    return new Promise((resolve) => {
        execFile(
            process.execPath,
            [script, ...args],
            { env: { ...process.env, CI: '', GITHUB_ACTIONS: '', GITHUB_STEP_SUMMARY: '', ...env }, timeout: 60_000 },
            (error, stdout, stderr) => resolve({ code: error ? (error.code ?? 1) : 0, out: stdout, err: stderr }),
        );
    });
}

describe('the command', () => {
    const goodSite = () =>
        site((request, response) => {
            response.end('<html>a page</html>');
        });

    function setup(lhr) {
        const dir = folder();
        const file = path.join(dir, 'lhr.json');

        fs.writeFileSync(file, JSON.stringify(lhr));

        return { dir, env: { LIGHTHOUSE_CLI: fakeCli(dir), FAKE_LHR: file, FAKE_LOG: path.join(dir, 'log') } };
    }

    test('audits every page, prints the table and keeps the reports', async () => {
        const base = await goodSite();
        const { dir, env } = setup(report());
        const out = path.join(dir, 'reports');
        const { code, out: stdout } = await runScript([`--site=${base}`, `--out=${out}`], env);

        assert.equal(code, 0, stdout);
        assert.match(stdout, /Lighthouse mobile, simulated slow 4G\n/);
        assert.match(stdout, /home +99 +100 +96 +69/);
        assert.deepEqual(fs.readdirSync(out).sort(), PAGES.flatMap((page) => [`${page.name}.report.html`, `${page.name}.report.json`]).sort());

        const asked = fs
            .readFileSync(path.join(dir, 'log'), 'utf8')
            .trim()
            .split('\n')
            .map((line) => JSON.parse(line)[0]);

        assert.deepEqual(
            asked,
            PAGES.map((page) => `${base}${page.path}`),
        );
    });

    test('writes the table into the job summary of GitHub, when there is one', async () => {
        const base = await goodSite();
        const { dir, env } = setup(report());
        const summary = path.join(dir, 'summary.md');

        await runScript([`--site=${base}`, `--out=${path.join(dir, 'reports')}`], { ...env, GITHUB_STEP_SUMMARY: summary });

        const text = fs.readFileSync(summary, 'utf8');

        assert.match(text, /^### Lighthouse\n/);
        assert.match(text, /\| home \| 99 \| 100 \| 96 \| 69 \|/);
    });

    test('fails when an image is at the wrong aspect ratio, says on which page, and still keeps the reports', async () => {
        const base = await goodSite();
        const { dir, env } = setup(failedImage('image-aspect-ratio', [`${base}/brand/logo-light.png`]));
        const out = path.join(dir, 'reports');
        const { code, err } = await runScript([`--site=${base}`, `--out=${out}`], { ...env, GITHUB_ACTIONS: 'true' });

        assert.equal(code, 1);
        assert.match(err, new RegExp(`${PAGES.length} to fix:`));
        assert.match(err, /\/forum: The page shows images at another aspect ratio.*\/brand\/logo-light\.png/);
        assert.match(err, /::error title=Lighthouse::\/blogs: /);
        assert.equal(fs.readdirSync(out).length, PAGES.length * 2);
    });

    test("turns Chrome's sandbox off on a CI runner, and only there", async () => {
        const base = await goodSite();
        const { dir, env } = setup(report());
        const flags = (ci) => {
            fs.rmSync(path.join(dir, 'log'), { force: true });

            return runScript([`--site=${base}`, `--out=${path.join(dir, 'reports')}`], { ...env, CI: ci }).then(() =>
                fs.readFileSync(path.join(dir, 'log'), 'utf8'),
            );
        };

        assert.match(await flags('true'), /--chrome-flags=--headless=new --no-sandbox/);
        assert.doesNotMatch(await flags(''), /--no-sandbox/);
    });

    test('a real site that sends its pages without compression fails', async () => {
        const base = await goodSite();
        const { dir, env } = setup(uncompressed());
        const { code, err } = await runScript([`--site=${base}`, `--out=${path.join(dir, 'reports')}`], env);

        assert.equal(code, 1);
        assert.match(err, /without compression/);
    });

    test('a page that does not answer 200 is a run that could not be made, not a bad score', async () => {
        const base = await site((request, response) => {
            response.statusCode = request.url === '/forum' ? 404 : 200;
            response.end('x');
        });
        const { dir, env } = setup(report());
        const { code, err } = await runScript([`--site=${base}`, `--out=${path.join(dir, 'reports')}`], env);

        assert.equal(code, 2);
        assert.match(err, /\/forum answers 404, not 200/);
    });

    test('a page that stalls is a run that could not be made, said soon and with its path, and the reports are not started', async () => {
        const base = await site((request, response) => {
            if (request.url !== '/forum') response.end('x');
        });
        const { dir, env } = setup(report());
        const out = path.join(dir, 'reports');
        const started = Date.now();
        const { code, err } = await runScript([`--site=${base}`, `--out=${out}`, '--request-timeout=0.3'], env);

        assert.equal(code, 2);
        assert.ok(err.includes(`Could not reach ${base}: /forum did not finish answering within 0.3 s.`), err);
        assert.ok(Date.now() - started < 20_000, `it took ${Date.now() - started} ms`);
        assert.deepEqual(fs.readdirSync(out), []);
        assert.ok(!fs.existsSync(path.join(dir, 'log')), 'Lighthouse was run');
    });

    test('a site that is not there is a run that could not be made', async () => {
        const { dir, env } = setup(report());
        const gone = `http://127.0.0.1:${await freePort()}`;
        const { code, err } = await runScript([`--site=${gone}`, `--out=${path.join(dir, 'reports')}`], env);

        assert.equal(code, 2);
        assert.ok(err.includes(`Could not reach ${gone}`), err);
        assert.match(err, /ECONNREFUSED/);
    });

    test('a Lighthouse that fails on one page is named, and the others are still audited', async () => {
        const base = await goodSite();
        const { dir, env } = setup(report());
        const { code, err, out } = await runScript([`--site=${base}`, `--out=${path.join(dir, 'reports')}`], { ...env, FAKE_FAIL: '1' });

        assert.equal(code, 1);
        assert.equal((out.match(/failed/g) ?? []).length, PAGES.length);
        assert.match(err, /\/: Lighthouse did not finish: exit 1\nChrome could not start/);
    });

    test('removes the reports of an earlier run and nothing else in the folder', async () => {
        const base = await goodSite();
        const { dir, env } = setup(report());
        const out = path.join(dir, 'reports');

        fs.mkdirSync(out);
        fs.writeFileSync(path.join(out, 'old.report.json'), '{}');
        fs.writeFileSync(path.join(out, 'keep.txt'), 'mine');

        await runScript([`--site=${base}`, `--out=${out}`], env);

        assert.ok(!fs.existsSync(path.join(out, 'old.report.json')));
        assert.equal(fs.readFileSync(path.join(out, 'keep.txt'), 'utf8'), 'mine');
    });

    test('says how to use it when it is given something it does not know', async () => {
        for (const args of [
            ['--preset=tablet'],
            ['--site=example.com'],
            ['--request-timeout=0'],
            ['--request-timeout=soon'],
            ['--request-timeout='],
        ]) {
            const { code, err } = await runScript(args);

            assert.equal(code, 2, args.join(' '));
            assert.match(err, /Usage: npm run lighthouse/);
        }
    });
});
