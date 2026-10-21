<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { formatMoney } from '@/lib/money';
import type { RecurringPayment } from '@/types/commerce';
import { Link } from '@inertiajs/vue3';

withDefaults(
    defineProps<{
        payments: RecurringPayment[];
        /** Say which order each came from (not needed on the order's own page). */
        showOrder?: boolean;
        /** Staff see Tebex's reference, its own status and what it said about a cancellation. */
        staff?: boolean;
    }>(),
    { showOrder: true, staff: false },
);

const day = (value: string | null) => (value ? new Date(value).toLocaleDateString(undefined, { dateStyle: 'medium' }) : null);

const variant = (state: RecurringPayment['state']) => {
    switch (state) {
        case 'active':
            return 'secondary';
        case 'overdue':
            return 'destructive';
        default:
            return 'outline';
    }
};

/** What happens next, or what happened last. */
const next = (payment: RecurringPayment) => {
    if (payment.state === 'ended') {
        return payment.ended_at ? `Ended ${day(payment.ended_at)}` : null;
    }

    if (payment.state === 'cancelling') {
        return payment.next_payment_at ? `It will not renew: it runs until ${day(payment.next_payment_at)}.` : 'It will not renew.';
    }

    if (payment.state === 'overdue') {
        return 'The last renewal could not be taken. Tebex will try again.';
    }

    return payment.next_payment_at ? `Next payment ${day(payment.next_payment_at)}` : null;
};
</script>

<template>
    <ul class="divide-y">
        <li v-for="payment in payments" :key="payment.id" class="space-y-1 py-3 first:pt-0 last:pb-0">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div class="min-w-0">
                    <Link v-if="payment.package_url" :href="payment.package_url" class="font-medium hover:underline">{{ payment.package }}</Link>
                    <span v-else class="font-medium">{{ payment.package }}</span>
                    <p class="text-sm text-muted-foreground">
                        <template v-if="payment.amount && payment.currency">{{ formatMoney(payment.amount, payment.currency) }} each time</template>
                        <template v-if="payment.renewals > 0">
                            · renewed {{ payment.renewals }} {{ payment.renewals === 1 ? 'time' : 'times' }}
                        </template>
                        <template v-if="showOrder && payment.order_number && payment.order_url">
                            · from <Link :href="payment.order_url" class="underline">order {{ payment.order_number }}</Link>
                        </template>
                    </p>
                </div>
                <Badge :variant="variant(payment.state)">{{ payment.state_label }}</Badge>
            </div>
            <p v-if="next(payment)" class="text-sm text-muted-foreground">{{ next(payment) }}</p>
            <p v-if="staff" class="text-xs text-muted-foreground">
                <span class="font-mono">{{ payment.reference }}</span>
                <template v-if="payment.provider_status"> · Tebex says {{ payment.provider_status }}</template>
                <template v-if="payment.fail_count"> · {{ payment.fail_count }} failed {{ payment.fail_count === 1 ? 'try' : 'tries' }}</template>
                <template v-if="payment.cancel_reason"> · {{ payment.cancel_reason }}</template>
            </p>
        </li>
    </ul>
</template>
