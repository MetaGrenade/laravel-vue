// The notification bell: turning an alert pushed to the browser into a line of the list (docs/notifications.md).
//
// The page is given its notifications as stored rows: `{ id, type, data: { title, url, ... } }`. What Laravel pushes over the
// websocket is not that. It is the notification's own fields at the top level, next to the id and the type:
// `{ title, url, excerpt, id, type }`. Reading `data` out of a push finds nothing, and every alert comes out as "Notification".
import type { NotificationItem } from '@/types';

/** The most the bell lists; the page's own list is cut at the same number (HandleInertiaRequests). */
export const BELL_LIMIT = 10;

export type PushedNotification = Record<string, unknown>;

export type BellState = {
    items: NotificationItem[];
    unreadCount: number;
    hasMore: boolean;
};

const isRecord = (value: unknown): value is Record<string, unknown> => typeof value === 'object' && value !== null && !Array.isArray(value);

const text = (value: unknown): string | null => (typeof value === 'string' && value.trim() !== '' ? value : null);

/**
 * The line a pushed alert becomes, or null when it has no id to tell it apart by.
 *
 * Reads the fields from the top level of the push, and from `data` when there is one (the shape of a stored row), so either is
 * understood. The title is chosen the way the server chooses it for the page's own list.
 */
export function notificationFromPush(pushed: unknown, now: Date = new Date()): NotificationItem | null {
    if (!isRecord(pushed)) {
        return null;
    }

    const id = text(pushed.id);

    if (id === null) {
        return null;
    }

    // The fields of a flat push are the notification's own, so the id and the type that were added beside them are not part of its data.
    const flat = { ...pushed };

    delete flat.id;
    delete flat.type;

    const data = isRecord(pushed.data) ? pushed.data : flat;

    return {
        id,
        type: text(pushed.type) ?? '',
        title: text(data.title) ?? text(data.thread_title) ?? 'Notification',
        excerpt: text(data.excerpt),
        url: text(data.url),
        data,
        created_at: text(pushed.created_at) ?? now.toISOString(),
        created_at_for_humans: 'Just now',
        read_at: text(pushed.read_at),
    };
}

/**
 * The bell with one more alert at the top.
 *
 * An alert whose id is already in the list is the one the page was given, or one pushed twice: it takes its place and is not counted
 * again.
 */
export function withPushedNotification(state: BellState, item: NotificationItem, limit: number = BELL_LIMIT): BellState {
    const known = state.items.some((candidate) => candidate.id === item.id);
    const items = [item, ...state.items.filter((candidate) => candidate.id !== item.id)].slice(0, limit);
    const unreadCount = known ? state.unreadCount : state.unreadCount + 1;

    return { items, unreadCount, hasMore: unreadCount > items.length };
}
