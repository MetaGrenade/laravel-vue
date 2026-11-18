// What the admin forms do with a time typed into a `datetime-local` box: `npm run bundle:test`.
// The helper is TypeScript; Node strips the types when it imports the file. The zone of a Node process is read when it starts, so each zone
// is a process of its own (`TZ` in its environment), and the helper is run in it.

import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { describe, test } from 'node:test';
import { fileURLToPath, pathToFileURL } from 'node:url';

const root = fileURLToPath(new URL('..', import.meta.url));
const helper = pathToFileURL(join(root, 'resources/js/lib/localTime.ts')).href;

/** Runs `body` (the code of an async function that can use `inputFromInstant` and `instantFromInput`) with the clock of `zone`, and returns what it returns, from JSON. */
const inZone = (zone, body) =>
    JSON.parse(
        execFileSync(
            process.execPath,
            [
                '--input-type=module',
                '-e',
                `import { inputFromInstant, instantFromInput } from ${JSON.stringify(helper)};\nconsole.log(JSON.stringify(await (async () => { ${body} })()));`,
            ],
            { env: { ...process.env, TZ: zone, NODE_NO_WARNINGS: '1' }, encoding: 'utf8' },
        ),
    );

// 9:00 on Wednesday 10 March 2027 on the clock of each zone, as UTC; and what 16:00 UTC is on the clock of each. No zone below changes its
// clock between the 9th and the 11th (daylight saving starts on the 14th in North America), so the offsets are fixed.
const ZONES = [
    { zone: 'UTC', nine: '2027-03-10T09:00:00.000Z', sixteen: '2027-03-10T16:00' },
    { zone: 'America/Denver', nine: '2027-03-10T16:00:00.000Z', sixteen: '2027-03-10T09:00' },
    { zone: 'America/New_York', nine: '2027-03-10T14:00:00.000Z', sixteen: '2027-03-10T11:00' },
    { zone: 'America/St_Johns', nine: '2027-03-10T12:30:00.000Z', sixteen: '2027-03-10T12:30' },
    { zone: 'Asia/Tokyo', nine: '2027-03-10T00:00:00.000Z', sixteen: '2027-03-11T01:00' },
    { zone: 'Asia/Kolkata', nine: '2027-03-10T03:30:00.000Z', sixteen: '2027-03-10T21:30' },
    { zone: 'Pacific/Kiritimati', nine: '2027-03-09T19:00:00.000Z', sixteen: '2027-03-11T06:00' },
    { zone: 'Pacific/Pago_Pago', nine: '2027-03-10T20:00:00.000Z', sixteen: '2027-03-10T05:00' },
];

describe('a time typed into an admin form', () => {
    for (const { zone, nine, sixteen } of ZONES) {
        test(`${zone}: 09:00 in the box is the moment ${nine}, and 16:00 UTC is ${sixteen.slice(11)} in the box`, () => {
            const out = inZone(zone, `return { sent: instantFromInput('2027-03-10T09:00'), shown: inputFromInstant('2027-03-10T16:00:00.000Z') };`);

            assert.equal(out.sent, nine);
            assert.equal(out.shown, sixteen);
        });

        test(`${zone}: the box and the moment make one another back, so saving a form untouched moves nothing`, () => {
            const out = inZone(
                zone,
                `
                const boxes = ['2027-03-10T09:00', '2027-01-01T00:00', '2027-12-31T23:59', '2027-07-04T12:30'];
                // What the server sends is a moment in some zone (the admin's profile zone, or UTC); the form fills the box from it,
                // and sends a moment back. It has to be the same one, however many times the form is saved.
                const stored = ['2027-03-10T09:00:00-07:00', '2027-03-10T16:00:00+00:00', '2027-03-11T01:00:00+09:00'];
                return {
                    boxes: boxes.map((box) => inputFromInstant(instantFromInput(box)) === box),
                    saves: stored.map((moment) => {
                        const once = instantFromInput(inputFromInstant(moment));
                        const twice = instantFromInput(inputFromInstant(once));
                        return once === new Date(moment).toISOString() && twice === once;
                    }),
                };`,
            );

            assert.deepEqual(out.boxes, [true, true, true, true]);
            assert.deepEqual(out.saves, [true, true, true]);
        });
    }

    test('an empty box stays empty, and a value that is not a date is left for the server to refuse', () => {
        const out = inZone(
            'America/Denver',
            `return [instantFromInput(''), instantFromInput(null), instantFromInput(undefined), instantFromInput('next tuesday'), inputFromInstant(''), inputFromInstant(null), inputFromInstant(undefined), inputFromInstant('next tuesday')];`,
        );

        assert.deepEqual(out, ['', '', '', 'next tuesday', '', '', '', '']);
    });

    test('a Date is accepted where a moment is', () => {
        const out = inZone('America/Denver', `return inputFromInstant(new Date('2027-03-10T16:00:00.000Z'));`);

        assert.equal(out, '2027-03-10T09:00');
    });
});

