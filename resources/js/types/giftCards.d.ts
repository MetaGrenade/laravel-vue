/** What the ACP receives about gift cards. */

export type GiftCardStatus = 'active' | 'used_up' | 'expired' | 'disabled';

/** A card as the team sees it: the end of its code only, never the code (which is not kept). */
export interface GiftCardRow {
    id: number;
    masked: string;
    last_four: string;
    currency: string;
    balance: string;
    initial_amount: string;
    status: GiftCardStatus;
    expires_at: string | null;
    note: string | null;
    issued_by: string | null;
    created_at: string | null;
}

export interface GiftCardLedgerRow {
    id: number;
    type: 'issued' | 'redeemed' | 'released' | 'refunded' | 'adjusted';
    /** Signed: what spending takes off is negative. */
    amount: string;
    balance_after: string;
    note: string | null;
    by: string | null;
    order: { number: string; public_id: string } | null;
    created_at: string | null;
}
