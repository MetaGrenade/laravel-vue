/** What the ACP search card is given (docs/search-index.md). */

export type SearchHealthStatus = 'unused' | 'healthy' | 'attention' | 'down';

export interface SearchIndexHealth {
    name: string;
    exists: boolean;
    /** How many documents the index holds; null when it does not exist. */
    documents: number | null;
    /** How many the database says it should hold. */
    expected: number;
    /** Meilisearch is still working through updates, so the count may change. */
    indexing: boolean;
    /** Whether the settings are the coded ones; null when the index does not exist. */
    settings_in_sync: boolean | null;
}

export interface SearchHealthReport {
    status: SearchHealthStatus;
    driver: string;
    host: string | null;
    version: string | null;
    /** Search is being served from the database for a short while after a failure. */
    circuit_open: boolean;
    last_synced_at: string | null;
    last_reindexed_at: string | null;
    failed_tasks: number;
    indexes: SearchIndexHealth[];
    /** What is wrong, and the command that puts it right. */
    problems: string[];
    /** Worth knowing, not wrong. */
    notes: string[];
}
