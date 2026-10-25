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

/** What a rule of the forum's automatic moderation looks for, by kind (docs/automod.md). */
export type AutomodRuleType = 'words' | 'links' | 'duplicate' | 'rate';

/** `hold` keeps a post for a moderator; `reject` refuses it. */
export type AutomodAction = 'hold' | 'reject';

export interface AutomodRule {
    id: number;
    name: string;
    type: AutomodRuleType;
    settings: {
        phrases?: string[];
        whole_words?: boolean;
        max_links?: number | null;
        blocked_domains?: string[];
        minutes?: number;
        copies?: number;
        posts?: number;
    };
    action: AutomodAction;
    /** Only for members whose account is younger than this many days; null: for everybody who is not staff. */
    only_new_days: number | null;
    is_enabled: boolean;
    hits: number;
    last_hit_at: string | null;
}

/** A rule that would catch the text that was tried. */
export interface AutomodTried {
    rule: string;
    action: AutomodAction;
    detail: string;
}

/** One thing in the review queue: a reply or a thread that a rule has held. */
export interface HeldItem {
    id: number;
    kind: 'reply' | 'thread';
    title: string | null;
    excerpt: string;
    reason: string | null;
    held_at: string | null;
    board: string | null;
    url: string | null;
    author: { id: number; nickname: string; account_created_at: string | null; approved_posts: number } | null;
    moderate_url: string | null;
}
