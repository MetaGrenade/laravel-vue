// Runs Lighthouse over the pages a visitor opens first and keeps the reports (docs/lighthouse.md): `npm run lighthouse`, or
// `npm run lighthouse -- --site=https://example.com` for a site that is already running.
//
// It reports. Scores and timings move with the machine, so no number here fails a run. What can fail it is what the application decides
// and a run does not change: an image shown at another aspect ratio than its file, an image smaller than the place it is shown in, an image
// without a width and a height, and a page that does not answer. With `--site` the page being sent without compression fails it too: the
// built-in PHP server that is started otherwise never compresses, so that is a question for a real web server.
//
// Without `--site` the application is started the way the browser suites start it (tests/e2e/serve.mjs), on a database of its own.

import { execFile, execFileSync, spawn } from 'node:child_process';
import fs from 'node:fs';
import { createRequire } from 'node:module';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

/**
 * The pages a visitor opens first, and the three that the roadmap's Largest Contentful Paint target names: the home page, a blog post and a
 * product page (docs/ROADMAP-v1.0.0.md, section 5.2). Their records are in the seeder the site is started with.
 */
export const PAGES = [
    { name: 'home', path: '/' },
    { name: 'blog', path: '/blogs' },
    { name: 'post', path: '/blogs/a-first-post' },
    { name: 'forum', path: '/forum' },
    { name: 'shop', path: '/shop' },
    { name: 'product', path: '/shop/products/hoodie' },
];

const SITE = { database: 'lighthouse.sqlite', seeder: 'AccessibilityFlowSeeder', port: 8200 };

/** Audits that answer the same for the same page, whatever the machine is doing. Each is binary: it passes or it does not. */
export const STABLE_AUDITS = [
    {
        id: 'image-aspect-ratio',
        problem: 'shows images at another aspect ratio than their file has (the width and height attributes, or the CSS, do not match the file)',
    },
    { id: 'image-size-responsive', problem: 'shows images larger than their file, so they are enlarged and blurred' },
    { id: 'unsized-images', problem: 'has images without a width and a height, so the page moves when they load' },
    { id: 'http-status-code', problem: 'answers with an error status' },
];

const number = (n) => n.toLocaleString('en-US');
const pathOf = (url) => {
    try {
        return new URL(url).pathname;
    } catch {
        return String(url);
    }
};

/** Where Lighthouse's own command is. */
export function lighthouseCli() {
    return path.join(path.dirname(createRequire(import.meta.url).resolve('lighthouse/package.json')), 'cli', 'index.js');
}

/**
 * What the command is given for one page. `--no-sandbox` is for a runner that is thrown away after the job (its Chrome cannot make a
 * sandbox where user namespaces are not allowed), so it follows `sandbox: false`, which CI sets.
 *
 * @param {string} url
 * @param {string} output The reports are written to `<output>.report.json` and `<output>.report.html`.
 * @param {{preset?: 'mobile'|'desktop', sandbox?: boolean}} [options]
 */
export function argumentsFor(url, output, { preset = 'mobile', sandbox = true } = {}) {
    return [
        url,
        '--output=json',
        '--output=html',
        `--output-path=${output}`,
        `--chrome-flags=${['--headless=new', ...(sandbox ? [] : ['--no-sandbox'])].join(' ')}`,
        '--only-categories=performance,accessibility,best-practices,seo',
        '--locale=en',
        '--quiet',
        ...(preset === 'desktop' ? ['--preset=desktop'] : []),
    ];
}

/** How long one request may take, from asking to the last byte of the answer. The first page after a start compiles its views: seconds, not half a minute. */
export const REQUEST_TIMEOUT = 30_000;

/** Whether a request ran out of time, as opposed to being refused or answered with an error. */
const timedOut = (error) => error.cause?.name === 'TimeoutError';

/**
 * Asks for the page to be read once, so the first compile of a view and the first read of the database are not what Lighthouse measures.
 * The whole body is read: a response that is left unread can crash Node's client when the server ends it by closing the connection.
 *
 * A server that takes the connection and then stalls would hold this for ever, and so would a body that stops half-way: `fetch()` comes
 * back at the headers. The deadline is one signal for both, so whichever is waiting is stopped, and `timeout` is in milliseconds.
 */
