import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { describe, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { annotation, readRun, summary } from './phpunit-failures.mjs';

const script = path.join(path.dirname(fileURLToPath(import.meta.url)), 'phpunit-failures.mjs');

const header = ['PHPUnit 12.5.38 by Sebastian Bergmann and contributors.', '', 'Runtime:       PHP 8.4.27', 'Random Seed:   1794158024', ''].join(
    '\n',
);

/** What PHPUnit prints at the end of a run that had one failure and one error, with a notice section that is not a problem. */
const broken = `${header}
...........................................................  60 / 4202 (  1%)
Time: 03:41.123, Memory: 371.00 MB

There was 1 error:

1) Tests\\Feature\\Support\\SupportSlaSettingsTest::a_time_that_is_not_a_time_is_refused
RuntimeException: The cache is gone.
and a second line of the message

/app/tests/Feature/Support/SupportSlaSettingsTest.php:44
#0 /app/vendor/phpunit/phpunit/src/Framework/TestCase.php(1234): something()
#1 {main}

--

There was 1 failure:

1) Tests\\Feature\\Blog\\BlogTest::the_latest_post_comes_first
Failed asserting that two strings are identical.
--- Expected
+++ Actual

/app/tests/Feature/Blog/BlogTest.php:90

There were 3 PHPUnit notices:

1) Tests\\Feature\\SomethingTest::notice_only
No expectations were configured for the mock object.

ERRORS!
Tests: 4202, Assertions: 30866, Errors: 1, Failures: 1, PHPUnit Notices: 3, Skipped: 4.
`;

const passed = `${header}
...........................................................  60 / 4202 (  1%)
Time: 03:10.500, Memory: 369.00 MB

OK, but there were issues!
Tests: 4202, Assertions: 30866, PHPUnit Notices: 3, Skipped: 4.
`;

describe('reading a run', () => {
    test('names the tests that failed or broke, with the first lines of why, and leaves out the notices', () => {
        const run = readRun(broken);

        assert.equal(run.seed, '1794158024');
        assert.equal(run.time, '03:41.123');
        assert.equal(run.memory, '371.00 MB');
        assert.match(run.totals, /^Tests: 4202, .*Errors: 1, Failures: 1/);
        assert.deepEqual(
            run.failures.map((failure) => failure.test),
            [
                'Tests\\Feature\\Support\\SupportSlaSettingsTest::a_time_that_is_not_a_time_is_refused',
                'Tests\\Feature\\Blog\\BlogTest::the_latest_post_comes_first',
            ],
        );
        // The message stops at the first blank line: the file name and the stack trace under it are not part of it.
        assert.deepEqual(run.failures[0].reason, ['RuntimeException: The cache is gone.', 'and a second line of the message']);
        assert.deepEqual(run.failures[1].reason, ['Failed asserting that two strings are identical.', '--- Expected', '+++ Actual']);
    });

    test('finds nothing to name in a run that passed', () => {
        const run = readRun(passed);

        assert.deepEqual(run.failures, []);
        assert.equal(run.time, '03:10.500');
    });

    test('keeps the last lines of a run that died without a report', () => {
        const run = readRun(
            `${header}\n......\nPHP Fatal error:  Allowed memory size of 536870912 bytes exhausted (tried to allocate 20480 bytes) in /app/x.php on line 3\n`,
        );

        assert.deepEqual(run.failures, []);
        assert.equal(run.seed, '1794158024');
        assert.match(run.tail.at(-1), /Allowed memory size of 536870912 bytes exhausted/);
    });

    test('reads a log saved on Windows (carriage returns) the same way', () => {
        assert.deepEqual(readRun(broken.replace(/\n/g, '\r\n')).failures.length, 2);
    });
});

describe('the annotation', () => {
    test('a failed run names the seed, the command that reproduces it and every test, on one line a workflow command can carry', () => {
        const text = annotation(readRun(broken), false, 5, 10);

        assert.ok(text.startsWith('::error title=run 5 of 10 failed::'));
        assert.equal(text.split('\n').length, 1, 'a newline would end the command');
        assert.match(text, /Seed 1794158024 in 03:41\.123, 371\.00 MB/);
        assert.match(text, /--random-order-seed=1794158024/);
        assert.match(text, /SupportSlaSettingsTest::a_time_that_is_not_a_time_is_refused/);
        assert.match(text, /BlogTest::the_latest_post_comes_first/);
        assert.match(text, /%0A/);
        // A backslash in a class name is not a special character; a percent sign is, and has to be written as %25.
        assert.ok(!/%(?![0-9A-F]{2})/.test(text.slice('::error '.length)));
    });

    test('escapes a percent sign and the characters a title cannot hold', () => {
        const text = annotation(
            { seed: '1', time: null, memory: null, totals: null, failures: [{ test: 'A::b', reason: ['50% of 100'] }], tail: [] },
            false,
            1,
            2,
        );

        assert.match(text, /50%25 of 100/);
        assert.match(text, /^::error title=run 1 of 2 failed::/);
    });

    test('a run that died without a report shows the end of its output instead of an empty list', () => {
        const text = annotation(readRun(`${header}\nPHP Fatal error:  Allowed memory size exhausted\n`), false, 2, 10);

        assert.match(text, /printed no list of failed tests/);
        assert.match(text, /Allowed memory size exhausted/);
    });

    test('says how many more tests there were when the list is long', () => {
        const many = Array.from({ length: 20 }, (_, index) => `${index + 1}) Tests\\T::t${index}\nwrong\n`).join('\n');
        const text = annotation(readRun(`${header}\nThere were 20 failures:\n\n${many}\nFAILURES!\nTests: 20, Failures: 20.\n`), false, 1, 1);

        assert.match(text, /Tests\\T::t14/);
        assert.ok(!text.includes('Tests\\T::t15'));
        assert.match(text, /and 5 more/);
    });

    test('a run that passed is a notice with its seed and time', () => {
        assert.equal(annotation(readRun(passed), true, 2, 10), '::notice title=run 2 of 10 passed::Seed 1794158024 in 03:10.500, 369.00 MB');
    });

    test('the exit code decides, not the text: a failed run with no failures listed is still a failure', () => {
        assert.ok(annotation(readRun(passed), false, 3, 10).startsWith('::error '));
    });
});

describe('the summary', () => {
    test('lists the failed tests and the command, or just the time when the run passed', () => {
        assert.match(summary(readRun(broken), false, 5, 10), /### Run 5 of 10: failed[\s\S]*BlogTest::the_latest_post_comes_first/);
        assert.match(summary(readRun(passed), true, 2, 10), /### Run 2 of 10: passed[\s\S]*time 03:10\.500/);
    });
});

describe('the command', () => {
    function run(logText, status) {
        const dir = fs.mkdtempSync(path.join(tmpdir(), 'phpunit-failures-'));

        try {
            const log = path.join(dir, 'run.log');
            const stepSummary = path.join(dir, 'summary.md');

            fs.writeFileSync(log, logText);

            const stdout = execFileSync(process.execPath, [script, log, '4', '10', status], {
                encoding: 'utf8',
                env: { ...process.env, GITHUB_STEP_SUMMARY: stepSummary },
            });

            return { stdout, summary: fs.readFileSync(stepSummary, 'utf8') };
        } finally {
            fs.rmSync(dir, { recursive: true, force: true });
        }
    }

    test('prints the annotation and adds the run to the job summary', () => {
        const failed = run(broken, 'failed');

        assert.match(failed.stdout, /^::error title=run 4 of 10 failed::/);
        assert.match(failed.summary, /### Run 4 of 10: failed/);
        assert.match(run(passed, 'passed').stdout, /^::notice title=run 4 of 10 passed::/);
    });

    test('says how it is used when it is given nothing', () => {
        assert.throws(
            () => execFileSync(process.execPath, [script], { stdio: 'pipe' }),
            (error) => error.status === 2 && /Usage/.test(String(error.stderr)),
        );
    });
});
