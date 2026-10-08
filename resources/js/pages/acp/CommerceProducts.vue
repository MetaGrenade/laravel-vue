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
import type { BreadcrumbItem } from '@/types';
import type { CatalogueLookup, StockStatus } from '@/types/catalogue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Package, Plus } from '@lucide/vue';
import { computed, reactive, watch } from 'vue';

interface ProductRow {
    id: number;
    name: string;
    slug: string;
    is_active: boolean;
    brand: string | null;
    variants_count: number;
    price: { from: string | null; to: string | null };
    stock: { tracked: boolean; total: number; status: StockStatus };
    sellable: boolean;
}

const props = defineProps<{
    products: { data: ProductRow[]; meta?: PaginationMeta | null };
    filters: { search: string; status: string; brand: number | null };
    brands: CatalogueLookup[];
    currency: string;
    can: { create: boolean };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Commerce', href: route('acp.commerce.index') },
    { title: 'Products', href: route('acp.commerce.products.index') },
];

const rows = computed(() => props.products.data ?? []);

const filterState = reactive({
    search: props.filters.search,
    status: props.filters.status,
    brand: props.filters.brand ?? ('' as number | ''),
});

watch(
    () => props.filters,
    (filters) => {
        filterState.search = filters.search;
        filterState.status = filters.status;
        filterState.brand = filters.brand ?? '';
    },
    { deep: true },
);

const go = (overrides: Record<string, string | number> = {}) => {
    const query = Object.fromEntries(Object.entries({ ...filterState, ...overrides }).filter(([, value]) => String(value).trim() !== ''));

    router.get(route('acp.commerce.products.index'), query, { preserveScroll: true, preserveState: true, replace: true });
};

const apply = () => go({ page: 1 });

const reset = () => {
    filterState.search = '';
    filterState.status = '';
    filterState.brand = '';
    go({ page: 1 });
};

const { meta, page, pageCount, rangeLabel } = useInertiaPagination({
    meta: computed(() => props.products.meta ?? null),
    itemsLength: computed(() => rows.value.length),
    defaultPerPage: 20,
    itemLabel: 'product',
    itemLabelPlural: 'products',
    onNavigate: (newPage) => go({ page: newPage }),
});

const priceLabel = (product: ProductRow) => {
    if (product.price.from === null || product.price.to === null) {
        return null;
    }

    return product.price.from === product.price.to
        ? formatMoney(product.price.from, props.currency)
        : `${formatMoney(product.price.from, props.currency)} – ${formatMoney(product.price.to, props.currency)}`;
};

const stockLabel = (product: ProductRow) => {
    switch (product.stock.status) {
        case 'untracked':
            return 'Not tracked';
        case 'out':
            return 'Out of stock';
        default:
            return `${product.stock.total} in stock`;
    }
};

const selectClass = 'h-10 rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs focus:ring-2 focus:ring-ring focus:outline-hidden';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Products" />

        <AdminLayout>
            <div class="space-y-6 pb-4">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 class="flex items-center gap-2 text-xl font-semibold tracking-tight"><Package class="size-5" /> Products</h2>
                        <p class="mt-1 max-w-2xl text-sm text-muted-foreground">
                            Everything you sell. Open a product to change its details, price, options, variants and stock.
                        </p>
                    </div>
                    <Button v-if="can.create" as-child>
                        <Link :href="route('acp.commerce.products.create')"><Plus class="size-4" /> New product</Link>
                    </Button>
                </div>

                <form class="grid gap-3 rounded-lg border border-border bg-card p-4 shadow-xs md:grid-cols-4 md:items-end" @submit.prevent="apply">
                    <div class="flex flex-col gap-2 md:col-span-2">
                        <label for="product-search" class="text-sm font-medium">Search</label>
                        <Input id="product-search" v-model="filterState.search" type="search" placeholder="Name, web address or SKU" />
                    </div>

                    <div class="flex flex-col gap-2">
                        <label for="product-status" class="text-sm font-medium">Status</label>
                        <select id="product-status" v-model="filterState.status" :class="selectClass">
                            <option value="">All</option>
                            <option value="active">On sale</option>
                            <option value="archived">Archived</option>
                        </select>
                    </div>

                    <div class="flex flex-col gap-2">
                        <label for="product-brand-filter" class="text-sm font-medium">Brand</label>
                        <select id="product-brand-filter" v-model="filterState.brand" :class="selectClass">
                            <option value="">Any</option>
                            <option v-for="brand in brands" :key="brand.id" :value="brand.id">{{ brand.name }}</option>
                        </select>
                    </div>

                    <div class="flex gap-2 md:col-span-4">
                        <Button type="submit">Apply</Button>
                        <Button type="button" variant="outline" @click="reset">Reset</Button>
                    </div>
                </form>

                <Card>
                    <CardContent class="overflow-x-auto pt-6">
                        <Table v-if="rows.length">
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Product</TableHead>
                                    <TableHead>Price</TableHead>
                                    <TableHead>Stock</TableHead>
                                    <TableHead>Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow v-for="product in rows" :key="product.id">
                                    <TableCell>
                                        <Link :href="route('acp.commerce.products.edit', product.id)" class="font-medium hover:underline">{{
                                            product.name
                                        }}</Link>
                                        <p class="text-xs text-muted-foreground">
                                            <template v-if="product.brand">{{ product.brand }} · </template>
                                            <template v-if="product.variants_count"
                                                >{{ product.variants_count }} {{ product.variants_count === 1 ? 'variant' : 'variants' }}</template
                                            >
                                            <template v-else>No variants</template>
                                        </p>
                                    </TableCell>
                                    <TableCell class="whitespace-nowrap tabular-nums">
                                        <template v-if="priceLabel(product)">{{ priceLabel(product) }}</template>
                                        <span v-else class="text-sm text-destructive">No price</span>
                                    </TableCell>
                                    <TableCell class="whitespace-nowrap tabular-nums">
                                        <span :class="product.stock.status === 'untracked' ? 'text-sm text-muted-foreground' : ''">{{
                                            stockLabel(product)
                                        }}</span>
                                        <Badge v-if="product.stock.status === 'out'" variant="destructive" class="ml-1.5">Out</Badge>
                                        <Badge v-else-if="product.stock.status === 'low'" variant="highlight" class="ml-1.5">Low</Badge>
                                    </TableCell>
                                    <TableCell>
                                        <div class="flex flex-wrap gap-1.5">
                                            <Badge :variant="product.is_active ? 'default' : 'secondary'">{{
                                                product.is_active ? 'On sale' : 'Archived'
                                            }}</Badge>
                                            <Badge v-if="product.is_active && !product.sellable" variant="outline">Cannot be bought</Badge>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                        <p v-else class="py-8 text-center text-sm text-muted-foreground">No products match.</p>
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
