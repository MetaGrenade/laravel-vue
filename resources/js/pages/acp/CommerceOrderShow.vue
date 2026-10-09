<script setup lang="ts">
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { addressLines } from '@/lib/address';
import { formatMoney } from '@/lib/money';
import { formatDateTime, orderStatusVariant, paymentStatusVariant } from '@/lib/orderStatus';
import type { BreadcrumbItem } from '@/types';
import type { AddressFormValue, TaxLineQuote } from '@/types/commerce';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { CircleAlert, ExternalLink, PackageCheck, RefreshCw, Undo2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';

interface OrderItem {
    id: number;
    description: string | null;
    quantity: number;
    unit_price: string;
    subtotal: string;
}

interface Shipment {
    carrier?: string;
    tracking_number?: string;
    tracking_url?: string;
}

interface Order {
    public_id: string;
    number: string;
    status: string;
    status_label: string;
    payment_status: string;
    payment_status_label: string;
    currency: string;
    subtotal: string;
    tax_total: string;
    shipping_total: string;
    discount_total: string;
    coupon_code: string | null;
    grand_total: string;
    refunded_total: string;
    customer_name: string | null;
    customer_email: string | null;
    customer: { id: number; nickname: string; email: string } | null;
    shipping_method: string | null;
    shipping_address: Partial<AddressFormValue> | null;
    billing_address: Partial<AddressFormValue> | null;
    tax_lines: TaxLineQuote[];
    shipment: Shipment | null;
    late_payment: boolean;
    placed_at: string | null;
    paid_at: string | null;
    fulfilled_at: string | null;
    cancelled_at: string | null;
    items: OrderItem[];
}

interface PaymentRow {
    id: number;
    provider: string;
    status: string;
    amount: string;
    currency: string;
    reference: string | null;
    paid_at: string | null;
    created_at: string | null;
    url: string | null;
}

interface RefundRow {
    id: number;
    amount: string;
    currency: string;
    status: 'pending' | 'succeeded' | 'failed' | 'canceled';
    status_label: string;
    reason: string | null;
    note: string | null;
    failure_reason: string | null;
    provider: string | null;
    reference: string | null;
    restock: boolean;
    issued_by: string | null;
    created_at: string | null;
    processed_at: string | null;
    can_check: boolean;
}

interface EventRow {
    id: number;
    type: string;
    message: string | null;
    by: string | null;
    created_at: string | null;
}

const props = defineProps<{
    order: Order;
    payments: PaymentRow[];
    refunds: RefundRow[];
    events: EventRow[];
    refunding: {
        refundable: string;
        can_refund: boolean;
        via_provider: boolean;
        provider_label: string | null;
        reasons: { value: string; label: string }[];
    };
    actions: { can_fulfil: boolean; can_cancel: boolean; can_check_payment: boolean };
    can: { edit: boolean; refund: boolean };
}>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Commerce', href: route('acp.commerce.index') },
    { title: 'Orders', href: route('acp.commerce.orders.index') },
    { title: props.order.number, href: route('acp.commerce.orders.show', props.order.public_id) },
]);

const money = (amount: string | number) => formatMoney(amount, props.order.currency);

const hasRefunds = computed(() => Number(props.order.refunded_total) > 0);

/** What the customer paid less what was returned, worked out in whole cents to avoid float drift. */
const netTotal = computed(() => (Math.round(Number(props.order.grand_total) * 100) - Math.round(Number(props.order.refunded_total) * 100)) / 100);

const reasonLabel = (reason: string | null) => props.refunding.reasons.find((option) => option.value === reason)?.label ?? null;

const refundVariant = (status: RefundRow['status']) => {
    switch (status) {
        case 'succeeded':
            return 'default' as const;
        case 'failed':
            return 'destructive' as const;
        default:
            return 'outline' as const;
    }
};

// A sentence for the failure codes Stripe sends; anything else is shown as it came.
const failureText = (reason: string | null) => {
    switch (reason) {
        case 'lost_or_stolen_card':
            return 'The card was lost or stolen.';
        case 'expired_or_canceled_card':
            return 'The card has expired or was cancelled.';
        case 'requires_action':
            return 'Waiting for the customer or their bank to act.';
        case null:
            return null;
        default:
            return reason;
    }
};

