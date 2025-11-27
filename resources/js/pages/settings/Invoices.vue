<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/SettingsLayout.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Head } from '@inertiajs/vue3';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Button } from '@/components/ui/button';

interface InvoicePayload {
    id: string;
    number: string;
    status: string;
    total: number;
    currency: string;
    created_at: string | null;
    paid_at: string | null;
}

interface Props {
    invoices: InvoicePayload[];
}

const props = defineProps<Props>();

const formatCurrency = (amount: number, currency: string) => {
    const formatter = new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: currency.toUpperCase(),
    });

    return formatter.format(amount / 100);
};
</script>

<template>
    <AppLayout :breadcrumbs="[{ title: 'Invoices', href: '/settings/billing/invoices' }]">
        <Head title="Invoices" />

        <SettingsLayout>
            <section class="space-y-4">
                <HeadingSmall
                    title="Billing invoices"
                    description="Download PDF receipts for your subscription and billing history."
                />

                <div class="overflow-x-auto rounded-lg border border-border bg-card shadow-sm">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Invoice</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Total</TableHead>
                                <TableHead>Issued</TableHead>
                                <TableHead>Paid</TableHead>
                                <TableHead class="text-right">Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-if="props.invoices.length === 0">
                                <TableCell colspan="6" class="text-center text-sm text-muted-foreground">
                                    No invoices have been generated yet.
                                </TableCell>
                            </TableRow>
                            <TableRow v-for="invoice in props.invoices" :key="invoice.id">
                                <TableCell class="font-mono text-xs">{{ invoice.number }}</TableCell>
                                <TableCell class="capitalize">{{ invoice.status }}</TableCell>
                                <TableCell>{{ formatCurrency(invoice.total, invoice.currency) }}</TableCell>
                                <TableCell>{{ invoice.created_at ? new Date(invoice.created_at).toLocaleString() : '—' }}</TableCell>
                                <TableCell>{{ invoice.paid_at ? new Date(invoice.paid_at).toLocaleString() : '—' }}</TableCell>
                                <TableCell class="text-right">
                                    <Button as-child variant="outline" size="sm">
                                        <a
                                            :href="route('settings.billing.invoices.download', { invoice: invoice.id })"
                                            download
                                        >
                                            Download PDF
                                        </a>
                                    </Button>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>
            </section>
        </SettingsLayout>
    </AppLayout>
</template>
