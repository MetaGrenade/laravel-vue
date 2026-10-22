/** Where an account stands on one metered thing this month (see docs/usage.md). */
export interface UsageSummary {
    meter: string;
    label: string;
    unit: string;
    used: number;
    /** What it may use in the month, or null for no limit. */
    allowance: number | null;
    remaining: number | null;
    /** Whether it has used all it may. */
    exhausted: boolean;
    /** Whether more is refused (the meter's overage is `block`), not just counted. */
    blocks: boolean;
    /** When the month ends and the count starts again. */
    resets_at: string;
}
