import type { CouponKind, CouponStatus } from '@/types/coupons';
import { formatMoney } from './money';

/** What a code does, in a few words: "10% off", "$5.00 off", "Free shipping". */
export function describeCoupon(type: CouponKind, value: string | null, currency: string | null): string {
    switch (type) {
        case 'percent':
            return `${value ?? '0'}% off`;
        case 'fixed':
            return `${formatMoney(value ?? '0', currency ?? 'USD')} off`;
        default:
            return 'Free shipping';
    }
}

export const couponStatusLabels: Record<CouponStatus, string> = {
    active: 'Active',
    inactive: 'Switched off',
    scheduled: 'Scheduled',
    expired: 'Expired',
    used_up: 'Used up',
};

export function couponStatusVariant(status: CouponStatus): 'default' | 'secondary' | 'outline' | 'destructive' {
    switch (status) {
        case 'active':
            return 'default';
        case 'expired':
        case 'used_up':
            return 'destructive';
        case 'scheduled':
            return 'outline';
        default:
            return 'secondary';
    }
}

/** A code made of letters and digits that cannot be mistaken for each other (no 0/O or 1/I/L). */
export function generateCouponCode(length = 8): string {
    const alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    const bytes = new Uint32Array(length);

    crypto.getRandomValues(bytes);

    return Array.from(bytes, (byte) => alphabet[byte % alphabet.length]).join('');
}
