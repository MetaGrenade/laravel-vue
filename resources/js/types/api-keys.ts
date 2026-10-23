/** Something an API key can be limited to (see docs/api-keys.md). */
export interface ApiScopeOption {
    key: string;
    label: string;
    description: string;
}

/** One of a person's API keys. The secret is never here: it is shown once, when the key is made. */
export interface ApiKey {
    id: number;
    name: string;
    status: 'active' | 'expired' | 'revoked';
    /** It holds every scope, including any added later (only staff can make one like that). */
    full_access: boolean;
    scopes: { key: string; label: string }[];
    created_at: string | null;
    last_used_at: string | null;
    expires_at: string | null;
    revoked_at: string | null;
    /** What it used of the account's API calls this month. */
    calls_this_month: number;
    /** Limits staff put on it. */
    hourly_quota: number | null;
    daily_quota: number | null;
}

/** The lifetimes a person can pick from when they make a key. */
export interface ApiKeyExpiry {
    days: number[];
    default: number | null;
    /** Whether a key that never expires is among them. */
    never: boolean;
}
