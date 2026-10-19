/** What the ACP receives about Tebex. Nothing here is secret: the private key and webhook secret never leave the server. */

export type TebexSyncStatus = 'running' | 'ok' | 'failed';

export interface TebexCounts {
    created: number;
    updated: number;
    unchanged: number;
    removed: number;
}

export interface TebexSyncRun {
    id: number;
    status: TebexSyncStatus;
    trigger: 'schedule' | 'command' | 'admin';
    by: string | null;
    started_at: string | null;
    finished_at: string | null;
    counts: { categories?: TebexCounts; packages?: TebexCounts } | null;
    error: string | null;
}

/** The answer to "Test connection", for the request that asked. */
export type TebexTestResult =
    | { ok: true; name: string; currency: string; url: string | null; disabled: boolean; platform: string | null; supported: boolean }
    | { ok: false; message: string };

/** The store as the shop last saw it, what buyers are asked before they can pay, and whether the shop knows its platform. */
export interface TebexStoreInfo {
    name: string | null;
    platform: string | null;
    supported: boolean;
    reason: string | null;
    /** `none`, `username` (Minecraft, Overwolf), `sign-in` (Discord, the Steam-based games), or null for a platform the shop does not know. */
    identity: 'none' | 'username' | 'sign-in' | null;
    username_label: string | null;
}

export interface TebexPackageRow {
    id: number;
    tebex_id: number;
    ident: string | null;
    name: string;
    category: string | null;
    type: 'single' | 'subscription';
    base_price: string;
    sales_tax: string;
    total_price: string;
    discount: string;
    currency: string;
    has_variables: boolean;
    removed: boolean;
}
