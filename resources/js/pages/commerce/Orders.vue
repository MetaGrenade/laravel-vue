<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';

interface OrderItem {
    id: number;
    description?: string | null;
    quantity: number;
    subtotal: string;
}

interface Order {
    id: number;
    status: string;
    currency: string;
    grand_total: string;
    created_at: string;
    items: OrderItem[];
}

interface Props {
    orders: {
        data: Order[];
    };
}

const props = defineProps<Props>();
</script>

<template>
    <AppLayout>
        <Head title="Orders" />

        <div class="space-y-6">
            <div>
                <p class="text-sm text-muted-foreground uppercase">Orders</p>
                <h1 class="text-3xl font-bold tracking-tight">Order history</h1>
                <p class="text-muted-foreground">Surface orders captured by checkout once flows are wired up.</p>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Recent orders</CardTitle>
                </CardHeader>
                <CardContent>
                    <div v-if="props.orders.data.length" class="space-y-4">
                        <div v-for="order in props.orders.data" :key="order.id" class="rounded border p-4">
                            <div class="flex items-center justify-between gap-4">
                                <div>
                                    <div class="text-lg font-semibold">Order #{{ order.id }}</div>
                                    <p class="text-sm text-muted-foreground">Placed {{ order.created_at }}</p>
                                </div>
                                <Badge variant="secondary">{{ order.status }}</Badge>
                            </div>

                            <Separator class="my-3" />

                            <div class="space-y-2">
                                <div v-for="item in order.items" :key="item.id" class="flex justify-between text-sm">
                                    <span>{{ item.description || 'Line item' }} (x{{ item.quantity }})</span>
                                    <span>{{ item.subtotal }} {{ order.currency }}</span>
                                </div>
                            </div>

                            <div class="mt-4 flex items-center justify-between">
                                <span class="text-sm text-muted-foreground">Total</span>
                                <span class="text-xl font-bold">{{ order.grand_total }} {{ order.currency }}</span>
                            </div>
                        </div>
                    </div>
                    <p v-else class="text-sm text-muted-foreground">No orders yet. Hook up checkout to start capturing purchases.</p>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
