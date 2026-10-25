/** What a moderator is given about one member (docs/moderation.md). */

export interface ModerationMember {
    id: number;
    nickname: string;
    email: string;
    avatar_url: string | null;
    roles: string[];
    created_at: string | null;
    last_activity_at: string | null;
    forum_threads: number;
    forum_posts: number;
}

export interface ModerationStanding {
    banned: boolean;
    banned_at: string | null;
    /** When the ban ends by itself; null when it lasts until a moderator lifts it. */
    banned_until: string | null;
    reason: string | null;
    banned_by: string | null;
}

/** One thing that was done: a warning, a ban or the lifting of one. Rows are never changed afterwards. */
export interface ModerationAction {
    id: number;
    type: 'warning' | 'ban' | 'unban';
    reason: string | null;
    /** For staff only: never shown to the member. */
    note: string | null;
    /** Who did it; null when the system did (a suspension that ran out). */
    actor: string | null;
    expires_at: string | null;
    /** Whether the member was emailed. */
    notified: boolean;
    subject: { label: string; url: string | null } | null;
    created_at: string | null;
}

/** What the report a moderator came from is about; the forms attach `reference`. */
export interface ModerationSubject {
    reference: string;
    label: string;
    url: string | null;
}
