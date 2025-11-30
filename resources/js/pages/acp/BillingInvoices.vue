<script setup lang="ts">
import { computed, ref } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { type BreadcrumbItem } from '@/types';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Button } from '@/components/ui/button';
import Input from '@/components/ui/input/Input.vue';
import { Label } from '@/components/ui/label';
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
import { useInertiaPagination, type PaginationMeta } from '@/composables/useInertiaPagination';
import HeadingSmall from '@/components/HeadingSmall.vue';

interface InvoiceUser {
    id: number;
    nickname: string;
    email: string;
}

interface InvoicePlan {
    id: number;
    name: string;
}

interface InvoiceItem {
    id: number;
    stripe_id: string;
    status: string;
    currency: string;
    total: number;
    subtotal: number;
    tax: number;
    created_at: string | null;
    paid_at: string | null;
    user: InvoiceUser | null;
    plan: InvoicePlan | null;
}

interface PaginationLinks {
    first: string | null;
    last: string | null;
    prev: string | null;
    next: string | null;
}

interface Props {
    invoices: {
        data: InvoiceItem[];
        meta?: PaginationMeta | null;
        links?: PaginationLinks | null;
    };
    filters: {
        search: string | null;
        status: string | null;
        date_from: string | null;
        date_to: string | null;
    };
    statusOptions: string[];
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Billing invoices', href: route('acp.billing.invoices.index') },
];

const invoices = computed(() => props.invoices.data ?? []);
const searchQuery = ref(props.filters.search ?? '');
const statusFilter = ref(props.filters.status ?? '');
const dateFrom = ref(props.filters.date_from ?? '');
const dateTo = ref(props.filters.date_to ?? '');

const applyFilters = (overrides: Record<string, string | number | null> = {}) => {
    router.get(
        route('acp.billing.invoices.index'),
        {
            search: searchQuery.value || null,
            status: statusFilter.value || null,
            date_from: dateFrom.value || null,
            date_to: dateTo.value || null,
            ...overrides,
        },
        {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        },
    );
};

const resetFilters = () => {
    searchQuery.value = '';
    statusFilter.value = '';
    dateFrom.value = '';
    dateTo.value = '';
    applyFilters({ page: 1 });
};

const { page, setPage, pageCount, rangeLabel } = useInertiaPagination({
    meta: computed(() => props.invoices.meta ?? null),
    itemsLength: computed(() => props.invoices.data?.length ?? 0),
    itemLabel: 'invoice',
    onNavigate: (newPage) => applyFilters({ page: newPage }),
});

const formatCurrency = (amount: number, currency: string) => {
    const formatter = new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: currency.toUpperCase(),
    });

    return formatter.format(amount / 100);
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Billing invoices" />

        <AdminLayout>
            <section class="flex flex-col w-full space-y-6">
                <HeadingSmall
                    title="Stripe invoices"
                    description="Monitor webhook-synced invoice activity across the community."
                />

                <div class="rounded-lg border border-border bg-card p-4 shadow-sm space-y-4">
                    <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                        <div class="space-y-2">
                            <Label for="invoice-search">Search</Label>
                            <Input
                                id="invoice-search"
                                v-model="searchQuery"
                                type="search"
                                placeholder="Stripe ID, customer, or plan"
                            />
                        </div>
                        <div class="space-y-2">
                            <Label for="invoice-status">Status</Label>
                            <select
                                id="invoice-status"
                                v-model="statusFilter"
                                class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-base ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 md:text-sm"
                            >
                                <option value="">All statuses</option>
                                <option v-for="status in props.statusOptions" :key="status" :value="status">
                                    {{ status }}
                                </option>
                            </select>
                        </div>
                        <div class="space-y-2">
                            <Label for="invoice-date-from">Created after</Label>
                            <Input id="invoice-date-from" v-model="dateFrom" type="date" />
                        </div>
                        <div class="space-y-2">
                            <Label for="invoice-date-to">Created before</Label>
                            <Input id="invoice-date-to" v-model="dateTo" type="date" />
                        </div>
                    </div>

                    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                        <div class="flex gap-2">
                            <Button variant="outline" type="button" @click="resetFilters">Reset</Button>
                            <Button type="button" @click="applyFilters({ page: 1 })">Apply filters</Button>
                        </div>
                    </div>
                </div>

                <div class="overflow-x-auto rounded-lg border border-border bg-card shadow-sm">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Invoice</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Customer</TableHead>
                                <TableHead>Plan</TableHead>
                                <TableHead>Total</TableHead>
                                <TableHead>Created</TableHead>
                                <TableHead>Paid</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-if="invoices.length === 0">
                                <TableCell colspan="7" class="text-center text-sm text-muted-foreground">
                                    No invoices recorded yet.
                                </TableCell>
                            </TableRow>
                            <TableRow v-for="invoice in invoices" :key="invoice.id">
                                <TableCell class="font-mono text-xs">{{ invoice.stripe_id }}</TableCell>
                                <TableCell class="capitalize">{{ invoice.status }}</TableCell>
                                <TableCell>
                                    <div v-if="invoice.user" class="flex flex-col text-sm">
                                        <span class="font-medium">{{ invoice.user.nickname }}</span>
                                        <span class="text-xs text-muted-foreground">{{ invoice.user.email }}</span>
                                    </div>
                                    <span v-else class="text-xs text-muted-foreground">Unknown</span>
                                </TableCell>
                                <TableCell>{{ invoice.plan?.name ?? '—' }}</TableCell>
                                <TableCell>{{ formatCurrency(invoice.total, invoice.currency) }}</TableCell>
                                <TableCell>
                                    {{ invoice.created_at ? new Date(invoice.created_at).toLocaleString() : '—' }}
                                </TableCell>
                                <TableCell>
                                    {{ invoice.paid_at ? new Date(invoice.paid_at).toLocaleString() : '—' }}
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>

                <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <p class="text-sm text-muted-foreground">{{ rangeLabel }}</p>
                    <Pagination v-if="pageCount > 1">
                        <PaginationList>
                            <PaginationListItem>
                                <PaginationFirst :disabled="page <= 1" @click="setPage(1)" />
                            </PaginationListItem>
                            <PaginationListItem>
                                <PaginationPrev :disabled="page <= 1" @click="setPage(page - 1)" />
                            </PaginationListItem>
                            <PaginationListItem v-if="page > 2">
                                <PaginationEllipsis />
                            </PaginationListItem>
                            <PaginationListItem>
                                <Button variant="outline" class="h-8 min-w-[2rem] px-3" disabled>{{ page }}</Button>
                            </PaginationListItem>
                            <PaginationListItem v-if="page < pageCount - 1">
                                <PaginationEllipsis />
                            </PaginationListItem>
                            <PaginationListItem>
                                <PaginationNext :disabled="page >= pageCount" @click="setPage(page + 1)" />
                            </PaginationListItem>
                            <PaginationListItem>
                                <PaginationLast :disabled="page >= pageCount" @click="setPage(pageCount)" />
                            </PaginationListItem>
                        </PaginationList>
                    </Pagination>
                </div>
            </section>
        </AdminLayout>
    </AppLayout>
</template>
