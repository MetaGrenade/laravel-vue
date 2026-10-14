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
import type { GiftCardRow, GiftCardStatus } from '@/types/giftCards';
import { Head, Link, router } from '@inertiajs/vue3';
import { Gift, Plus } from '@lucide/vue';
import { computed, reactive, watch } from 'vue';

const props = defineProps<{
    cards: { data: GiftCardRow[]; meta?: PaginationMeta | null };
    filters: { search: string; status: GiftCardStatus | 'all' };
    counts: Record<GiftCardStatus | 'all', number>;
    outstanding: string;
    currency: string;
    enabled: boolean;
    can: { create: boolean };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Commerce', href: route('acp.commerce.index') },
    { title: 'Gift cards', href: route('acp.commerce.gift-cards.index') },
];

const rows = computed(() => props.cards.data ?? []);

const tabs: { value: GiftCardStatus | 'all'; label: string }[] = [
    { value: 'all', label: 'All' },
    { value: 'active', label: 'Active' },
    { value: 'used_up', label: 'Used up' },
    { value: 'expired', label: 'Expired' },
    { value: 'disabled', label: 'Switched off' },
];

const statusLabels: Record<GiftCardStatus, string> = { active: 'Active', used_up: 'Used up', expired: 'Expired', disabled: 'Switched off' };

const statusVariant = (status: GiftCardStatus) =>
    ({ active: 'secondary', used_up: 'outline', expired: 'highlight', disabled: 'destructive' })[status] as
        'secondary' | 'outline' | 'highlight' | 'destructive';

const filterState = reactive({ search: props.filters.search });

watch(
    () => props.filters,
    (filters) => {
        filterState.search = filters.search;
    },
    { deep: true },
);

const go = (overrides: Record<string, string | number> = {}) => {
    const query = Object.fromEntries(
        Object.entries({ status: props.filters.status, search: filterState.search, ...overrides }).filter(
            ([, value]) => String(value).trim() !== '' && value !== 'all',
        ),
    );

    router.get(route('acp.commerce.gift-cards.index'), query, { preserveScroll: true, preserveState: true, replace: true });
};

const { meta, page, pageCount, rangeLabel } = useInertiaPagination({
    meta: computed(() => props.cards.meta ?? null),
    itemsLength: computed(() => rows.value.length),
    defaultPerPage: 25,
    itemLabel: 'card',
    itemLabelPlural: 'cards',
    onNavigate: (newPage) => go({ page: newPage }),
});

const day = (iso: string | null) => (iso ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(iso)) : '');
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Gift cards" />

        <AdminLayout>
            <div class="space-y-6 pb-4">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 class="flex items-center gap-2 text-xl font-semibold tracking-tight"><Gift class="size-5" /> Gift cards</h2>
                        <p class="mt-1 max-w-2xl text-sm text-muted-foreground">
                            Stored value you issue and customers spend at checkout, on the whole order. A card's code is shown once, when it is made,
                            and is not kept: only the end of it is, to recognise the card by.
                        </p>
                        <p v-if="!enabled" class="mt-2 text-sm text-destructive" role="status">
                            Gift cards are switched off (COMMERCE_GIFT_CARDS), so customers cannot use them at checkout. The cards below keep their
                            balances.
                        </p>
                    </div>
                    <Button v-if="can.create" as-child>
                        <Link :href="route('acp.commerce.gift-cards.create')"><Plus class="size-4" /> Make a gift card</Link>
                    </Button>
                </div>

                <Card>
                    <CardContent class="flex flex-wrap items-baseline justify-between gap-x-8 gap-y-2 pt-6">
                        <div>
                            <p class="text-sm text-muted-foreground">Owed on cards that can still be spent</p>
                            <p class="text-3xl font-semibold tabular-nums">{{ formatMoney(outstanding, currency) }}</p>
                        </div>
                        <p class="text-sm text-muted-foreground">{{ counts.active }} active {{ counts.active === 1 ? 'card' : 'cards' }}</p>
                    </CardContent>
                </Card>

                <div class="flex flex-wrap gap-2" role="tablist" aria-label="Gift card status">
                    <Button
                        v-for="tab in tabs"
                        :key="tab.value"
                        size="sm"
                        :variant="filters.status === tab.value ? 'default' : 'outline'"
                        role="tab"
                        :aria-selected="filters.status === tab.value"
                        @click="go({ status: tab.value, page: 1 })"
                    >
                        {{ tab.label }}
                        <span class="tabular-nums opacity-80">{{ counts[tab.value] }}</span>
                    </Button>
                </div>

                <form class="flex flex-wrap items-end gap-3 rounded-lg border border-border bg-card p-4 shadow-xs" @submit.prevent="go({ page: 1 })">
                    <div class="flex min-w-60 flex-1 flex-col gap-2">
                        <label for="gift-card-search" class="text-sm font-medium">Search</label>
                        <Input
                            id="gift-card-search"
                            v-model="filterState.search"
                            type="search"
                            placeholder="A whole code, its last four characters, or a note"
                        />
                    </div>
                    <Button type="submit">Search</Button>
                    <Button
                        v-if="filterState.search"
                        type="button"
                        variant="outline"
                        @click="
                            filterState.search = '';
                            go({ page: 1, search: '' });
                        "
                        >Clear</Button
                    >
                </form>

                <Card>
                    <CardContent class="overflow-x-auto pt-6">
                        <Table v-if="rows.length">
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Card</TableHead>
                                    <TableHead>Balance</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Expires</TableHead>
                                    <TableHead>Made</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow v-for="card in rows" :key="card.id">
                                    <TableCell>
                                        <Link :href="route('acp.commerce.gift-cards.show', card.id)" class="font-mono font-medium hover:underline">{{
                                            card.masked
                                        }}</Link>
                                        <p v-if="card.note" class="max-w-64 truncate text-xs text-muted-foreground">{{ card.note }}</p>
                                    </TableCell>
                                    <TableCell class="whitespace-nowrap tabular-nums">
                                        {{ formatMoney(card.balance, card.currency) }}
                                        <span class="text-xs text-muted-foreground">of {{ formatMoney(card.initial_amount, card.currency) }}</span>
                                    </TableCell>
                                    <TableCell>
                                        <Badge :variant="statusVariant(card.status)">{{ statusLabels[card.status] }}</Badge>
                                    </TableCell>
                                    <TableCell class="text-sm whitespace-nowrap text-muted-foreground">{{
                                        card.expires_at ? day(card.expires_at) : 'Never'
                                    }}</TableCell>
                                    <TableCell class="text-sm whitespace-nowrap text-muted-foreground">
                                        {{ day(card.created_at) }}<template v-if="card.issued_by"> by {{ card.issued_by }}</template>
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                        <p v-else-if="filterState.search || filters.status !== 'all'" class="py-8 text-center text-sm text-muted-foreground">
                            No cards match.
                        </p>
                        <p v-else class="py-8 text-center text-sm text-muted-foreground">No gift cards yet.</p>
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
