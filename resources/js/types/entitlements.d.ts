export type EntitlementKind = 'feature' | 'limit';

/** One entitlement on the ACP list. */
export interface EntitlementRow {
    id: number;
    key: string;
    label: string;
    description: string | null;
    type: EntitlementKind;
    type_label: string;
    /** How many Tebex packages are set to give it. */
    packages_count: number;
    /** How many subscription plans are set to give it. */
    plans_count: number;
    /** Grants that count now (not taken back, not past their end), one per order line that gave it. */
    grants_count: number;
}

/** What a Tebex package can be set to give: the list the package dialog chooses from. */
export interface EntitlementChoice {
    id: number;
    key: string;
    label: string;
    type: EntitlementKind;
}

/** What one package is set to give. For a limit, `value` is how much one unit adds. */
export interface PackageEntitlement extends EntitlementChoice {
    value: number | null;
}

/** What a subscription plan gives, as visitors to the pricing page see it. */
export interface PlanGiven {
    key: string;
    label: string;
    type: EntitlementKind;
    /** How much of a limit; null for a feature. */
    value: number | null;
}

/** What a customer's purchases and plan give them, each entitlement once with a limit's grants added up. */
export interface HeldEntitlement {
    key: string;
    label: string;
    description: string | null;
    type: EntitlementKind;
    value: number | null;
}

/** What an order gave the account, as staff see it. */
export interface OrderGrant {
    id: number;
    key: string;
    label: string;
    type: EntitlementKind;
    value: number | null;
    /** The order line it came with. */
    line: string | null;
    active: boolean;
    revoked_at: string | null;
    /** `refund` or `subscription`. */
    revoked_reason: string | null;
    /** False for a guest's order: there is no account to hold it yet. */
    held_by_account: boolean;
}
