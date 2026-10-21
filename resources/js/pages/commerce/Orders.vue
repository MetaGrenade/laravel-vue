<script setup lang="ts">
import RecurringPayments from '@/components/commerce/RecurringPayments.vue';
import OrderDigitalBadges from '@/components/dashboard/OrderDigitalBadges.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatMoney } from '@/lib/money';
import { paymentStatusVariant } from '@/lib/orderStatus';
import type { RecurringPayment } from '@/types/commerce';
import type { CustomerOrder } from '@/types/dashboard';
import type { HeldEntitlement } from '@/types/entitlements';
import { Head, Link } from '@inertiajs/vue3';

interface Props {
    orders: {
        data: CustomerOrder[];
        current_page: number;
        last_page: number;
        total: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    /** Their recurring payments (Tebex's recurring packages), newest first. */
    subscriptions: RecurringPayment[];
    /** What their purchases give their account: features switched on and limits raised. */
    entitlements: HeldEntitlement[];
}

const props = defineProps<Props>();

const placedOn = (value: string) => new Date(value).toLocaleDateString(undefined, { dateStyle: 'medium' });

/** Something that was paid for (and not given back in full) can be reviewed. */
const canReview = (order: CustomerOrder) => ['paid', 'partially_refunded'].includes(order.payment_status) && order.status !== 'cancelled';
</script>

<template>
    <AppLayout>
        <Head title="Orders" />

        <div class="space-y-6">
            <div>
                <p class="text-sm text-muted-foreground uppercase">Orders</p>
                <h1 class="text-3xl font-bold tracking-tight">Order history</h1>
            </div>

            <Card v-if="props.subscriptions.length">
                <CardHeader>
                    <CardTitle>Your recurring payments</CardTitle>
                    <CardDescription> Tebex takes these every period until they end. </CardDescription>
                </CardHeader>
                <CardContent>
                    <RecurringPayments :payments="props.subscriptions" />
                </CardContent>
            </Card>

            <Card v-if="props.entitlements.length">
                <CardHeader>
                    <CardTitle>What your plan and purchases give you</CardTitle>
                    <CardDescription>
                        Your plan gives them while your subscription is live. A purchase adds them to your account when the order is paid, and takes
                        them back if it is refunded or a recurring payment ends.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <ul class="divide-y">
                        <li
                            v-for="entitlement in props.entitlements"
                            :key="entitlement.key"
                            class="flex items-start justify-between gap-3 py-2.5 first:pt-0 last:pb-0"
                        >
                            <div class="min-w-0">
                                <p class="font-medium">{{ entitlement.label }}</p>
                                <p v-if="entitlement.description" class="text-sm text-muted-foreground">{{ entitlement.description }}</p>
                            </div>
                            <span v-if="entitlement.type === 'limit'" class="shrink-0 font-medium tabular-nums">{{ entitlement.value }}</span>
                            <span v-else class="shrink-0 text-sm text-muted-foreground">On</span>
                        </li>
                    </ul>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Your orders</CardTitle>
                    <CardDescription> Open an order to see its details and tracking, and to find your licence keys and downloads. </CardDescription>
                </CardHeader>
                <CardContent>
                    <div v-if="props.orders.data.length" class="space-y-4">
                        <div v-for="order in props.orders.data" :key="order.id" class="rounded border p-4">
                            <div class="flex flex-wrap items-center justify-between gap-4">
                                <div>
                                    <Link :href="order.url" class="text-lg font-semibold text-primary hover:underline">Order {{ order.number }}</Link>
                                    <p class="text-sm text-muted-foreground">Placed {{ placedOn(order.created_at) }}</p>
                                </div>
                                <div class="flex gap-2">
                                    <Badge variant="secondary">{{ order.status_label }}</Badge>
                                    <Badge :variant="paymentStatusVariant(order.payment_status)">{{ order.payment_status_label }}</Badge>
                                </div>
                            </div>

                            <OrderDigitalBadges :digital="order.digital" class="mt-3" />

                            <Separator class="my-3" />

                            <div class="space-y-2">
                                <div v-for="item in order.items" :key="item.id" class="flex justify-between gap-3 text-sm">
                                    <span>
                                        {{ item.description || 'Line item' }} (x{{ item.quantity }})
                                        <a
                                            v-if="item.product_url && canReview(order)"
                                            :href="`${item.product_url}#reviews`"
                                            class="ml-1 text-primary hover:underline"
                                            >Write a review</a
                                        >
                                    </span>
                                    <span class="tabular-nums">{{ formatMoney(item.subtotal, order.currency) }}</span>
                                </div>
                            </div>

                            <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <span class="text-sm text-muted-foreground">Total </span>
                                    <span class="text-xl font-bold tabular-nums">{{ formatMoney(order.grand_total, order.currency) }}</span>
                                    <p v-if="Number(order.refunded_total) > 0" class="text-sm text-muted-foreground">
                                        {{ formatMoney(order.refunded_total, order.currency) }} refunded
                                    </p>
                                </div>
                                <Button variant="outline" size="sm" as-child>
                                    <Link :href="order.url">View order</Link>
                                </Button>
                            </div>
                        </div>

                        <nav v-if="props.orders.last_page > 1" class="flex items-center justify-between gap-3 pt-2" aria-label="Order pages">
                            <Button v-if="props.orders.prev_page_url" variant="outline" size="sm" as-child>
                                <Link :href="props.orders.prev_page_url" preserve-scroll>Newer orders</Link>
                            </Button>
                            <span v-else />
                            <span class="text-sm text-muted-foreground">Page {{ props.orders.current_page }} of {{ props.orders.last_page }}</span>
                            <Button v-if="props.orders.next_page_url" variant="outline" size="sm" as-child>
                                <Link :href="props.orders.next_page_url" preserve-scroll>Older orders</Link>
                            </Button>
                            <span v-else />
                        </nav>
                    </div>
                    <div v-else class="space-y-3 text-sm text-muted-foreground">
                        <p>You haven't placed any orders yet.</p>
                        <Button size="sm" as-child>
                            <Link :href="route('shop.index')">Browse the shop</Link>
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
