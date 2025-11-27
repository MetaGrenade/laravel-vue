<script setup lang="ts">
import { computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { type BreadcrumbItem } from '@/types';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Button } from '@/components/ui/button';
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
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Billing invoices', href: route('acp.billing.invoices.index') },
];

const invoices = computed(() => props.invoices.data ?? []);

const { page, setPage, pageCount, rangeLabel } = useInertiaPagination({
    meta: computed(() => props.invoices.meta ?? null),
    itemsLength: computed(() => props.invoices.data?.length ?? 0),
    itemLabel: 'invoice',
    onNavigate: (newPage) => {
        router.get(
            route('acp.billing.invoices.index'),
            { page: newPage },
            {
                preserveScroll: true,
                preserveState: true,
                replace: true,
            },
        );
    },
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
