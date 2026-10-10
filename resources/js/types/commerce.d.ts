/** An address as the forms edit it. Every field is a string; empty means not given. */
export interface AddressFormValue {
    name: string;
    company: string;
    line1: string;
    line2: string;
    city: string;
    region: string;
    postal_code: string;
    country: string;
    phone: string;
}

/** A saved address from the customer's address book. */
export interface SavedAddress extends AddressFormValue {
    id: number;
    label: string | null;
    is_default: boolean;
}

export interface ShippingOptionQuote {
    id: number;
    name: string;
    description: string | null;
    amount: string;
}

export interface TaxLineQuote {
    name: string;
    rate: string;
    amount: string;
}

/** The discount code on the cart. `applied` is false when it cannot be used (and `problem` says why). */
export interface CartCoupon {
    code: string;
    applied: boolean;
    problem: string | null;
    free_shipping: boolean;
    /** What it takes off the items now, a decimal string; null when it does not apply. (The cart page only.) */
    discount?: string | null;
}

/** What the checkout page shows for the details entered so far. */
export interface CheckoutQuote {
    error: string | null;
    currency?: string;
    subtotal?: string;
    needs_shipping?: boolean;
    shipping?: {
        can_ship: boolean;
        message: string | null;
        options: ShippingOptionQuote[];
        selected_id: number | null;
        /** The charge before any discount code. */
        amount: string;
        /** What a discount code takes off it (all of it for free shipping). */
        discount?: string;
    };
    coupon?: CartCoupon | null;
    tax?: {
        lines: TaxLineQuote[];
        total: string;
        region_required: boolean;
    };
    discount_total?: string;
    grand_total?: string;
}

/** A product picture as the shop shows it, at three sizes. */
export interface StorefrontImage {
    /** The large size. */
    url: string;
    medium: string;
    thumb: string;
    alt: string;
    /** Of the large size. */
    width: number;
    height: number;
}

export interface StorefrontPrice {
    id: number;
    currency: string;
    amount: string;
    /** The price it used to be, shown struck through. */
    compare_at_amount: string | null;
}

/** How much is left, in words: the shop never shows counts. `backorder` means orders are taken for when it arrives. */
export type StorefrontStock = 'in_stock' | 'low' | 'out' | 'backorder';

export interface StorefrontVariant {
    id: number;
    name: string;
    sku: string | null;
    option_values: Record<string, string>;
    is_default: boolean;
    stock: StorefrontStock;
    prices: StorefrontPrice[];
}

export interface StorefrontOption {
    id: number;
    name: string;
    display_name: string;
    values: string[];
}
