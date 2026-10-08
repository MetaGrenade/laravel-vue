/** What the ACP catalogue screens receive from the server. */

export interface CatalogueLookup {
    id: number;
    name: string;
}

export interface PriceRow {
    id: number;
    amount: string;
    compare_at_amount: string | null;
    currency: string;
    is_active: boolean;
    /** Active and in the shop's currency: the only kind a shopper can be charged. */
    usable: boolean;
}

export interface StockMovement {
    id: number;
    delta: number;
    reason: string;
    note: string | null;
    by: string | null;
    order: { number: string; public_id: string } | null;
    created_at: string | null;
}

export interface StockRow {
    id: number;
    quantity: number;
    allow_backorder: boolean;
    /** False once orders have used it: their reservations are in its history, which is kept. */
    can_untrack: boolean;
    movements: StockMovement[];
}

export interface OptionValueRow {
    id: number;
    value: string;
}

export interface OptionRow {
    id: number;
    name: string;
    display_name: string;
    values: OptionValueRow[];
}

export interface VariantRow {
    id: number;
    name: string;
    sku: string | null;
    option_values: Record<string, string>;
    is_default: boolean;
    is_active: boolean;
    ordered: boolean;
    prices: PriceRow[];
    stock: StockRow | null;
}

export interface ProductDetails {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    brand_id: number | null;
    category_ids: number[];
    tag_ids: number[];
    is_active: boolean;
    requires_shipping: boolean;
    is_taxable: boolean;
    shop_url?: string | null;
}

export interface Readiness {
    ok: boolean;
    text: string;
}

export interface Capabilities {
    create: boolean;
    edit: boolean;
    delete: boolean;
}

export interface TaxonomyEntry {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    products_count: number;
}

export type StockStatus = 'untracked' | 'in_stock' | 'low' | 'out';