export async function warm(url, { timeout = REQUEST_TIMEOUT } = {}) {
    const signal = AbortSignal.timeout(timeout);

    try {
        const response = await fetch(url, { redirect: 'follow', signal });

        await response.arrayBuffer();

        return response.status;
    } catch (error) {
        if (signal.aborted) throw new Error(`no complete answer within ${number(timeout / 1000)} s`, { cause: signal.reason });

        // Node's client says only "fetch failed"; the reason (ECONNREFUSED, ENOTFOUND, a certificate) is in the cause.
        throw new Error(error.cause?.code ?? error.cause?.message ?? error.message, { cause: error });
    }
}

/**
 * Whether something is using the address: it answered, or it took the connection and said nothing. Only a refused connection (or
 * another failure to connect) says there is nothing there.
 */
export async function inUse(base, { timeout = 5_000 } = {}) {
    try {
        await warm(base, { timeout });

        return true;
    } catch (error) {
        return timedOut(error);
    }
}

/**
 * Waits until the site answers 200, or says why it did not. A request is never allowed past the deadline of the wait, so `timeout` is
 * the longest this takes (and `interval` the pause between two requests), whatever the site does.
 */
export async function waitForSite(base, { timeout = 180_000, interval = 500, requestTimeout = REQUEST_TIMEOUT, giveUp = () => null } = {}) {
    const end = Date.now() + timeout;
    let last = 'no answer';

    while (Date.now() < end) {
        const reason = giveUp();

        if (reason) throw new Error(reason);

        try {
            const status = await warm(base, { timeout: Math.max(1, Math.min(requestTimeout, end - Date.now())) });

            if (status === 200) return;

            last = `answered ${status}`;
        } catch (error) {
            last = error.message;
        }

        await new Promise((resolve) => setTimeout(resolve, interval));
    }

    throw new Error(`${base} did not answer 200 within ${number(timeout / 1000)} s (${last}).`);
}

/**
 * The scores and the numbers a reader looks at first, from one report.
 *
 * @param {object} lhr Lighthouse's JSON report.
 */
export function summarise(lhr) {
    const score = (id) => (lhr.categories?.[id]?.score == null ? null : Math.round(lhr.categories[id].score * 100));
    const value = (id) => lhr.audits?.[id]?.numericValue ?? null;

    return {
        performance: score('performance'),
        accessibility: score('accessibility'),
        bestPractices: score('best-practices'),
        seo: score('seo'),
        fcp: value('first-contentful-paint'),
        lcp: value('largest-contentful-paint'),
        tbt: value('total-blocking-time'),
        cls: value('cumulative-layout-shift'),
        bytes: value('total-byte-weight'),
    };
}

/**
 * What is wrong with a page that does not depend on the machine, as lines of text.
 *
 * @param {object} lhr Lighthouse's JSON report.
 * @param {{server?: boolean}} [options] `server`: the site is a real one, so what its web server does is judged too.
 */
export function stableProblems(lhr, { server = false } = {}) {
    if (lhr.runtimeError) return [`Lighthouse could not audit the page: ${lhr.runtimeError.message ?? lhr.runtimeError.code}`];

    const problems = [];

    for (const { id, problem } of STABLE_AUDITS) {
        const audit = lhr.audits?.[id];

        if (!audit || audit.score === null || audit.score >= 1 || ['notApplicable', 'manual', 'informative'].includes(audit.scoreDisplayMode))
            continue;

        const named = [
            ...new Set(
                (audit.details?.items ?? [])
                    .map((item) => item.url)
                    .filter(Boolean)
                    .map(pathOf),
            ),
        ];

        problems.push(`The page ${problem}${named.length > 0 ? `: ${named.join(', ')}` : ''}.`);
    }

    if (server && lhr.audits?.['document-latency-insight']?.details?.items?.usesCompression?.value === false) {
        problems.push('The page is sent without compression: the web server does not compress text (the nginx settings are in docs/performance.md).');
    }

    return problems;
}

const seconds = (ms) => (ms === null ? '-' : `${(ms / 1000).toFixed(1)} s`);
const milliseconds = (ms) => (ms === null ? '-' : `${Math.round(ms)} ms`);
const kilobytes = (bytes) => (bytes === null ? '-' : `${number(Math.round(bytes / 1024))} kB`);
const score = (n) => (n === null ? '-' : String(n));

const COLUMNS = [
    ['Performance', (s) => score(s.performance)],
    ['Accessibility', (s) => score(s.accessibility)],
    ['Best practices', (s) => score(s.bestPractices)],
    ['SEO', (s) => score(s.seo)],
    ['FCP', (s) => seconds(s.fcp)],
    ['LCP', (s) => seconds(s.lcp)],
    ['TBT', (s) => milliseconds(s.tbt)],
    ['CLS', (s) => (s.cls === null ? '-' : s.cls.toFixed(3))],
    ['Transfer', (s) => kilobytes(s.bytes)],
];

