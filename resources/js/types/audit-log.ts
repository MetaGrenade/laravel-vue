/** One thing that changed in an audit entry (see docs/audit-log.md). */
export interface AuditChange {
    /** The kind of record: `User`, `Role`... */
    model: string;
    id: number | string | null;
    /** What the record was called then. */
    label: string;
    /** `created`, `updated`, `deleted`, or `roles.attached`, `roles.detached`, `permissions.attached`, `permissions.detached`. */
    action: string;
    /** What it was and what it became, for each attribute that changed. */
    old?: Record<string, unknown>;
    new?: Record<string, unknown>;
    /** The names of the roles or permissions added or taken away. */
    values?: string[];
}

/** Everything else about the request an entry records. */
export interface AuditMeta {
    route?: string | null;
    method?: string;
    path?: string;
    status?: number;
    /** The names of what the request sent, never its values. */
    fields?: string[];
    models?: Record<string, number | string>;
    changes_not_kept?: number;
    [key: string]: unknown;
}

export interface AuditEntry {
    id: number;
    created_at: string | null;
    actor: { id: number | null; label: string | null };
    event: string;
    event_label: string;
    outcome: 'succeeded' | 'failed' | 'denied';
    subject: { type: string; id: number | null; label: string | null } | null;
    /** How many accounts it concerns: several for a bulk action, which `changes` may not all describe. */
    accounts_count: number;
    ip_address: string | null;
    user_agent: string | null;
    changes: AuditChange[];
    meta: AuditMeta;
}
