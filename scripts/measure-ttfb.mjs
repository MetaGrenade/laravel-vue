// Measures how long a site takes to start answering the pages a guest opens (docs/performance.md): `npm run ttfb -- https://example.com`.
//
// Time to first byte is counted from the moment the request starts, a new connection each time like a new visitor's, to the moment the
// response headers arrive; DNS, TCP and TLS are inside it. Each page is asked for a few times first, so a cold cache and the first
// compile of a view are not counted, and the middle sample is the number to read: a single slow request is noise.
// The body is read to the end so the size on the wire is known, and a page that is sent uncompressed is called out.
//
// There are no dependencies: Node's own HTTP client.

import http from 'node:http';
import https from 'node:https';
import { pathToFileURL } from 'node:url';

/** The pages a guest can open that need nothing in the database to be named. */
export const PAGES = ['/robots.txt', '/', '/blogs', '/forum', '/members', '/leaderboard', '/shop', '/pricing', '/support', '/login'];

/** docs/ROADMAP-v1.0.0.md, section 5.2: a guest page answers in under 200 ms. */
export const BUDGET_MS = 200;

/** A page smaller than this fits in one packet or two, and compressing it saves nothing worth a warning. */
const COMPRESS_ABOVE = 2000;

const number = (n) => n.toLocaleString('en-US');

/**
 * One request on its own connection.
 *
 * @param {URL} url
 * @param {{timeout?: number}} [options]
 * @returns {Promise<{status: number, ttfb: number, bytes: number, encoding: string|null, location: string|null, type: string}>}
 */
export function sample(url, { timeout = 15_000 } = {}) {
    return new Promise((resolve, reject) => {
        const client = url.protocol === 'https:' ? https : http;
        const start = performance.now();
        const request = client.get(
            url,
            {
                agent: false,
                timeout,
                headers: { accept: 'text/html,*/*', 'accept-encoding': 'gzip, br', connection: 'close', 'user-agent': 'metaforge-ttfb' },
            },
            (response) => {
                const ttfb = performance.now() - start;
                let bytes = 0;

                response.on('data', (chunk) => (bytes += chunk.length));
                response.on('error', reject);
                response.on('end', () =>
                    resolve({
                        status: response.statusCode ?? 0,
                        ttfb,
                        bytes,
                        encoding: response.headers['content-encoding'] ?? null,
                        location: response.headers.location ?? null,
                        type: response.headers['content-type'] ?? '',
                    }),
                );
            },
        );

        request.on('timeout', () => request.destroy(new Error(`no answer within ${number(timeout)} ms`)));
        request.on('error', reject);
    });
}

/**
 * The middle sample, the one that nine in ten are under, and the quickest.
 *
 * @param {number[]} times
 */
export function summarise(times) {
    if (times.length === 0) throw new Error('There is nothing to summarise.');

    const sorted = [...times].sort((a, b) => a - b);
    const middle = Math.floor(sorted.length / 2);

    return {
        median: sorted.length % 2 === 1 ? sorted[middle] : (sorted[middle - 1] + sorted[middle]) / 2,
        p90: sorted[Math.ceil(sorted.length * 0.9) - 1],
        min: sorted[0],
    };
}

/**
 * @param {string} base The site, e.g. https://example.com
 * @param {string[]} paths
 * @param {{runs?: number, warmup?: number}} [options]
 */
export async function measure(base, paths, { runs = 15, warmup = 3 } = {}) {
    const results = [];

    for (const path of paths) {
        const url = new URL(path, base);

        for (let i = 0; i < warmup; i++) await sample(url);

        const samples = [];
        for (let i = 0; i < runs; i++) samples.push(await sample(url));

        // Every answer counts, not the last: a page that fails now and then is failing. An error comes back faster than a page, so the
        // times are those of the answers that were 200 (all of them, when none was).
        const failed = samples.filter((answer) => answer.status !== 200);
        const served = samples.filter((answer) => answer.status === 200);
        const shown = failed[0] ?? samples[samples.length - 1];
        const last = served[served.length - 1] ?? shown;

        results.push({
            path,
            status: shown.status,
            location: shown.location,
            failed: failed.length,
            runs: samples.length,
            ...summarise((served.length > 0 ? served : samples).map((answer) => answer.ttfb)),
            // Size, encoding and type of an answer that was served: they do not change from one request to the next, or the page is not
            // static enough to measure.
            bytes: last.bytes,
            encoding: last.encoding,
            type: last.type,
        });
    }

    return results;
}