// ----- Fulfil ------------------------------------------------------------------------------------

const fulfilOpen = ref(false);
const fulfilForm = useForm({ carrier: '', tracking_number: '', tracking_url: '', notify_customer: true });

const openFulfil = () => {
    fulfilForm.defaults({ carrier: '', tracking_number: '', tracking_url: '', notify_customer: true }).reset();
    fulfilForm.clearErrors();
    fulfilOpen.value = true;
};

const fulfil = () => {
    fulfilForm.post(route('acp.commerce.orders.fulfil', props.order.public_id), {
        preserveScroll: true,
        onSuccess: () => {
            fulfilOpen.value = false;
        },
    });
};

// ----- Refund ------------------------------------------------------------------------------------

/** A random id for one refund form, so pressing the button twice cannot refund twice. Works without a secure context. */
const newToken = () => {
    if (typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }

    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
};

const refundOpen = ref(false);

const blankRefund = () => ({
    amount: props.refunding.refundable,
    reason: 'requested_by_customer',
    note: '',
    restock: false,
    notify_customer: true,
    // Without a provider that can send the money back, the only thing left is to record one.
    manual: !props.refunding.via_provider,
    token: newToken(),
});

const refundForm = useForm(blankRefund());

const openRefund = () => {
    refundForm.defaults(blankRefund()).reset();
    refundForm.clearErrors();
    refundOpen.value = true;
};

const cents = (value: string) => {
    const number = Number(value);

    return value.trim() !== '' && Number.isFinite(number) ? Math.round(number * 100) : null;
};

/** Stock can only go back when this refund returns everything that is left. */
const completesOrder = computed(() => cents(refundForm.amount) !== null && cents(refundForm.amount) === cents(props.refunding.refundable));

// An error belongs to the amount that was submitted; once it is edited the message is stale.
watch(
    () => refundForm.amount,
    () => refundForm.clearErrors('amount', 'restock'),
);

watch(completesOrder, (completes) => {
    if (!completes) {
        refundForm.restock = false;
    }
});

const refund = () => {
    refundForm.post(route('acp.commerce.orders.refunds.store', props.order.public_id), {
        preserveScroll: true,
        onSuccess: () => {
            refundOpen.value = false;
        },
    });
};

const checkRefund = (refundId: number) => {
    router.post(route('acp.commerce.orders.refunds.check', [props.order.public_id, refundId]), {}, { preserveScroll: true });
};

// ----- Cancel, check payment, notes --------------------------------------------------------------

const cancelOpen = ref(false);

const cancelOrder = () => {
    router.post(
        route('acp.commerce.orders.cancel', props.order.public_id),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                cancelOpen.value = false;
            },
        },
    );
};

const checkPayment = () => {
    router.post(route('acp.commerce.orders.check-payment', props.order.public_id), {}, { preserveScroll: true });
};

const noteForm = useForm({ note: '' });

const addNote = () => {
    noteForm.post(route('acp.commerce.orders.notes.store', props.order.public_id), {
        preserveScroll: true,
        onSuccess: () => noteForm.reset(),
    });
};

