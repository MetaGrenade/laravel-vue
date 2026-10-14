<script setup lang="ts">
import OrderDigitalBadges from '@/components/dashboard/OrderDigitalBadges.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { formatMoney } from '@/lib/money';
import { paymentStatusVariant } from '@/lib/orderStatus';
import type { CustomerOrder } from '@/types/dashboard';
import { Link } from '@inertiajs/vue3';
import { ShoppingBag } from '@lucide/vue';

defineProps<{ orders: CustomerOrder[]; total: number }>();

const placedOn = (value: string) => new Date(value).toLocaleDateString(undefined, { dateStyle: 'medium' });

/** "Pro Licence × 2, Editor and 1 more": enough to recognise an order without listing every line. */
const summary = (order: CustomerOrder) => {
    const shown = order.items
        .slice(0, 2)
        .map((item) => `${item.description || 'Item'}${item.quantity > 1 ? ` × ${item.quantity}` : ''}`)
        .join(', ');
    const more = order.items.length - 2;

    return more > 0 ? `${shown} and ${more} more` : shown;
};
</script>

<template>
    <Card>
        <CardHeader class="flex flex-row items-start justify-between gap-4 space-y-0">
            <div class="space-y-1.5">
                <CardTitle class="flex items-center gap-2"
                    ><ShoppingBag class="size-5 text-muted-foreground" aria-hidden="true" /> Your orders</CardTitle
                >
                <CardDescription>Open an order to see its details, track it, and find your licence keys and downloads.</CardDescription>
            </div>
            <Button v-if="orders.length" variant="outline" size="sm" as-child class="shrink-0">
                <Link :href="route('shop.orders')"
                    >All orders<template v-if="total > orders.length"> ({{ total }})</template></Link
                >
            </Button>
        </CardHeader>
        <CardContent>
            <ul v-if="orders.length" class="space-y-3">
                <li v-for="order in orders" :key="order.id" class="rounded-lg border border-sidebar-border/60 p-3 text-sm dark:border-sidebar-border">
                    <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                        <div class="min-w-0 space-y-1">
                            <Link :href="order.url" class="font-medium text-primary hover:underline">Order {{ order.number }}</Link>
                            <p class="text-xs break-words text-muted-foreground">{{ placedOn(order.created_at) }} · {{ summary(order) }}</p>
                        </div>
                        <div class="text-right">
                            <p class="font-semibold tabular-nums">{{ formatMoney(order.grand_total, order.currency) }}</p>
                            <p v-if="Number(order.refunded_total) > 0" class="text-xs text-muted-foreground">
                                {{ formatMoney(order.refunded_total, order.currency) }} refunded
                            </p>
                        </div>
                    </div>
                    <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <Badge variant="secondary">{{ order.status_label }}</Badge>
                            <Badge :variant="paymentStatusVariant(order.payment_status)">{{ order.payment_status_label }}</Badge>
                            <OrderDigitalBadges :digital="order.digital" />
                        </div>
                        <Button size="sm" variant="outline" as-child>
                            <Link :href="order.url">View order</Link>
                        </Button>
                    </div>
                </li>
            </ul>
            <div v-else class="space-y-3 text-sm text-muted-foreground">
                <p>You haven't placed an order yet. When you do, it appears here with everything you bought.</p>
                <Button size="sm" as-child>
                    <Link :href="route('shop.index')">Browse the shop</Link>
                </Button>
            </div>
        </CardContent>
    </Card>
</template>