/**
 * A table of the results, for the terminal (aligned) or for a GitHub job summary (Markdown).
 *
 * @param {Array<{name: string, summary: ReturnType<typeof summarise>}>} rows
 */
export function table(rows, { markdown = false } = {}) {
    const cells = rows.map((row) => [row.name, ...COLUMNS.map(([, format]) => format(row.summary))]);
    const heads = ['Page', ...COLUMNS.map(([name]) => name)];

    if (markdown) {
        return [
            `| ${heads.join(' | ')} |`,
            `|${heads.map((_, i) => (i === 0 ? ' --- ' : ' ---: ')).join('|')}|`,
            ...cells.map((line) => `| ${line.join(' | ')} |`),
        ].join('\n');
    }

    const widths = heads.map((head, i) => Math.max(head.length, ...cells.map((line) => line[i].length)));
    const line = (values) => `  ${values.map((value, i) => (i === 0 ? value.padEnd(widths[i]) : value.padStart(widths[i]))).join('  ')}`;

    return [line(heads), ...cells.map(line)].join('\n');
}

/** Runs a command and resolves with its output, or rejects with what it printed. */
function execute(file, args, { timeout }) {
    return new Promise((resolve, reject) => {
        execFile(file, args, { timeout, maxBuffer: 64 * 1024 * 1024, windowsHide: true }, (error, stdout, stderr) => {
            if (error) {
                const tail = `${stderr}${stdout}`.trim().split('\n').slice(-6).join('\n');

                reject(
                    new Error(
                        `${error.killed ? `no result within ${number(timeout / 1000)} s` : `exit ${error.code ?? error.signal}`}${tail ? `\n${tail}` : ''}`,
                    ),
                );

                return;
            }

            resolve({ stdout, stderr });
        });
    });
}

/**
 * One page, once: Lighthouse's command writes both reports, and the JSON one is read back.
 *
 * @returns {Promise<object>} Lighthouse's JSON report.
 */
export async function audit(url, name, { out, cli = lighthouseCli(), preset = 'mobile', sandbox = true, timeout = 180_000 }) {
    const output = path.join(out, name);

    await execute(process.execPath, [cli, ...argumentsFor(url, output, { preset, sandbox })], { timeout });

    return JSON.parse(fs.readFileSync(`${output}.report.json`, 'utf8'));
}

/** Ends a process and what it started: the application's server is a child of the script that starts it. */
function stop(child) {
    if (child.exitCode !== null || child.pid === undefined) return;

    try {
        if (process.platform === 'win32') execFileSync('taskkill', ['/pid', String(child.pid), '/T', '/F'], { stdio: 'ignore' });
        else process.kill(-child.pid, 'SIGTERM');
    } catch {
        // It ended by itself.
    }
}

/** Starts the application on a database of its own and waits for it. @returns {Promise<{base: string, stop: () => void}>} */
async function startSite(out, { requestTimeout }) {
    const base = `http://127.0.0.1:${SITE.port}`;

    if (await inUse(base)) {
        throw new Error(
            `Something is already using ${base}, so the application would not be the one started here. Stop it, or use --site=${base} to audit it.`,
        );
    }

    fs.mkdirSync(out, { recursive: true });

    const log = fs.openSync(path.join(out, 'site.log'), 'w');
    const child = spawn(process.execPath, ['tests/e2e/serve.mjs'], {
        cwd: root,
        stdio: ['ignore', log, log],
        detached: process.platform !== 'win32',
        windowsHide: true,
        env: {
            ...process.env,
            PHP_BIN: process.env.PHP_BIN ?? 'php',
            E2E_DATABASE: SITE.database,
            E2E_SEEDER: SITE.seeder,
            E2E_PORT: String(SITE.port),
            // A site that blocks search engines would fail the SEO audit for a reason that is not the page's.
            SEO_INDEXING: 'true',
        },
    });

    try {
        await waitForSite(base, {
            requestTimeout,
            giveUp: () => (child.exitCode === null ? null : `The application stopped with exit code ${child.exitCode}.`),
        });
    } catch (error) {
        stop(child);

        const tail = fs.readFileSync(path.join(out, 'site.log'), 'utf8').trim().split('\n').slice(-12).join('\n');

        throw new Error(`${error.message}\n${tail}`);
    }

    return { base, stop: () => stop(child) };
}

