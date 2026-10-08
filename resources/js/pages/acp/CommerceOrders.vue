<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Pagination,
    PaginationEllipsis,
    PaginationFirst,
    PaginationLast,
    PaginationList,
    PaginationListItem,
    PaginationNext,
    PaginationPrev,
} from '@/components/ui/pagination';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useInertiaPagination, type PaginationMeta } from '@/composables/useInertiaPagination';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatMoney } from '@/lib/money';
import { formatDateTime, orderStatusVariant, paymentStatusVariant } from '@/lib/orderStatus';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ReceiptText } from '@lucide/vue';
import { computed, reactive, watch } from 'vue';

interface OrderRow {
    public_id: string;
    number: string;
    status: string;
    status_label: string;
    payment_status: string;
    payment_status_label: string;
    currency: string;
    grand_total: string;
    refunded_total: string;
    customer_name: string | null;
    customer_email: string | null;
    items_count: number;
    placed_at: string | null;
}

interface Option {
    value: string;
    label: string;
}

const props = defineProps<{
    orders: { data: OrderRow[]; meta?: PaginationMeta | null };
    filters: { search: string; status: string; payment_status: string };
    statuses: Option[];
    paymentStatuses: Option[];
    counts: Record<string, number>;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Commerce', href: route('acp.commerce.index') },
    { title: 'Orders', href: route('acp.commerce.orders.index') },
];

const rows = computed(() => props.orders.data ?? []);

const filterState = reactive({
    search: props.filters.search,
    status: props.filters.status,
    payment_status: props.filters.payment_status,
});

watch(
    () => props.filters,
    (filters) => {
        filterState.search = filters.search;
        filterState.status = filters.status;
        filterState.payment_status = filters.payment_status;
    },
    { deep: true },
);

const query = (overrides: Record<string, string | number> = {}) =>
    Object.fromEntries(Object.entries({ ...filterState, ...overrides }).filter(([, value]) => String(value).trim() !== ''));

const go = (overrides: Record<string, string | number> = {}) => {
    router.get(route('acp.commerce.orders.index'), query(overrides), { preserveScroll: true, preserveState: true, replace: true });
};

const applyFilters = () => go({ page: 1 });

const showStatus = (status: string) => {
    filterState.status = status;
    go({ page: 1 });
};

const reset = () => {
    filterState.search = '';
    filterState.status = '';
    filterState.payment_status = '';
    go({ page: 1 });
};

const { meta, page, pageCount, rangeLabel } = useInertiaPagination({
    meta: computed(() => props.orders.meta ?? null),
    itemsLength: computed(() => rows.value.length),
    defaultPerPage: 20,
    itemLabel: 'order',
    itemLabelPlural: 'orders',
    onNavigate: (newPage) => go({ page: newPage }),
});

const selectClass = 'h-10 rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs focus:ring-2 focus:ring-ring focus:outline-hidden';

