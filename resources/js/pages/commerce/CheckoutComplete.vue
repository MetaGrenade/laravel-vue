<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatMoney } from '@/lib/money';
import { Head, Link, router } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck, Clock } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted } from 'vue';

interface OrderItem {
    id: number;
    description: string | null;
    quantity: number;
    unit_price: string;
    subtotal: string;
}

interface Order {
    number: string;
    status: 'pending' | 'processing' | 'completed' | 'cancelled';
    status_label: string;
    payment_status: 'unpaid' | 'paid' | 'failed';
    payment_status_label: string;
    currency: string;
    subtotal: string;
    tax_total: string;
    shipping_total: string;
    discount_total: string;
    grand_total: string;
    customer_email: string | null;
    items: OrderItem[];
}

const props = defineProps<{ order: Order }>();

const state = computed<'paid' | 'waiting' | 'closed'>(() => {
    if (props.order.payment_status === 'paid') {
        return 'paid';
    }

    return props.order.status === 'pending' ? 'waiting' : 'closed';
});

// The payment provider tells us a moment after the customer returns. Check a
// handful of times, slowing down as we go (about seven checks over a little more
// than a minute, well inside the status page's own rate limit), then stop: the
// receipt email carries on from there. Each check waits for the last to finish.
const CHECK_DELAYS_MS = [3000, 5000, 8000, 12000, 15000, 15000, 15000];

let timer: ReturnType<typeof setTimeout> | undefined;
let stopped = false;

const scheduleCheck = (attempt: number) => {
    if (stopped || attempt >= CHECK_DELAYS_MS.length || state.value !== 'waiting') {
        return;
    }

    timer = setTimeout(() => {
        router.reload({
            only: ['order'],
            onFinish: () => scheduleCheck(attempt + 1),
        });
    }, CHECK_DELAYS_MS[attempt]);
};

onMounted(() => scheduleCheck(0));

onBeforeUnmount(() => {
    stopped = true;
    clearTimeout(timer);
});
</script>

<template>
    <AppLayout>
        <Head :title="`Order ${props.order.number}`" />

        <div class="mx-auto max-w-2xl space-y-6">
            <Card>
                <CardContent class="flex items-start gap-4 pt-6">
                    <CircleCheck v-if="state === 'paid'" class="mt-1 size-8 shrink-0 text-success" />
                    <Clock v-else-if="state === 'waiting'" class="mt-1 size-8 shrink-0 text-muted-foreground" />
                    <CircleAlert v-else class="mt-1 size-8 shrink-0 text-destructive" />

                    <div class="space-y-1">
                        <h1 class="text-2xl font-bold tracking-tight">
                            <template v-if="state === 'paid'">Thank you for your order</template>
                            <template v-else-if="state === 'waiting'">Confirming your payment…</template>
                            <template v-else>This order was not completed</template>
                        </h1>
                        <p class="text-muted-foreground">
                            <template v-if="state === 'paid'">
                                Order {{ props.order.number }} is confirmed<template v-if="props.order.customer_email"
                                    >. A receipt is on its way to {{ props.order.customer_email }}</template
                                >.
                            </template>
                            <template v-else-if="state === 'waiting'">
                                We're waiting for the payment provider to confirm order {{ props.order.number }}. This usually takes a few seconds;
                                you can leave this page open.
                            </template>
                            <template v-else>
                                Order {{ props.order.number }} was cancelled and you haven't been charged. Your cart is still waiting if you'd like to
                                try again.
                            </template>
                        </p>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <CardTitle>Order {{ props.order.number }}</CardTitle>
                        <div class="flex gap-2">
                            <Badge variant="secondary">{{ props.order.status_label }}</Badge>
                            <Badge :variant="state === 'paid' ? 'default' : 'outline'">{{ props.order.payment_status_label }}</Badge>
                        </div>
                    </div>
                </CardHeader>
                <CardContent class="space-y-4">
                    <ul class="space-y-3 text-sm">
                        <li v-for="item in props.order.items" :key="item.id" class="flex justify-between gap-3">
                            <span>
                                {{ item.description || 'Item' }}
                                <span class="text-muted-foreground">× {{ item.quantity }}</span>
                            </span>
                            <span class="tabular-nums">{{ formatMoney(item.subtotal, props.order.currency) }}</span>
                        </li>
                    </ul>
                    <Separator />
                    <dl class="space-y-1 text-sm">
                        <div v-if="Number(props.order.shipping_total) > 0" class="flex justify-between">
                            <dt class="text-muted-foreground">Shipping</dt>
                            <dd class="tabular-nums">{{ formatMoney(props.order.shipping_total, props.order.currency) }}</dd>
                        </div>
                        <div v-if="Number(props.order.tax_total) > 0" class="flex justify-between">
                            <dt class="text-muted-foreground">Tax</dt>
                            <dd class="tabular-nums">{{ formatMoney(props.order.tax_total, props.order.currency) }}</dd>
                        </div>
                        <div v-if="Number(props.order.discount_total) > 0" class="flex justify-between">
                            <dt class="text-muted-foreground">Discount</dt>
                            <dd class="tabular-nums">−{{ formatMoney(props.order.discount_total, props.order.currency) }}</dd>
                        </div>
                        <div class="flex justify-between text-base font-semibold">
                            <dt>Total</dt>
                            <dd class="tabular-nums">{{ formatMoney(props.order.grand_total, props.order.currency) }}</dd>
                        </div>
                    </dl>
                </CardContent>
            </Card>

            <div class="flex justify-center gap-3">
                <Button variant="outline" as-child>
                    <Link :href="route('shop.index')">Continue shopping</Link>
                </Button>
                <Button v-if="state === 'closed'" as-child>
                    <Link :href="route('shop.cart')">Back to your cart</Link>
                </Button>
            </div>
        </div>
    </AppLayout>
</template>
