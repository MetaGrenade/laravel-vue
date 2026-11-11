// Turns what PHPUnit printed for one run of the suite into a GitHub annotation (the `stability` workflow, docs/architecture.md):
//
//     node scripts/phpunit-failures.mjs <log of one run> <number of the run> <runs in all>
//
// The weekly job runs the whole suite ten times in random order. A job's log needs a GitHub sign-in to read, even in a public repository, but
// its annotations do not: the checks page and the API show them to anyone. So a run that failed says here which tests failed, why (the
// first lines of each message), the random seed that reproduces the order, and how long it took; a run that passed says how long it took,
// which is what shows a run that waited on something. Nothing here decides whether the job fails; the workflow still counts the exit codes.

import fs from 'node:fs';
import { pathToFileURL } from 'node:url';

/** The longest part of a test's message that is kept, and how many tests are named: an annotation is read on a page, not in a log. */
const REASON_LINES = 3;
const REASON_WIDTH = 240;
const MOST_TESTS = 15;
const TAIL_LINES = 8;

/** The headings PHPUnit puts above the tests that count against a run. Notices, deprecations and warnings come under others and are left out. */
const PROBLEM_SECTION = /^There (?:was|were) \d+ (?:failure|failures|error|errors|risky test|risky tests):$/;
const OTHER_SECTION = /^There (?:was|were) \d+ /;
const TEST_HEADING = /^\d+\) (.+)$/;

/** @param {string} line */
function trimmed(line) {
    const text = line.trim();

    return text.length > REASON_WIDTH ? `${text.slice(0, REASON_WIDTH - 1)}…` : text;
}

/**
 * What one run printed: its seed, how long it took, the tests that failed (or broke, or were risky) with the first lines of why, and the
 * last lines of the log, which are the answer when the run died without a report (memory used up, a fatal error).
 *
 * @param {string} log
 */
export function readRun(log) {
    const lines = log.replace(/\r/g, '').split('\n');
    const failures = [];
    let inProblems = false;
    let current = null;
    let reasonEnded = false;

    for (const line of lines) {
        if (PROBLEM_SECTION.test(line)) {
            inProblems = true;
            current = null;

            continue;
        }

        if (OTHER_SECTION.test(line) || /^(?:FAILURES!|ERRORS!|OK\b|WARNINGS!)/.test(line)) {
            inProblems = false;
            current = null;

            continue;
        }

        if (!inProblems) {
            continue;
        }

        const heading = TEST_HEADING.exec(line);

        if (heading) {
            current = { test: heading[1].trim(), reason: [] };
            reasonEnded = false;
            failures.push(current);

            continue;
        }

        // The message is the lines under the heading up to the first blank one; what follows is the stack trace.
        if (current && !reasonEnded) {
            if (line.trim() === '') {
                reasonEnded = current.reason.length > 0;
            } else if (current.reason.length < REASON_LINES) {
                current.reason.push(trimmed(line));
            }
        }
    }

    const seed = /^Random Seed:\s+(\d+)/m.exec(log)?.[1] ?? null;
    const time = /^Time: (.+?)(?:, Memory: (.+))?\.?$/m.exec(log);
    const totals = /^Tests: .+$/m.exec(log)?.[0] ?? null;
    const tail = lines
        .filter((line) => line.trim() !== '')
        .slice(-TAIL_LINES)
        .map(trimmed);

    return { seed, time: time?.[1] ?? null, memory: time?.[2] ?? null, totals, failures, tail };
}

/** A workflow command's message and title are escaped, or a newline ends the annotation (GitHub's documented encoding). */
function escapeData(text) {
    return text.replace(/%/g, '%25').replace(/\r/g, '%0D').replace(/\n/g, '%0A');
}

function escapeProperty(text) {
    return escapeData(text).replace(/:/g, '%3A').replace(/,/g, '%2C');
}

/**
 * @param {ReturnType<typeof readRun>} run
 * @param {boolean} succeeded  the exit code of PHPUnit, not the text: a run that died prints no report at all
 * @param {number} number
 * @param {number} runs
 */
export function annotation(run, succeeded, number, runs) {
    const took = run.time ? ` in ${run.time}${run.memory ? `, ${run.memory}` : ''}` : '';

    if (succeeded) {
        return `::notice title=${escapeProperty(`run ${number} of ${runs} passed`)}::${escapeData(`Seed ${run.seed ?? 'unknown'}${took}`)}`;
    }

    const body = [
        `Seed ${run.seed ?? 'unknown'}${took}. Reproduce the order with: vendor/bin/phpunit --order-by=random --random-order-seed=${run.seed ?? '<seed>'}`,
    ];

    if (run.totals) {
        body.push(run.totals);
    }

    if (run.failures.length > 0) {
        for (const { test, reason } of run.failures.slice(0, MOST_TESTS)) {
            body.push('', test, ...reason.map((line) => `    ${line}`));
        }

        if (run.failures.length > MOST_TESTS) {
            body.push('', `… and ${run.failures.length - MOST_TESTS} more`);
        }
    } else {
        body.push('', 'PHPUnit printed no list of failed tests. The last lines of its output:', ...run.tail.map((line) => `    ${line}`));
    }

    return `::error title=${escapeProperty(`run ${number} of ${runs} failed`)}::${escapeData(body.join('\n'))}`;
}

/** The same, for the page of the job itself (Markdown). */
export function summary(run, succeeded, number, runs) {
    const heading = `### Run ${number} of ${runs}: ${succeeded ? 'passed' : 'failed'}`;
    const facts = [`Seed \`${run.seed ?? 'unknown'}\``, run.time && `time ${run.time}`, run.memory && `memory ${run.memory}`]
        .filter(Boolean)
        .join(', ');

    if (succeeded) {
        return `${heading}\n\n${facts}\n`;
    }

    const tests =
        run.failures.length > 0
            ? run.failures
                  .slice(0, MOST_TESTS)
                  .map(({ test, reason }) => `- \`${test}\`${reason.length ? `\n  ${reason[0]}` : ''}`)
                  .join('\n')
            : `PHPUnit printed no list of failed tests. Last lines:\n\n\`\`\`\n${run.tail.join('\n')}\n\`\`\``;

    return `${heading}\n\n${facts}. \`vendor/bin/phpunit --order-by=random --random-order-seed=${run.seed ?? '<seed>'}\`\n\n${tests}\n`;
}

if (import.meta.url === pathToFileURL(process.argv[1] ?? '').href) {
    const [file, number, runs, status = 'failed'] = process.argv.slice(2);

    if (!file || !number || !runs) {
        console.error('Usage: node scripts/phpunit-failures.mjs <log of one run> <number of the run> <runs in all> [passed|failed]');
        process.exit(2);
    }

    const run = readRun(fs.readFileSync(file, 'utf8'));
    const succeeded = status === 'passed';

    console.log(annotation(run, succeeded, Number(number), Number(runs)));

    if (process.env.GITHUB_STEP_SUMMARY) {
        fs.appendFileSync(process.env.GITHUB_STEP_SUMMARY, `${summary(run, succeeded, Number(number), Number(runs))}\n`);
    }
}