const customer = (order: OrderRow) => order.customer_name || order.customer_email || 'Guest';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Orders" />

        <AdminLayout>
            <div class="space-y-6 pb-4">
                <div>
                    <h2 class="flex items-center gap-2 text-xl font-semibold tracking-tight"><ReceiptText class="size-5" /> Orders</h2>
                    <p class="mt-1 max-w-2xl text-sm text-muted-foreground">
                        Every order placed in the shop. Open one to ship it, refund it or leave a note.
                    </p>
                </div>

                <div class="flex flex-wrap gap-2" role="group" aria-label="Filter by status">
                    <Button :variant="filterState.status === '' ? 'default' : 'outline'" size="sm" @click="showStatus('')">All</Button>
                    <Button
                        v-for="status in props.statuses"
                        :key="status.value"
                        :variant="filterState.status === status.value ? 'default' : 'outline'"
                        size="sm"
                        @click="showStatus(status.value)"
                    >
                        {{ status.label }}
                        <span class="tabular-nums opacity-70">{{ props.counts[status.value] ?? 0 }}</span>
                    </Button>
                </div>

                <form
                    class="grid gap-3 rounded-lg border border-border bg-card p-4 shadow-xs md:grid-cols-3 md:items-end"
                    @submit.prevent="applyFilters"
                >
                    <div class="flex flex-col gap-2">
                        <label for="order-search" class="text-sm font-medium">Search</label>
                        <Input id="order-search" v-model="filterState.search" type="search" placeholder="Order number, name or email" />
                    </div>

                    <div class="flex flex-col gap-2">
                        <label for="order-payment" class="text-sm font-medium">Payment</label>
                        <select id="order-payment" v-model="filterState.payment_status" :class="selectClass">
                            <option value="">Any</option>
                            <option v-for="status in props.paymentStatuses" :key="status.value" :value="status.value">{{ status.label }}</option>
                        </select>
                    </div>

                    <div class="flex gap-2">
                        <Button type="submit">Apply</Button>
                        <Button type="button" variant="outline" @click="reset">Reset</Button>
                    </div>
                </form>

                <Card>
                    <CardContent class="overflow-x-auto pt-6">
                        <Table v-if="rows.length">
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Order</TableHead>
                                    <TableHead>Customer</TableHead>
                                    <TableHead>Placed</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Payment</TableHead>
                                    <TableHead class="text-right">Total</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow v-for="order in rows" :key="order.public_id">
                                    <TableCell>
                                        <Link :href="route('acp.commerce.orders.show', order.public_id)" class="font-medium hover:underline">{{
                                            order.number
                                        }}</Link>
                                        <p class="text-xs text-muted-foreground">
                                            {{ order.items_count }} {{ order.items_count === 1 ? 'item' : 'items' }}
                                        </p>
                                    </TableCell>
                                    <TableCell>
                                        <span class="text-sm">{{ customer(order) }}</span>
                                        <p v-if="order.customer_name && order.customer_email" class="text-xs text-muted-foreground">
                                            {{ order.customer_email }}
                                        </p>
                                    </TableCell>
                                    <TableCell class="text-sm whitespace-nowrap">{{ formatDateTime(order.placed_at) }}</TableCell>
                                    <TableCell>
                                        <Badge :variant="orderStatusVariant(order.status)">{{ order.status_label }}</Badge>
                                    </TableCell>
                                    <TableCell>
                                        <Badge :variant="paymentStatusVariant(order.payment_status)">{{ order.payment_status_label }}</Badge>
                                    </TableCell>
                                    <TableCell class="text-right tabular-nums">
                                        {{ formatMoney(order.grand_total, order.currency) }}
                                        <p v-if="Number(order.refunded_total) > 0" class="text-xs text-muted-foreground">
                                            {{ formatMoney(order.refunded_total, order.currency) }} refunded
                                        </p>
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                        <p v-else class="py-8 text-center text-sm text-muted-foreground">No orders match.</p>
                    </CardContent>
                </Card>

                <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <p class="text-sm text-muted-foreground">{{ rangeLabel }}</p>
                    <Pagination
                        v-if="pageCount > 1"
                        v-slot="{ page: currentPage }"
                        v-model:page="page"
                        :items-per-page="Math.max(meta.per_page, 1)"
                        :total="meta.total"
                        :sibling-count="1"
                        show-edges
                    >
                        <PaginationList v-slot="{ items }" class="flex flex-wrap items-center justify-center gap-1">
                            <PaginationFirst />
                            <PaginationPrev />

                            <template v-for="(item, index) in items" :key="index">
                                <PaginationListItem v-if="item.type === 'page'" :value="item.value" as-child>
                                    <Button class="h-9 w-9 p-0" :variant="item.value === currentPage ? 'default' : 'outline'">
                                        {{ item.value }}
                                    </Button>
                                </PaginationListItem>
                                <PaginationEllipsis v-else :index="index" />
                            </template>

                            <PaginationNext />
                            <PaginationLast />
                        </PaginationList>
                    </Pagination>
                </div>
            </div>
        </AdminLayout>
    </AppLayout>
</template>
