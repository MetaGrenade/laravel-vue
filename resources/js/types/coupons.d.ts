export type CouponKind = 'percent' | 'fixed' | 'free_shipping';

/** Where a code stands: switched off, past its end, not started, every use taken, or usable. */
export type CouponStatus = 'active' | 'inactive' | 'scheduled' | 'expired' | 'used_up';

/** A discount code as the list shows it. */
export interface CouponRow {
    id: number;
    code: string;
    description: string | null;
    type: CouponKind;
    /** A percentage, or an amount in `currency`; null for free shipping. */
    value: string | null;
    currency: string | null;
    /** A fixed amount in another currency than the shop sells in: it cannot be applied. */
    currency_mismatch: boolean;
    minimum_subtotal: string | null;
    starts_at: string | null;
    ends_at: string | null;
    max_redemptions: number | null;
    max_redemptions_per_customer: number | null;
    is_active: boolean;
    status: CouponStatus;
    /** Orders it is on that have not been cancelled. */
    uses: number;
    /** Limited to some products or categories. */
    restricted: boolean;
}

/** A discount code as the form edits it. */
export interface CouponDetails {
    id: number;
    code: string;
    description: string | null;
    type: CouponKind;
    value: string | null;
    currency: string | null;
    minimum_subtotal: string | null;
    starts_at: string | null;
    ends_at: string | null;
    max_redemptions: number | null;
    max_redemptions_per_customer: number | null;
    is_active: boolean;
    status: CouponStatus;
    category_ids: number[];
    products: { id: number; name: string }[];
}

export interface CouponUsage {
    orders: number;
    /** Total taken off those orders, a decimal string. */
    discounted: string;
}
