/** What the customer dashboard and the order history receive about shop activity. */

/** What an order holds that the customer may need to open it for. Counts only: a key is shown on the order page. */
export interface OrderDigital {
    /** Licence keys the customer holds now. */
    keys: number;
    /** Licence keys paid for and still to come. */
    keys_waiting: number;
    /** Whether any file can still be downloaded. */
    downloads: boolean;
}

export interface CustomerOrderItem {
    id: number;
    description: string | null;
    quantity: number;
    subtotal: string;
}

export interface CustomerOrder {
    id: number;
    number: string;
    status: string;
    status_label: string;
    payment_status: string;
    payment_status_label: string;
    currency: string;
    grand_total: string;
    refunded_total: string;
    created_at: string;
    /** The order page, where keys and downloads are found. */
    url: string;
    items: CustomerOrderItem[];
    digital: OrderDigital;
}

/** A line of a paid order that delivers a licence key or files. */
export interface DigitalPurchase {
    id: number;
    description: string;
    quantity: number;
    order_number: string | null;
    url: string | null;
    purchased_at: string | null;
    has_keys: boolean;
    keys: number;
    keys_waiting: number;
    downloads: boolean;
}

export interface DashboardShop {
    summary: {
        orders: number;
        awaiting_payment: number;
        in_progress: number;
        digital: number;
        keys_waiting: number;
    };
    recent_orders: CustomerOrder[];
    digital: DigitalPurchase[];
}

export interface DashboardPlan {
    state: 'none' | 'active' | 'grace' | 'attention' | 'ended';
    name: string | null;
    /** A decimal in the currency's major units ("12.00"), ready to format. */
    price: string | null;
    currency: string | null;
    interval: string | null;
    ends_at: string | null;
}

export interface DashboardAccount {
    two_factor_enabled: boolean;
    reputation: number;
    badges: number;
    member_since: string | null;
}