const selectClass =
    'h-9 w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 md:text-sm';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`Order ${props.order.number}`" />

        <AdminLayout>
            <div class="space-y-6 pb-4">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="space-y-2">
                        <h2 class="text-xl font-semibold tracking-tight">Order {{ props.order.number }}</h2>
                        <div class="flex flex-wrap items-center gap-2">
                            <Badge :variant="orderStatusVariant(props.order.status)">{{ props.order.status_label }}</Badge>
                            <Badge :variant="paymentStatusVariant(props.order.payment_status)">{{ props.order.payment_status_label }}</Badge>
                            <span class="text-sm text-muted-foreground">Placed {{ formatDateTime(props.order.placed_at) }}</span>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Button v-if="props.can.edit && props.actions.can_fulfil" @click="openFulfil">
                            <PackageCheck class="size-4" /> Mark as fulfilled
                        </Button>
                        <Button v-if="props.can.refund && props.refunding.can_refund" variant="outline" @click="openRefund">
                            <Undo2 class="size-4" /> Refund
                        </Button>
                        <Button v-if="props.can.edit && props.actions.can_check_payment" variant="outline" @click="checkPayment">
                            <RefreshCw class="size-4" /> Check payment
                        </Button>
                        <Button
                            v-if="props.can.edit && props.actions.can_cancel"
                            variant="outline"
                            class="text-destructive"
                            @click="cancelOpen = true"
                        >
                            Cancel order
                        </Button>
                    </div>
                </div>

                <Alert v-if="props.order.late_payment" variant="warning">
                    <CircleAlert />
                    <AlertDescription>
                        The customer paid just as this order was being cancelled, so it was reinstated. Check that stock is available and that they
                        were not charged twice.
                    </AlertDescription>
                </Alert>

                <div class="grid gap-6 lg:grid-cols-3">
                    <div class="space-y-6 lg:col-span-2">
                        <Card>
                            <CardHeader>
                                <CardTitle>Items</CardTitle>
                            </CardHeader>
                            <CardContent class="space-y-4">
                                <ul class="divide-y text-sm">
                                    <li v-for="item in props.order.items" :key="item.id" class="flex justify-between gap-4 py-3 first:pt-0">
                                        <span>
                                            {{ item.description || 'Item' }}
                                            <span class="text-muted-foreground">× {{ item.quantity }} at {{ money(item.unit_price) }}</span>
                                        </span>
                                        <span class="tabular-nums">{{ money(item.subtotal) }}</span>
                                    </li>
                                </ul>

                                <dl class="space-y-1 border-t pt-4 text-sm">
                                    <div class="flex justify-between">
                                        <dt class="text-muted-foreground">Subtotal</dt>
                                        <dd class="tabular-nums">{{ money(props.order.subtotal) }}</dd>
                                    </div>
                                    <div v-if="Number(props.order.discount_total) > 0" class="flex justify-between">
                                        <dt class="text-muted-foreground">
                                            Discount<template v-if="props.order.coupon_code"> ({{ props.order.coupon_code }})</template>
                                        </dt>
                                        <dd class="tabular-nums">−{{ money(props.order.discount_total) }}</dd>
                                    </div>
                                    <div v-if="Number(props.order.shipping_total) > 0 || props.order.shipping_method" class="flex justify-between">
                                        <dt class="text-muted-foreground">
                                            Shipping<span v-if="props.order.shipping_method"> ({{ props.order.shipping_method }})</span>
                                        </dt>
                                        <dd class="tabular-nums">{{ money(props.order.shipping_total) }}</dd>
                                    </div>
                                    <div v-for="line in props.order.tax_lines" :key="line.name" class="flex justify-between">
                                        <dt class="text-muted-foreground">{{ line.name }} ({{ line.rate }}%)</dt>
                                        <dd class="tabular-nums">{{ money(line.amount) }}</dd>
                                    </div>
                                    <div class="flex justify-between border-t pt-2 font-semibold">
                                        <dt>Total</dt>
                                        <dd class="tabular-nums">{{ money(props.order.grand_total) }}</dd>
                                    </div>
                                    <template v-if="hasRefunds">
                                        <div class="flex justify-between">
                                            <dt class="text-muted-foreground">Refunded</dt>
                                            <dd class="tabular-nums">−{{ money(props.order.refunded_total) }}</dd>
                                        </div>
                                        <div class="flex justify-between font-semibold">
                                            <dt>Net</dt>
                                            <dd class="tabular-nums">{{ money(netTotal) }}</dd>
                                        </div>
                                    </template>
                                </dl>
                            </CardContent>
                        </Card>

                        <Card v-if="props.refunds.length">
                            <CardHeader>
                                <CardTitle>Refunds</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <ul class="divide-y">
                                    <li v-for="item in props.refunds" :key="item.id" class="space-y-1 py-3 text-sm first:pt-0 last:pb-0">
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <div class="flex items-center gap-2">
                                                <span class="font-medium tabular-nums">{{ formatMoney(item.amount, item.currency) }}</span>
                                                <Badge :variant="refundVariant(item.status)">{{ item.status_label }}</Badge>
                                                <Badge v-if="!item.provider" variant="outline">Recorded by hand</Badge>
                                            </div>
                                            <Button v-if="item.can_check" variant="outline" size="sm" @click="checkRefund(item.id)">
                                                <RefreshCw class="size-3.5" /> Check status
                                            </Button>
                                        </div>
                                        <p class="text-muted-foreground">
                                            {{ formatDateTime(item.created_at) }}
                                            <template v-if="item.issued_by"> by {{ item.issued_by }}</template>
                                            <template v-else-if="item.provider"> in the {{ item.provider }} dashboard</template>
                                            <template v-if="reasonLabel(item.reason)"> · {{ reasonLabel(item.reason) }}</template>
                                            <template v-if="item.restock"> · stock returned</template>
                                        </p>
                                        <p v-if="item.note">{{ item.note }}</p>
                                        <p v-if="failureText(item.failure_reason)" class="text-destructive">{{ failureText(item.failure_reason) }}</p>
                                        <p v-if="item.reference" class="font-mono text-xs text-muted-foreground">{{ item.reference }}</p>
                                    </li>
                                </ul>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>History</CardTitle>
                                <CardDescription>What happened to this order, and notes for the team. Customers cannot see this.</CardDescription>
                            </CardHeader>
                            <CardContent class="space-y-5">
                                <form v-if="props.can.edit" class="space-y-2" @submit.prevent="addNote">
                                    <Label for="order-note" class="sr-only">Add a note</Label>
                                    <Textarea
                                        id="order-note"
                                        v-model="noteForm.note"
                                        rows="2"
                                        maxlength="1000"
                                        placeholder="Add a note for the team…"
                                        :aria-invalid="!!noteForm.errors.note || undefined"
                                    />
                                    <InputError :message="noteForm.errors.note" />
                                    <Button type="submit" size="sm" variant="outline" :disabled="noteForm.processing || !noteForm.note.trim()">
                                        Add note
                                    </Button>
                                </form>

                                <ol v-if="props.events.length" class="space-y-3 border-l pl-4">
                                    <li v-for="event in props.events" :key="event.id" class="relative text-sm">
                                        <span class="absolute top-1.5 -left-[1.3rem] size-2 rounded-full bg-border" aria-hidden="true" />
                                        <p>
                                            <Badge v-if="event.type === 'note'" variant="secondary" class="mr-1.5">Note</Badge>
                                            {{ event.message }}
                                        </p>
                                        <p class="text-xs text-muted-foreground">
                                            {{ formatDateTime(event.created_at) }}<template v-if="event.by"> · {{ event.by }}</template>
                                        </p>
                                    </li>
                                </ol>
                                <p v-else class="text-sm text-muted-foreground">Nothing has happened yet.</p>
                            </CardContent>
                        </Card>
                    </div>

                    <div class="space-y-6">
                        <Card>
                            <CardHeader>
                                <CardTitle>Customer</CardTitle>
                            </CardHeader>
                            <CardContent class="space-y-1 text-sm">
                                <p class="font-medium">{{ props.order.customer_name || props.order.customer?.nickname || 'Guest' }}</p>
                                <p v-if="props.order.customer_email">
                                    <a :href="`mailto:${props.order.customer_email}`" class="text-primary hover:underline">{{
                                        props.order.customer_email
                                    }}</a>
                                </p>
                                <p v-if="props.order.customer" class="text-muted-foreground">
                                    Account:
                                    <Link :href="route('acp.users.edit', props.order.customer.id)" class="text-primary hover:underline">{{
                                        props.order.customer.nickname
                                    }}</Link>
                                </p>
                                <p v-else class="text-muted-foreground">Checked out as a guest.</p>
                            </CardContent>
                        </Card>

                        <Card v-if="props.order.shipping_address">
                            <CardHeader>
                                <CardTitle>Ship to</CardTitle>
                            </CardHeader>
                            <CardContent class="space-y-3 text-sm">
                                <address class="not-italic">
                                    <template v-for="line in addressLines(props.order.shipping_address)" :key="line">{{ line }}<br /></template>
                                </address>
                                <p v-if="props.order.shipping_address.phone" class="text-muted-foreground">
                                    {{ props.order.shipping_address.phone }}
                                </p>
                                <p v-if="props.order.shipping_method" class="text-muted-foreground">{{ props.order.shipping_method }}</p>
                            </CardContent>
                        </Card>

                        <Card v-if="props.order.billing_address">
                            <CardHeader>
                                <CardTitle>Billing address</CardTitle>
                            </CardHeader>
                            <CardContent class="text-sm">
                                <address class="not-italic">
                                    <template v-for="line in addressLines(props.order.billing_address)" :key="line">{{ line }}<br /></template>
                                </address>
                            </CardContent>
                        </Card>

                        <Card v-if="props.order.shipment">
                            <CardHeader>
                                <CardTitle>Shipment</CardTitle>
                                <CardDescription v-if="props.order.fulfilled_at"
                                    >Fulfilled {{ formatDateTime(props.order.fulfilled_at) }}</CardDescription
                                >
                            </CardHeader>
                            <CardContent class="space-y-1 text-sm">
                                <p v-if="props.order.shipment.carrier">{{ props.order.shipment.carrier }}</p>
                                <p v-if="props.order.shipment.tracking_number" class="font-mono">{{ props.order.shipment.tracking_number }}</p>
                                <a
                                    v-if="props.order.shipment.tracking_url"
                                    :href="props.order.shipment.tracking_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex items-center gap-1 text-primary hover:underline"
                                >
                                    Track parcel <ExternalLink class="size-3.5" />
                                </a>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>Payments</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <ul v-if="props.payments.length" class="divide-y text-sm">
                                    <li v-for="payment in props.payments" :key="payment.id" class="space-y-1 py-3 first:pt-0 last:pb-0">
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="font-medium capitalize">{{ payment.provider }}</span>
                                            <Badge
                                                :variant="
                                                    payment.status === 'succeeded'
                                                        ? 'default'
                                                        : payment.status === 'review'
                                                          ? 'destructive'
                                                          : 'outline'
                                                "
                                                >{{ payment.status }}</Badge
                                            >
                                        </div>
                                        <p class="tabular-nums">{{ formatMoney(payment.amount, payment.currency) }}</p>
                                        <p class="text-xs text-muted-foreground">{{ formatDateTime(payment.paid_at ?? payment.created_at) }}</p>
                                        <p v-if="payment.status === 'review'" class="text-xs text-destructive">
                                            The amount Stripe took did not match the order, so it was not applied. Check it in Stripe.
                                        </p>
                                        <a
                                            v-if="payment.url"
                                            :href="payment.url"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="inline-flex items-center gap-1 font-mono text-xs break-all text-primary hover:underline"
                                        >
                                            {{ payment.reference }} <ExternalLink class="size-3 shrink-0" />
                                        </a>
                                        <p v-else-if="payment.reference" class="font-mono text-xs break-all text-muted-foreground">
                                            {{ payment.reference }}
                                        </p>
                                    </li>
                                </ul>
                                <p v-else class="text-sm text-muted-foreground">No payment attempts.</p>
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </div>

            <Dialog v-model:open="fulfilOpen">
                <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Mark order as fulfilled</DialogTitle>
                        <DialogDescription>Add tracking if you have it. The customer can be emailed.</DialogDescription>
                    </DialogHeader>

                    <form id="fulfil-form" class="space-y-5" @submit.prevent="fulfil">
                        <div class="grid gap-2">
                            <Label for="fulfil-carrier">Carrier <span class="text-muted-foreground">(optional)</span></Label>
                            <Input id="fulfil-carrier" v-model="fulfilForm.carrier" maxlength="100" placeholder="Royal Mail" />
                            <InputError :message="fulfilForm.errors.carrier" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="fulfil-number">Tracking number <span class="text-muted-foreground">(optional)</span></Label>
                            <Input id="fulfil-number" v-model="fulfilForm.tracking_number" maxlength="100" />
                            <InputError :message="fulfilForm.errors.tracking_number" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="fulfil-url">Tracking link <span class="text-muted-foreground">(optional)</span></Label>
                            <Input
                                id="fulfil-url"
                                v-model="fulfilForm.tracking_url"
                                type="url"
                                maxlength="500"
                                placeholder="https://"
                                :aria-invalid="!!fulfilForm.errors.tracking_url || undefined"
                            />
                            <InputError :message="fulfilForm.errors.tracking_url" />
                        </div>

                        <div class="flex items-center gap-3">
                            <Switch id="fulfil-notify" v-model="fulfilForm.notify_customer" />
                            <Label for="fulfil-notify" class="font-normal">Email the customer</Label>
                        </div>
                    </form>

                    <DialogFooter>
                        <Button type="button" variant="outline" @click="fulfilOpen = false">Cancel</Button>
                        <Button type="submit" form="fulfil-form" :disabled="fulfilForm.processing">Mark as fulfilled</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog v-model:open="refundOpen">
                <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Refund order {{ props.order.number }}</DialogTitle>
                        <DialogDescription>
                            Up to {{ money(props.refunding.refundable) }} can still be refunded.
                            <template v-if="props.refunding.via_provider && !refundForm.manual"
                                >The money goes back to the customer's original payment method through {{ props.refunding.provider_label }}. This
                                cannot be undone.</template
                            >
                        </DialogDescription>
                    </DialogHeader>

                    <form id="refund-form" class="space-y-5" @submit.prevent="refund">
                        <div class="grid gap-2">
                            <Label for="refund-amount">Amount ({{ props.order.currency }})</Label>
                            <div class="flex items-center gap-2">
                                <Input
                                    id="refund-amount"
                                    v-model="refundForm.amount"
                                    inputmode="decimal"
                                    class="w-40"
                                    :aria-invalid="!!refundForm.errors.amount || undefined"
                                />
                                <Button type="button" variant="outline" size="sm" @click="refundForm.amount = props.refunding.refundable">
                                    Everything left
                                </Button>
                            </div>
                            <InputError :message="refundForm.errors.amount" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="refund-reason">Reason</Label>
                            <select id="refund-reason" v-model="refundForm.reason" :class="selectClass">
                                <option v-for="reason in props.refunding.reasons" :key="reason.value" :value="reason.value">
                                    {{ reason.label }}
                                </option>
                            </select>
                            <InputError :message="refundForm.errors.reason" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="refund-note">Note <span class="text-muted-foreground">(optional, for the team)</span></Label>
                            <Textarea id="refund-note" v-model="refundForm.note" rows="2" maxlength="500" />
                            <InputError :message="refundForm.errors.note" />
                        </div>

                        <div class="flex items-start gap-3">
                            <Checkbox id="refund-restock" v-model="refundForm.restock" :disabled="!completesOrder" class="mt-0.5" />
                            <div class="grid gap-1">
                                <Label for="refund-restock" class="font-normal">Put the items back in stock</Label>
                                <p class="text-xs text-muted-foreground">Only possible when this refunds everything that is left.</p>
                                <InputError :message="refundForm.errors.restock" />
                            </div>
                        </div>

                        <div class="flex items-center gap-3">
                            <Switch id="refund-notify" v-model="refundForm.notify_customer" />
                            <Label for="refund-notify" class="font-normal">Email the customer</Label>
                        </div>

                        <div v-if="props.refunding.via_provider" class="flex items-start gap-3">
                            <Switch id="refund-manual" v-model="refundForm.manual" class="mt-0.5" />
                            <div class="grid gap-1">
                                <Label for="refund-manual" class="font-normal">I already returned the money another way</Label>
                                <p class="text-xs text-muted-foreground">
                                    Only records the refund (cash, a bank transfer); nothing is sent to {{ props.refunding.provider_label }}.
                                </p>
                            </div>
                        </div>
                        <Alert v-else>
                            <CircleAlert />
                            <AlertDescription>
                                This order's payment provider cannot send the refund from here, so this only records a refund you have already made.
                            </AlertDescription>
                        </Alert>
                    </form>

                    <DialogFooter>
                        <Button type="button" variant="outline" @click="refundOpen = false">Cancel</Button>
                        <Button type="submit" form="refund-form" :disabled="refundForm.processing">
                            {{ refundForm.manual ? 'Record refund' : 'Refund' }}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <ConfirmDialog
                :open="cancelOpen"
                title="Cancel this order?"
                description="It has not been paid. The stock it holds goes back on the shelf and the customer's checkout stops working."
                confirm-label="Cancel order"
                @update:open="(open) => (cancelOpen = open)"
                @confirm="cancelOrder"
                @cancel="cancelOpen = false"
            />
        </AdminLayout>
    </AppLayout>
</template>
