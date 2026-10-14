<script setup lang="ts">
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
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
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { useInertiaPagination, type PaginationMeta } from '@/composables/useInertiaPagination';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatMoney } from '@/lib/money';
import type { BreadcrumbItem } from '@/types';
import type { GiftCardLedgerRow, GiftCardRow, GiftCardStatus } from '@/types/giftCards';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Check, CircleAlert, Copy, Gift, KeyRound, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';

type Card = GiftCardRow & { is_active: boolean; expires_at_date: string | null; ledger_matches: boolean; orders: number };

const props = defineProps<{
    card: Card;
    /** The code, only on the page that made it (or replaced it); null on every other visit. */
    code: string | null;
    ledger: { data: GiftCardLedgerRow[]; meta?: PaginationMeta | null };
    currency: string;
    can: { create: boolean; edit: boolean; delete: boolean };
    deletable: boolean;
}>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Commerce', href: route('acp.commerce.index') },
    { title: 'Gift cards', href: route('acp.commerce.gift-cards.index') },
    { title: props.card.masked, href: route('acp.commerce.gift-cards.show', props.card.id) },
]);

const money = (amount: string) => formatMoney(amount, props.currency);
const day = (iso: string | null) => (iso ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(iso)) : '');
const when = (iso: string | null) =>
    iso ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso)) : '';

const statusLabels: Record<GiftCardStatus, string> = { active: 'Active', used_up: 'Used up', expired: 'Expired', disabled: 'Switched off' };
const statusVariant = (status: GiftCardStatus) =>
    ({ active: 'secondary', used_up: 'outline', expired: 'highlight', disabled: 'destructive' })[status] as
        'secondary' | 'outline' | 'highlight' | 'destructive';

const typeLabels: Record<GiftCardLedgerRow['type'], string> = {
    issued: 'Made',
    redeemed: 'Spent on an order',
    released: 'Given back (order not paid)',
    refunded: 'Refunded to the card',
    adjusted: 'Changed by the team',
};

// ----- The code, shown once --------------------------------------------------------------------------------

const copied = ref(false);

const copyCode = async () => {
    try {
        await navigator.clipboard.writeText(props.code ?? '');
        copied.value = true;
        setTimeout(() => (copied.value = false), 2500);
    } catch {
        // Not a secure page, or refused: the code is on screen to select by hand.
    }
};

// ----- Changing the card -----------------------------------------------------------------------------------

const settings = useForm({
    is_active: props.card.is_active,
    expires_at: props.card.expires_at_date ?? '',
    note: props.card.note ?? '',
});

const saveSettings = () => settings.patch(route('acp.commerce.gift-cards.update', props.card.id), { preserveScroll: true });

const adjustment = useForm({ direction: 'add', amount: '', note: '' });

const adjust = () =>
    adjustment.post(route('acp.commerce.gift-cards.adjust', props.card.id), {
        preserveScroll: true,
        onSuccess: () => adjustment.reset(),
    });

const confirmingReplace = ref(false);
const confirmingDelete = ref(false);

const replaceCode = () =>
    router.post(
        route('acp.commerce.gift-cards.regenerate', props.card.id),
        {},
        { preserveScroll: true, onFinish: () => (confirmingReplace.value = false) },
    );

const remove = () => router.delete(route('acp.commerce.gift-cards.destroy', props.card.id), { onFinish: () => (confirmingDelete.value = false) });

// ----- The history -----------------------------------------------------------------------------------------

const { meta, page, pageCount, rangeLabel } = useInertiaPagination({
    meta: computed(() => props.ledger.meta ?? null),
    itemsLength: computed(() => props.ledger.data.length),
    defaultPerPage: 25,
    itemLabel: 'entry',
    itemLabelPlural: 'entries',
    onNavigate: (newPage) =>
        router.get(route('acp.commerce.gift-cards.show', props.card.id), { page: newPage }, { preserveScroll: true, preserveState: true }),
});

