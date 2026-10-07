<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatMoney } from '@/lib/money';
import { Head, Link } from '@inertiajs/vue3';

interface OrderItem {
    id: number;
    description?: string | null;
    quantity: number;
    subtotal: string;
}

interface Order {
    id: number;
    number: string;
    status: string;
    status_label: string;
    payment_status: string;
    payment_status_label: string;
    currency: string;
    grand_total: string;
    created_at: string;
    url: string;
    items: OrderItem[];
}

interface Props {
    orders: {
        data: Order[];
    };
}

const props = defineProps<Props>();

const placedOn = (value: string) => new Date(value).toLocaleDateString(undefined, { dateStyle: 'medium' });
</script>

<template>
    <AppLayout>
        <Head title="Orders" />

        <div class="space-y-6">
            <div>
                <p class="text-sm text-muted-foreground uppercase">Orders</p>
                <h1 class="text-3xl font-bold tracking-tight">Order history</h1>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Recent orders</CardTitle>
                </CardHeader>
                <CardContent>
                    <div v-if="props.orders.data.length" class="space-y-4">
                        <div v-for="order in props.orders.data" :key="order.id" class="rounded border p-4">
                            <div class="flex flex-wrap items-center justify-between gap-4">
                                <div>
                                    <div class="text-lg font-semibold">Order {{ order.number }}</div>
                                    <p class="text-sm text-muted-foreground">Placed {{ placedOn(order.created_at) }}</p>
                                </div>
                                <div class="flex gap-2">
                                    <Badge variant="secondary">{{ order.status_label }}</Badge>
                                    <Badge :variant="order.payment_status === 'paid' ? 'default' : 'outline'">{{ order.payment_status_label }}</Badge>
                                </div>
                            </div>

                            <Separator class="my-3" />

                            <div class="space-y-2">
                                <div v-for="item in order.items" :key="item.id" class="flex justify-between text-sm">
                                    <span>{{ item.description || 'Line item' }} (x{{ item.quantity }})</span>
                                    <span class="tabular-nums">{{ formatMoney(item.subtotal, order.currency) }}</span>
                                </div>
                            </div>

                            <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <span class="text-sm text-muted-foreground">Total </span>
                                    <span class="text-xl font-bold tabular-nums">{{ formatMoney(order.grand_total, order.currency) }}</span>
                                </div>
                                <Button variant="outline" size="sm" as-child>
                                    <Link :href="order.url">View order</Link>
                                </Button>
                            </div>
                        </div>
                    </div>
                    <p v-else class="text-sm text-muted-foreground">You haven't placed any orders yet.</p>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