async function main(argv) {
    const option = (name, fallback) => argv.find((arg) => arg.startsWith(`--${name}=`))?.slice(name.length + 3) ?? fallback;
    const given = option('site', null);
    const out = path.resolve(root, option('out', 'lighthouse-report'));
    const preset = option('preset', 'mobile');
    const seconds = Number(option('request-timeout', REQUEST_TIMEOUT / 1000));
    const requestTimeout = seconds * 1000;
    const cli = process.env.LIGHTHOUSE_CLI ?? lighthouseCli();

    if (!['mobile', 'desktop'].includes(preset) || !(seconds > 0 && Number.isFinite(seconds)) || (given !== null && !/^https?:\/\//.test(given))) {
        console.error(
            'Usage: npm run lighthouse [-- --site=https://example.com] [--out=lighthouse-report] [--preset=mobile|desktop] [--request-timeout=30]',
        );
        console.error('Without --site the application is started on a database of its own. A site is its address, e.g. https://example.com.');
        console.error('--request-timeout is the seconds a page may take to answer completely when it is read before the audit (a number above 0).');

        return 2;
    }

    let site;
    let base = given?.replace(/\/+$/, '');

    // What an earlier run left is removed, and nothing else: `--out` may be a folder that holds other things.
    fs.mkdirSync(out, { recursive: true });

    for (const file of fs.readdirSync(out)) {
        if (/\.report\.(json|html)$/.test(file) || file === 'site.log') fs.rmSync(path.join(out, file));
    }

    try {
        if (!base) {
            site = await startSite(out, { requestTimeout });
            base = site.base;
        }

        for (const page of PAGES) {
            const status = await warm(`${base}${page.path}`, { timeout: requestTimeout }).catch((error) => {
                throw timedOut(error) ? new Error(`${page.path} did not finish answering within ${number(requestTimeout / 1000)} s.`) : error;
            });

            if (status !== 200) throw new Error(`${page.path} answers ${status}, not 200.`);
        }
    } catch (error) {
        site?.stop();
        console.error(`Could not reach ${base ?? 'the application'}: ${error.message}`);

        return 2;
    }

    const interrupted = () => {
        site?.stop();
        process.exit(130);
    };

    process.once('SIGINT', interrupted);

    const rows = [];
    const problems = [];

    try {
        for (const page of PAGES) {
            process.stdout.write(`  ${page.name.padEnd(7)} ${base}${page.path} ...`);

            try {
                const lhr = await audit(`${base}${page.path}`, page.name, { out, cli, preset, sandbox: !process.env.CI, timeout: 180_000 });

                rows.push({ name: page.name, summary: summarise(lhr) });
                problems.push(...stableProblems(lhr, { server: given !== null }).map((problem) => `${page.path}: ${problem}`));
                console.log(' done');
            } catch (error) {
                console.log(' failed');
                problems.push(`${page.path}: Lighthouse did not finish: ${error.message}`);
            }
        }
    } finally {
        process.removeListener('SIGINT', interrupted);
        site?.stop();
    }

    const how = `${preset === 'desktop' ? 'desktop' : 'mobile, simulated slow 4G'}${given ? '' : ', served by the built-in PHP server (no compression, no cache headers)'}`;

    if (rows.length > 0) {
        console.log(`\nLighthouse ${how}\n`);
        console.log(table(rows));
    }

    if (process.env.GITHUB_STEP_SUMMARY && rows.length > 0) {
        fs.appendFileSync(
            process.env.GITHUB_STEP_SUMMARY,
            `### Lighthouse\n\n${how.charAt(0).toUpperCase()}${how.slice(1)}. The reports are kept with the run (lighthouse-report).\n\n${table(rows, { markdown: true })}\n\n${
                problems.length > 0
                    ? `**${problems.length} to fix**\n\n${problems.map((problem) => `- ${problem}`).join('\n')}\n`
                    : 'Nothing that does not depend on the machine is wrong.\n'
            }`,
        );
    }

    console.log(`\nThe reports are in ${path.relative(process.cwd(), out) || '.'} (open the .report.html files).`);

    if (problems.length === 0) {
        console.log('Nothing that does not depend on the machine is wrong. Scores and timings are for reading, not for passing.');

        return 0;
    }

    console.error(`\n${problems.length} to fix:`);

    for (const problem of problems) {
        console.error(`  - ${problem}`);
        if (process.env.GITHUB_ACTIONS) console.error(`::error title=Lighthouse::${problem}`);
    }

    return 1;
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    process.exitCode = await main(process.argv.slice(2));
}