/**
 * What is wrong with the results, as lines of text.
 *
 * @param {Awaited<ReturnType<typeof measure>>} results
 * @param {number} budget Milliseconds the middle sample is allowed.
 */
export function check(results, budget = BUDGET_MS) {
    const problems = [];

    for (const page of results) {
        // The answer that was not 200, and how often: all of them is a page that is not there, some of them is one that fails now and then.
        const often = page.failed < page.runs ? ` in ${page.failed} of ${page.runs} requests` : '';

        if (page.failed > 0 && page.status >= 300 && page.status < 400) {
            problems.push(
                `${page.path} answers ${page.status}${often}, a redirect${page.location ? ` to ${page.location}` : ''}. Measure the page it leads to.`,
            );
        } else if (page.failed > 0) {
            problems.push(`${page.path} answers ${page.status}${often}, not 200.`);
        } else if (page.median > budget) {
            problems.push(
                `${page.path} starts answering after ${Math.round(page.median)} ms (the middle of the samples), over the ${budget} ms it is allowed.`,
            );
        }
    }

    return problems;
}

/** Pages that are text, are not small, and arrive as they were written. */
export function uncompressed(results) {
    return results.filter(
        (page) => page.failed === 0 && !page.encoding && page.bytes > COMPRESS_ABOVE && /text|json|xml|javascript|svg/.test(page.type),
    );
}

const ms = (n) => `${Math.round(n)} ms`.padStart(7);

async function main(argv) {
    const option = (name, fallback) => argv.find((arg) => arg.startsWith(`--${name}=`))?.slice(name.length + 3) ?? fallback;
    const base = argv.find((arg) => !arg.startsWith('--'));

    if (!base) {
        console.error('Usage: npm run ttfb -- <site> [--runs=15] [--warmup=3] [--budget=200] [--path=/blogs --path=/forum ...]');
        console.error('A site is its address, e.g. https://example.com or http://127.0.0.1:8000.');

        return 2;
    }

    const runs = Number.parseInt(option('runs', '15'), 10);
    const warmup = Number.parseInt(option('warmup', '3'), 10);
    const budget = Number.parseInt(option('budget', String(BUDGET_MS)), 10);
    const chosen = argv.filter((arg) => arg.startsWith('--path=')).map((arg) => arg.slice(7));
    const paths = chosen.length > 0 ? chosen : PAGES;

    if (![runs, warmup, budget].every(Number.isInteger) || runs < 1 || warmup < 0 || budget < 1) {
        console.error('--runs must be 1 or more, --warmup 0 or more and --budget 1 or more.');

        return 2;
    }

    let results;
    try {
        results = await measure(base, paths, { runs, warmup });
    } catch (error) {
        console.error(`Could not reach ${base}: ${error.message}`);

        return 2;
    }

    console.log(`Time to first byte of ${base}: ${runs} requests a page after ${warmup} to warm it, each on a new connection\n`);
    console.log(`  ${'page'.padEnd(16)} status   median      p90      min   on the wire`);
    for (const page of results) {
        const wire = `${number(page.bytes)} B${page.encoding ? ` ${page.encoding}` : ''}`;
        console.log(`  ${page.path.padEnd(16)} ${String(page.status).padStart(6)} ${ms(page.median)} ${ms(page.p90)} ${ms(page.min)}   ${wire}`);
    }

    const plain = uncompressed(results);
    if (plain.length > 0) {
        console.log(`\n${plain.length} of these arrive uncompressed: ${plain.map((page) => page.path).join(', ')}.`);
        console.log(
            'Have the web server (or the CDN in front of it) compress text responses; a page is about seven times smaller on the wire (docs/performance.md).',
        );
    }

    const problems = check(results, budget);
    if (problems.length === 0) {
        console.log(`\nEvery page starts answering in under ${budget} ms.`);

        return 0;
    }

    console.error(`\n${problems.length} over budget or not answering:`);
    for (const problem of problems) {
        console.error(`  - ${problem}`);
        if (process.env.GITHUB_ACTIONS) console.error(`::error title=Time to first byte::${problem}`);
    }

    return 1;
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    process.exitCode = await main(process.argv.slice(2));
}
