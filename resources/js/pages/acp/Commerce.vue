<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatMoney } from '@/lib/money';
import { orderStatusVariant, paymentStatusVariant } from '@/lib/orderStatus';
import type { BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { Package, Percent, Plus, ReceiptText, Tags, TicketPercent, Truck } from '@lucide/vue';

interface RecentOrder {
    id: number;
    public_id: string;
    number: string;
    status: string;
    payment_status: string;
    currency: string;
    grand_total: string;
    user?: { id: number; nickname: string; email: string } | null;
}

interface LowStockItem {
    id: number;
    product_id: number;
    product: string | null;
    variant: string | null;
    quantity: number;
}

defineProps<{
    orders: RecentOrder[];
    metrics: {
        products: { total: number; active: number; options: number; variants: number };
        pricing: { active_prices: number; total_prices: number };
        inventory: { items: number; on_hand: number; backorderable: number; out_of_stock: number };
        orders: { total: number; processing: number; completed: number; cancelled: number; revenue: number };
    };
    orderStatusBreakdown: Record<string, number>;
    lowStock: LowStockItem[];
    lowStockThreshold: number;
    currency: string;
    can: { create: boolean };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Commerce', href: route('acp.commerce.index') }];

const statusLabels: Record<string, string> = {
    pending: 'Awaiting payment',
    processing: 'Processing',
    completed: 'Completed',
    cancelled: 'Cancelled',
};

const formatStatus = (status: string) => statusLabels[status] ?? status;
</script>

<template>
    <Head title="Commerce ACP" />

    <AppLayout :breadcrumbs="breadcrumbs" title="Commerce" description="Manage products, pricing, and orders." sticky>
        <AdminLayout>
            <div class="w-full space-y-6">
                <div class="flex flex-wrap gap-2">
                    <Button v-if="can.create" size="sm" as-child>
                        <Link :href="route('acp.commerce.products.create')"><Plus class="size-4" /> New product</Link>
                    </Button>
                    <Button variant="outline" size="sm" as-child>
                        <Link :href="route('acp.commerce.products.index')"><Package class="size-4" /> Products</Link>
                    </Button>
                    <Button variant="outline" size="sm" as-child>
                        <Link :href="route('acp.commerce.orders.index')"><ReceiptText class="size-4" /> Orders</Link>
                    </Button>
                    <Button variant="outline" size="sm" as-child>
                        <Link :href="route('acp.commerce.taxonomy.index')"><Tags class="size-4" /> Brands, categories and tags</Link>
                    </Button>
                    <Button variant="outline" size="sm" as-child>
                        <Link :href="route('acp.commerce.shipping.index')"><Truck class="size-4" /> Shipping zones and rates</Link>
                    </Button>
                    <Button variant="outline" size="sm" as-child>
                        <Link :href="route('acp.commerce.tax-rates.index')"><Percent class="size-4" /> Tax rates</Link>
                    </Button>
                    <Button variant="outline" size="sm" as-child>
                        <Link :href="route('acp.commerce.coupons.index')"><TicketPercent class="size-4" /> Discount codes</Link>
                    </Button>
                </div>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <Card>
                        <CardHeader>
                            <CardDescription>Products</CardDescription>
                            <CardTitle class="text-3xl">{{ metrics.products.total }}</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p class="text-sm text-muted-foreground">
                                {{ metrics.products.active }} on sale • {{ metrics.products.options }} options •
                                {{ metrics.products.variants }} variants
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardDescription>Pricing</CardDescription>
                            <CardTitle class="text-3xl">{{ metrics.pricing.total_prices }}</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p class="text-sm text-muted-foreground">{{ metrics.pricing.active_prices }} in use</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardDescription>Stock</CardDescription>
                            <CardTitle class="text-3xl">{{ metrics.inventory.on_hand }}</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p class="text-sm text-muted-foreground">
                                units for sale across {{ metrics.inventory.items }} tracked items • {{ metrics.inventory.out_of_stock }} out of stock
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardDescription>Orders</CardDescription>
                            <CardTitle class="text-3xl">{{ metrics.orders.total }}</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p class="text-sm text-muted-foreground">
                                {{ metrics.orders.processing }} processing • {{ metrics.orders.completed }} completed •
                                {{ formatMoney(metrics.orders.revenue, currency) }} revenue
                            </p>
                        </CardContent>
                    </Card>
                </div>

                <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Recent orders</CardTitle>
                            <CardDescription>The latest ten.</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Table v-if="orders.length">
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Order</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Customer</TableHead>
                                        <TableHead class="text-right">Total</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    <TableRow v-for="order in orders" :key="order.id">
                                        <TableCell>
                                            <Link :href="route('acp.commerce.orders.show', order.public_id)" class="font-medium hover:underline">{{
                                                order.number
                                            }}</Link>
                                        </TableCell>
                                        <TableCell>
                                            <div class="flex flex-wrap gap-1">
                                                <Badge :variant="orderStatusVariant(order.status)">{{ formatStatus(order.status) }}</Badge>
                                                <Badge
                                                    v-if="['partially_refunded', 'refunded'].includes(order.payment_status)"
                                                    :variant="paymentStatusVariant(order.payment_status)"
                                                >
                                                    {{ order.payment_status === 'refunded' ? 'Refunded' : 'Part refunded' }}
                                                </Badge>
                                            </div>
                                        </TableCell>
                                        <TableCell>{{ order.user?.nickname ?? 'Guest' }}</TableCell>
                                        <TableCell class="text-right tabular-nums">{{ formatMoney(order.grand_total, order.currency) }}</TableCell>
                                    </TableRow>
                                </TableBody>
                            </Table>
                            <p v-else class="text-sm text-muted-foreground">No orders yet.</p>
                        </CardContent>
                    </Card>

                    <div class="space-y-6">
                        <Card>
                            <CardHeader>
                                <CardTitle>Running low</CardTitle>
                                <CardDescription
                                    >Tracked stock with {{ lowStockThreshold }} or fewer left that cannot be backordered.</CardDescription
                                >
                            </CardHeader>
                            <CardContent>
                                <ul v-if="lowStock.length" class="divide-y text-sm">
                                    <li v-for="item in lowStock" :key="item.id" class="flex items-center justify-between gap-3 py-2">
                                        <Link
                                            :href="route('acp.commerce.products.edit', item.product_id)"
                                            class="min-w-0 truncate font-medium hover:underline"
                                        >
                                            {{ item.product
                                            }}<span v-if="item.variant" class="font-normal text-muted-foreground"> — {{ item.variant }}</span>
                                        </Link>
                                        <Badge :variant="item.quantity <= 0 ? 'destructive' : 'highlight'">{{
                                            item.quantity <= 0 ? 'Out' : `${item.quantity} left`
                                        }}</Badge>
                                    </li>
                                </ul>
                                <p v-else class="text-sm text-muted-foreground">Nothing is running low.</p>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>Status breakdown</CardTitle>
                                <CardDescription>Distribution of orders by state.</CardDescription>
                            </CardHeader>
                            <CardContent>
                                <ul class="space-y-2 text-sm text-muted-foreground">
                                    <li v-for="(count, status) in orderStatusBreakdown" :key="status" class="flex items-center justify-between">
                                        <span class="font-medium text-foreground">{{ formatStatus(String(status)) }}</span>
                                        <span>{{ count }} orders</span>
                                    </li>
                                </ul>
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </div>
        </AdminLayout>
    </AppLayout>
</template>
