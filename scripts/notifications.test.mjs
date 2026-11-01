// What the bell makes of an alert pushed to the browser: `npm run bundle:test`.
// The helper is TypeScript; Node strips the types when it imports the file.

import assert from 'node:assert/strict';
import { describe, test } from 'node:test';
import { BELL_LIMIT, notificationFromPush, withPushedNotification } from '../resources/js/lib/notifications.ts';

const NOW = new Date('2026-11-01T10:00:00.000Z');

// What Laravel pushes for a followed thread: the notification's own fields, with the id and the type beside them.
const PUSHED = {
    title: 'New reply in "Something to Test"',
    excerpt: 'Ada: it works',
    url: 'https://metaforge.test/forum/general/something-to-test?post=7',
    thread_title: 'Something to Test',
    id: '6b0f2c1e-0000-4000-8000-000000000001',
    type: 'App\\Notifications\\ForumThreadUpdated',
};

const item = (id, overrides = {}) => ({
    id,
    type: 'App\\Notifications\\ForumThreadUpdated',
    title: `Title ${id}`,
    excerpt: null,
    url: null,
    data: {},
    created_at: NOW.toISOString(),
    created_at_for_humans: 'Just now',
    read_at: null,
    ...overrides,
});

describe('an alert pushed to the browser', () => {
    test('keeps its title, text and link when the fields sit at the top of the push', () => {
        const line = notificationFromPush(PUSHED, NOW);

        assert.equal(line.id, PUSHED.id);
        assert.equal(line.type, PUSHED.type);
        assert.equal(line.title, 'New reply in "Something to Test"');
        assert.equal(line.excerpt, 'Ada: it works');
        assert.equal(line.url, PUSHED.url);
        assert.equal(line.created_at, NOW.toISOString());
        assert.equal(line.created_at_for_humans, 'Just now');
        assert.equal(line.read_at, null);
    });

    test('keeps the id and the type out of the data it carries', () => {
        const line = notificationFromPush(PUSHED, NOW);

        assert.equal(line.data.thread_title, 'Something to Test');
        assert.equal('id' in line.data, false);
        assert.equal('type' in line.data, false);
    });

    test('is understood in the shape of a stored row too', () => {
        const line = notificationFromPush(
            { id: 'abc', type: 'T', data: { title: 'Stored', url: '/somewhere' }, created_at: '2026-10-31T08:00:00.000Z' },
            NOW,
        );

        assert.equal(line.title, 'Stored');
        assert.equal(line.url, '/somewhere');
        assert.equal(line.created_at, '2026-10-31T08:00:00.000Z');
    });

    test('is titled after its thread when it has no title, and only then called "Notification"', () => {
        assert.equal(notificationFromPush({ id: 'a', thread_title: 'A thread' }, NOW).title, 'A thread');
        assert.equal(notificationFromPush({ id: 'a' }, NOW).title, 'Notification');
        assert.equal(notificationFromPush({ id: 'a', title: '   ' }, NOW).title, 'Notification');
    });

    test('has no link and no text unless it was sent with them', () => {
        const line = notificationFromPush({ id: 'a', title: 'Hi', url: 42, excerpt: null }, NOW);

        assert.equal(line.url, null);
        assert.equal(line.excerpt, null);
    });

    test('is dropped when there is no id to tell it apart by', () => {
        assert.equal(notificationFromPush({ title: 'No id' }, NOW), null);
        assert.equal(notificationFromPush({ id: '' }, NOW), null);
        assert.equal(notificationFromPush(null, NOW), null);
        assert.equal(notificationFromPush('text', NOW), null);
        assert.equal(notificationFromPush([PUSHED], NOW), null);
    });
});

describe('the bell when an alert arrives', () => {
    test('shows it at the top and counts it', () => {
        const state = withPushedNotification({ items: [item('old')], unreadCount: 1, hasMore: false }, item('new'));

        assert.deepEqual(
            state.items.map((line) => line.id),
            ['new', 'old'],
        );
        assert.equal(state.unreadCount, 2);
        assert.equal(state.hasMore, false);
    });

    test('does not count an alert the list already has, and moves it to the top', () => {
        const state = withPushedNotification({ items: [item('a'), item('b')], unreadCount: 2, hasMore: false }, item('b', { title: 'Again' }));

        assert.deepEqual(
            state.items.map((line) => line.id),
            ['b', 'a'],
        );
        assert.equal(state.items[0].title, 'Again');
        assert.equal(state.unreadCount, 2);
    });

    test('lists no more than the bell holds, and says there are more', () => {
        const full = Array.from({ length: BELL_LIMIT }, (_, number) => item(`n${number}`));
        const state = withPushedNotification({ items: full, unreadCount: BELL_LIMIT, hasMore: false }, item('latest'));

        assert.equal(state.items.length, BELL_LIMIT);
        assert.equal(state.items[0].id, 'latest');
        assert.equal(state.unreadCount, BELL_LIMIT + 1);
        assert.equal(state.hasMore, true);
    });

    test('leaves the list it was given as it was', () => {
        const items = [item('old')];

        withPushedNotification({ items, unreadCount: 1, hasMore: false }, item('new'));

        assert.equal(items.length, 1);
    });
});