// Every box that holds a time has to send a moment. A new form that posts `v-model` as it is brings the whole thing back: the post is
// scheduled for the wrong hours, and saving it again moves it.
const vueFiles = (dir) =>
    readdirSync(dir).flatMap((name) => {
        const path = join(dir, name);

        return statSync(path).isDirectory() ? vueFiles(path) : path.endsWith('.vue') ? [path] : [];
    });

// Read by the day on the server (`startOfDay()`, `endOfDay()`), as a filter of a list and not a time that is kept.
const FILTERS = new Set(['date_from', 'date_to']);

describe('the forms of the site', () => {
    const boxes = [];

    for (const path of vueFiles(join(root, 'resources/js'))) {
        const source = readFileSync(path, 'utf8');

        for (const tag of source.match(/<Input\b[^>]*\btype="datetime-local"[^>]*>/g) ?? []) {
            const model = /v-model="([^"]+)"/.exec(tag)?.[1];

            assert.ok(model, `${relative(root, path)}: a datetime-local box with no v-model: ${tag}`);
            boxes.push({ file: relative(root, path).replaceAll('\\', '/'), source, field: model.split('.').pop() });
        }
    }

    test('the check finds the boxes it is there for', () => {
        // Blog create and edit, poll create and edit, token create and edit, the two of Trust & Safety, the two of the coupon, the two
        // filters of the token log. A smaller number means the pattern stopped matching.
        assert.ok(boxes.length >= 14, `found ${boxes.length}`);
    });

    // A file may hold two boxes of one name (the token dialogs for making and for editing): each needs its own conversion.
    const groups = new Map();

    for (const box of boxes) {
        const key = `${box.file}|${box.field}`;

        groups.set(key, { ...box, count: (groups.get(key)?.count ?? 0) + 1 });
    }

    for (const { file, source, field, count } of groups.values()) {
        if (FILTERS.has(field)) {
            continue;
        }

        test(`${file}: ${field} is sent as the moment it names (${count} box${count === 1 ? '' : 'es'})`, () => {
            // `instantFromInput(data.field)`, or what the coupon form does, `dayjs(data.field).toISOString()`. Other uses of the same field
            // in the file (`dayjs(token.expires_at).format(...)`, which fills the box) do not count.
            const converted = new RegExp(String.raw`instantFromInput\([^)]*\b${field}\b|dayjs\([^)]*\b${field}\b[^)]*\)\.toISOString\(\)`, 'g');

            assert.ok(
                (source.match(converted) ?? []).length >= count,
                `${file}: ${field} is a datetime-local box and is sent as it is typed (a wall clock with no zone, read by the server as UTC). ` +
                    `Send instantFromInput(data.${field}) from resources/js/lib/localTime.ts in the form's transform.`,
            );
        });
    }
});
