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
import { couponStatusLabels, couponStatusVariant, describeCoupon } from '@/lib/coupons';
import { formatMoney } from '@/lib/money';
import type { BreadcrumbItem } from '@/types';
import type { CouponRow } from '@/types/coupons';
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus, TicketPercent } from '@lucide/vue';
import { computed, reactive, watch } from 'vue';

const props = defineProps<{
    coupons: { data: CouponRow[]; meta?: PaginationMeta | null };
    filters: { search: string };
    currency: string;
    can: { create: boolean; edit: boolean; delete: boolean };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Commerce', href: route('acp.commerce.index') },
    { title: 'Discount codes', href: route('acp.commerce.coupons.index') },
];

const rows = computed(() => props.coupons.data ?? []);

const filterState = reactive({ search: props.filters.search });

watch(
    () => props.filters,
    (filters) => {
        filterState.search = filters.search;
    },
    { deep: true },
);

const go = (overrides: Record<string, string | number> = {}) => {
    const query = Object.fromEntries(Object.entries({ ...filterState, ...overrides }).filter(([, value]) => String(value).trim() !== ''));

    router.get(route('acp.commerce.coupons.index'), query, { preserveScroll: true, preserveState: true, replace: true });
};

const { meta, page, pageCount, rangeLabel } = useInertiaPagination({
    meta: computed(() => props.coupons.meta ?? null),
    itemsLength: computed(() => rows.value.length),
    defaultPerPage: 25,
    itemLabel: 'code',
    itemLabelPlural: 'codes',
    onNavigate: (newPage) => go({ page: newPage }),
});

const day = (iso: string) => new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(iso));

const validity = (coupon: CouponRow) => {
    if (coupon.starts_at && coupon.ends_at) {
        return `${day(coupon.starts_at)} – ${day(coupon.ends_at)}`;
    }

    if (coupon.starts_at) {
        return `From ${day(coupon.starts_at)}`;
    }

    if (coupon.ends_at) {
        return `Until ${day(coupon.ends_at)}`;
    }

    return 'Any time';
};

const usesLabel = (coupon: CouponRow) => (coupon.max_redemptions === null ? `${coupon.uses}` : `${coupon.uses} / ${coupon.max_redemptions}`);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Discount codes" />

        <AdminLayout>
            <div class="space-y-6 pb-4">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 class="flex items-center gap-2 text-xl font-semibold tracking-tight"><TicketPercent class="size-5" /> Discount codes</h2>
                        <p class="mt-1 max-w-2xl text-sm text-muted-foreground">
                            Codes customers type at checkout: a percentage or an amount off, or free shipping. One code can be used per order.
                        </p>
                    </div>
                    <Button v-if="can.create" as-child>
                        <Link :href="route('acp.commerce.coupons.create')"><Plus class="size-4" /> New code</Link>
                    </Button>
                </div>

                <form class="flex flex-wrap items-end gap-3 rounded-lg border border-border bg-card p-4 shadow-xs" @submit.prevent="go({ page: 1 })">
                    <div class="flex min-w-60 flex-1 flex-col gap-2">
                        <label for="coupon-search" class="text-sm font-medium">Search</label>
                        <Input id="coupon-search" v-model="filterState.search" type="search" placeholder="Code or note" />
                    </div>
                    <Button type="submit">Search</Button>
                    <Button
                        v-if="filterState.search"
                        type="button"
                        variant="outline"
                        @click="
                            filterState.search = '';
                            go({ page: 1 });
                        "
                        >Clear</Button
                    >
                </form>

                <Card>
                    <CardContent class="overflow-x-auto pt-6">
                        <Table v-if="rows.length">
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Code</TableHead>
                                    <TableHead>Discount</TableHead>
                                    <TableHead>Conditions</TableHead>
                                    <TableHead>Used</TableHead>
                                    <TableHead>Valid</TableHead>
                                    <TableHead>Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow v-for="coupon in rows" :key="coupon.id">
                                    <TableCell>
                                        <Link :href="route('acp.commerce.coupons.edit', coupon.id)" class="font-mono font-medium hover:underline">{{
                                            coupon.code
                                        }}</Link>
                                        <p v-if="coupon.description" class="max-w-56 truncate text-xs text-muted-foreground">
                                            {{ coupon.description }}
                                        </p>
                                    </TableCell>
                                    <TableCell class="whitespace-nowrap">
                                        {{ describeCoupon(coupon.type, coupon.value, coupon.currency) }}
                                        <Badge v-if="coupon.currency_mismatch" variant="destructive" class="ml-1.5">Not in {{ currency }}</Badge>
                                    </TableCell>
                                    <TableCell class="text-sm text-muted-foreground">
                                        <p v-if="coupon.minimum_subtotal">Spend {{ formatMoney(coupon.minimum_subtotal, currency) }}+</p>
                                        <p v-if="coupon.restricted">Some products only</p>
                                        <p v-if="coupon.max_redemptions_per_customer">{{ coupon.max_redemptions_per_customer }} per customer</p>
                                        <p v-if="!coupon.minimum_subtotal && !coupon.restricted && !coupon.max_redemptions_per_customer">None</p>
                                    </TableCell>
                                    <TableCell class="whitespace-nowrap tabular-nums">{{ usesLabel(coupon) }}</TableCell>
                                    <TableCell class="text-sm whitespace-nowrap text-muted-foreground">{{ validity(coupon) }}</TableCell>
                                    <TableCell>
                                        <Badge :variant="couponStatusVariant(coupon.status)">{{ couponStatusLabels[coupon.status] }}</Badge>
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                        <p v-else-if="filterState.search" class="py-8 text-center text-sm text-muted-foreground">No codes match.</p>
                        <p v-else class="py-8 text-center text-sm text-muted-foreground">No discount codes yet.</p>
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
