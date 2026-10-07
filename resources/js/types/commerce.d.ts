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
        amount: string;
    };
    tax?: {
        lines: TaxLineQuote[];
        total: string;
        region_required: boolean;
    };
    discount_total?: string;
    grand_total?: string;
}
