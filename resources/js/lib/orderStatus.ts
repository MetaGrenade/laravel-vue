import type { BadgeVariants } from '@/components/ui/badge';

type Variant = NonNullable<BadgeVariants['variant']>;

/** How an order's fulfilment status is drawn as a badge. */
export function orderStatusVariant(status: string): Variant {
    switch (status) {
        case 'completed':
            return 'default';
        case 'processing':
            return 'secondary';
        case 'cancelled':
            return 'destructive';
        default:
            return 'outline';
    }
}

/** How an order's payment status is drawn as a badge. */
export function paymentStatusVariant(status: string): Variant {
    switch (status) {
        case 'paid':
            return 'default';
        case 'partially_refunded':
        case 'refunded':
            return 'highlight';
        case 'failed':
            return 'destructive';
        default:
            return 'outline';
    }
}

/** Whether the customer's money was received (including money later returned). */
export function hasReceivedPayment(status: string): boolean {
    return status === 'paid' || status === 'partially_refunded' || status === 'refunded';
}

/** A date and time in the viewer's locale; a dash when there is none. */
export function formatDateTime(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
}