const selectClass = 'rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs focus:border-primary focus:outline-hidden';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`Gift card ${card.masked}`" />

        <AdminLayout>
            <div class="space-y-6 pb-4">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 class="flex flex-wrap items-center gap-3 text-xl font-semibold tracking-tight">
                            <Gift class="size-5" />
                            <span class="font-mono">{{ card.masked }}</span>
                            <Badge :variant="statusVariant(card.status)">{{ statusLabels[card.status] }}</Badge>
                        </h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Made {{ day(card.created_at) }}<template v-if="card.issued_by"> by {{ card.issued_by }}</template
                            >. Spent on {{ card.orders }} {{ card.orders === 1 ? 'order' : 'orders' }}.
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Button v-if="can.create" variant="outline" @click="confirmingReplace = true"
                            ><KeyRound class="size-4" /> Replace the code</Button
                        >
                        <Button v-if="can.delete && deletable" variant="outline" class="text-destructive" @click="confirmingDelete = true">
                            <Trash2 class="size-4" /> Delete
                        </Button>
                    </div>
                </div>

                <!-- The code: here once, never again -->
                <div v-if="code" class="space-y-3 rounded-lg border-2 border-primary/50 bg-primary/5 p-5" role="status">
                    <p class="font-medium">The code for this card</p>
                    <div class="flex flex-wrap items-center gap-3">
                        <code class="text-2xl font-semibold tracking-wider break-all select-all" data-testid="gift-card-code">{{ code }}</code>
                        <Button type="button" size="sm" variant="outline" @click="copyCode">
                            <component :is="copied ? Check : Copy" class="size-4" /> {{ copied ? 'Copied' : 'Copy' }}
                        </Button>
                    </div>
                    <p class="flex items-start gap-2 text-sm text-muted-foreground">
                        <CircleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                        It is not kept anywhere, so this is the only time it is shown. Give it to the customer now. If it is lost, replace the code.
                    </p>
                </div>

                <div
                    v-if="!card.ledger_matches"
                    class="flex items-start gap-3 rounded-lg border border-destructive/40 bg-destructive/5 p-4 text-sm"
                    role="alert"
                >
                    <CircleAlert class="mt-0.5 size-4 shrink-0 text-destructive" />
                    <p>
                        <strong>The history below does not add up to the balance.</strong> Something changed this card without being recorded. Do not
                        issue more value on it until that is understood.
                    </p>
                </div>

                <div class="grid gap-4 md:grid-cols-3">
                    <Card>
                        <CardHeader>
                            <CardDescription>Balance</CardDescription>
                            <CardTitle class="text-3xl tabular-nums">{{ money(card.balance) }}</CardTitle>
                        </CardHeader>
                        <CardContent class="text-sm text-muted-foreground">Of {{ money(card.initial_amount) }} when it was made</CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardDescription>Expires</CardDescription>
                            <CardTitle class="text-xl">{{ card.expires_at ? day(card.expires_at) : 'Never' }}</CardTitle>
                        </CardHeader>
                        <CardContent class="text-sm text-muted-foreground">{{
                            card.status === 'expired' ? 'It has expired; its balance is kept.' : 'A customer can spend it until then.'
                        }}</CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardDescription>Note</CardDescription>
                            <CardTitle class="text-base font-normal">{{ card.note || 'None' }}</CardTitle>
                        </CardHeader>
                    </Card>
                </div>

                <div class="grid gap-6 lg:grid-cols-2">
                    <Card v-if="can.create">
                        <CardHeader>
                            <CardTitle>Add or take away value</CardTitle>
                            <CardDescription>It goes in the history of the card with the reason you give.</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form class="space-y-4" @submit.prevent="adjust">
                                <div class="flex flex-wrap items-end gap-3">
                                    <div class="grid gap-2">
                                        <Label for="adjust-direction">What to do</Label>
                                        <select id="adjust-direction" v-model="adjustment.direction" :class="selectClass">
                                            <option value="add">Add</option>
                                            <option v-if="can.edit" value="remove">Take away</option>
                                        </select>
                                    </div>
                                    <div class="grid gap-2">
                                        <Label for="adjust-amount">Amount ({{ currency }})</Label>
                                        <Input
                                            id="adjust-amount"
                                            v-model="adjustment.amount"
                                            type="number"
                                            inputmode="decimal"
                                            min="0.01"
                                            step="0.01"
                                            class="w-36 tabular-nums"
                                            :aria-invalid="!!adjustment.errors.amount || undefined"
                                        />
                                    </div>
                                </div>
                                <InputError :message="adjustment.errors.amount" />
                                <div class="grid gap-2">
                                    <Label for="adjust-note">Why</Label>
                                    <Textarea
                                        id="adjust-note"
                                        v-model="adjustment.note"
                                        rows="2"
                                        maxlength="500"
                                        :aria-invalid="!!adjustment.errors.note || undefined"
                                    />
                                    <InputError :message="adjustment.errors.note" />
                                </div>
                                <Button type="submit" :disabled="adjustment.processing || adjustment.amount === ''">Save</Button>
                            </form>
                        </CardContent>
                    </Card>

                    <Card v-if="can.edit">
                        <CardHeader>
                            <CardTitle>Settings</CardTitle>
                            <CardDescription>A card that is switched off cannot be spent, and keeps its balance.</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form class="space-y-4" @submit.prevent="saveSettings">
                                <div class="flex items-center gap-2">
                                    <Checkbox id="card-active" v-model="settings.is_active" />
                                    <Label for="card-active" class="font-normal">Customers can spend this card</Label>
                                </div>
                                <div class="grid gap-2">
                                    <Label for="card-expires">Expires</Label>
                                    <Input id="card-expires" v-model="settings.expires_at" type="date" class="max-w-48" />
                                    <InputError :message="settings.errors.expires_at" />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="card-note">Note</Label>
                                    <Textarea id="card-note" v-model="settings.note" rows="2" maxlength="500" />
                                    <InputError :message="settings.errors.note" />
                                </div>
                                <Button type="submit" :disabled="settings.processing">Save settings</Button>
                            </form>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>History</CardTitle>
                        <CardDescription>Every change to the balance, newest first.</CardDescription>
                    </CardHeader>
                    <CardContent class="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>When</TableHead>
                                    <TableHead>What</TableHead>
                                    <TableHead class="text-right">Amount</TableHead>
                                    <TableHead class="text-right">Balance</TableHead>
                                    <TableHead>Order</TableHead>
                                    <TableHead>Note</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow v-for="entry in ledger.data" :key="entry.id">
                                    <TableCell class="text-sm whitespace-nowrap text-muted-foreground">{{ when(entry.created_at) }}</TableCell>
                                    <TableCell class="text-sm">
                                        {{ typeLabels[entry.type] }}<span v-if="entry.by" class="text-muted-foreground"> · {{ entry.by }}</span>
                                    </TableCell>
                                    <TableCell
                                        class="text-right whitespace-nowrap tabular-nums"
                                        :class="Number(entry.amount) < 0 ? 'text-destructive' : ''"
                                    >
                                        {{ Number(entry.amount) > 0 ? '+' : '' }}{{ money(entry.amount) }}
                                    </TableCell>
                                    <TableCell class="text-right whitespace-nowrap tabular-nums">{{ money(entry.balance_after) }}</TableCell>
                                    <TableCell class="whitespace-nowrap">
                                        <Link
                                            v-if="entry.order"
                                            :href="route('acp.commerce.orders.show', entry.order.public_id)"
                                            class="hover:underline"
                                            >{{ entry.order.number }}</Link
                                        >
                                    </TableCell>
                                    <TableCell class="max-w-64 truncate text-sm text-muted-foreground" :title="entry.note ?? undefined">{{
                                        entry.note
                                    }}</TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>

                        <div class="mt-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
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
                                            <Button class="h-9 w-9 p-0" :variant="item.value === currentPage ? 'default' : 'outline'">{{
                                                item.value
                                            }}</Button>
                                        </PaginationListItem>
                                        <PaginationEllipsis v-else :index="index" />
                                    </template>
                                    <PaginationNext />
                                    <PaginationLast />
                                </PaginationList>
                            </Pagination>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <ConfirmDialog
                :open="confirmingReplace"
                title="Replace this card's code?"
                description="The old code stops working at once, and the new one is shown once. The balance does not change."
                confirm-label="Replace the code"
                confirm-variant="default"
                @update:open="(open) => !open && (confirmingReplace = false)"
                @confirm="replaceCode"
                @cancel="confirmingReplace = false"
            />
            <ConfirmDialog
                :open="confirmingDelete"
                title="Delete this gift card?"
                description="It has never been spent on an order. It and its history are removed for good."
                confirm-label="Delete card"
                @update:open="(open) => !open && (confirmingDelete = false)"
                @confirm="remove"
                @cancel="confirmingDelete = false"
            />
        </AdminLayout>
    </AppLayout>
</template>
