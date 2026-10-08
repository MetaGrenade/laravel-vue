<script setup lang="ts">
import AddressFields from '@/components/commerce/AddressFields.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/AppLayout.vue';
import { addressLines, emptyAddress } from '@/lib/address';
import { formatMoney } from '@/lib/money';
import type { CartSummary } from '@/types';
import type { CheckoutQuote, SavedAddress } from '@/types/commerce';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { LoaderCircle, Lock } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, watch } from 'vue';

interface Props {
    cart: CartSummary;
    customer: { email: string | null; name: string | null };
    isGuest: boolean;
    provider: string;
    available: boolean;
    token: string;
    needsShipping: boolean;
    billingRequired: boolean;
    /** Countries an order can be shipped to (limited by the shipping zones). */
    countries: string[];
    /** Countries a billing address can be in: all of them. */
    billingCountries: string[];
    addresses: SavedAddress[];
    quote: CheckoutQuote;
}

const props = defineProps<Props>();

const defaultSaved = props.addresses.find((address) => address.is_default) ?? props.addresses[0] ?? null;
const onlyCountry = props.countries.length === 1 ? props.countries[0] : '';

const form = useForm({
    email: props.customer.email ?? '',
    name: props.customer.name ?? '',
    token: props.token,
    // A saved address picked from the address book, or null to type a new one.
    shipping_address_id: (defaultSaved?.id ?? null) as number | null,
    shipping_address: emptyAddress(onlyCountry),
    billing_same_as_shipping: true,
    billing_address_id: null as number | null,
    billing_address: emptyAddress(),
    shipping_rate_id: null as number | null,
    save_addresses: false,
});

const errors = computed(() => form.errors as Record<string, string | undefined>);
const currency = computed(() => props.quote.currency ?? props.cart.currency);
const money = (amount: string | undefined) => formatMoney(amount ?? '0', currency.value);

const savedById = (id: number | null) => props.addresses.find((address) => address.id === id) ?? null;

/** Billing is its own address when asked for (digital goods) or when the shopper says it differs. */
const billingIsSeparate = computed(() => (props.needsShipping ? !form.billing_same_as_shipping : props.billingRequired));

// An address is only worth offering to save when the shopper is typing it in, rather than picking a saved one.
const typesShippingAddress = computed(() => props.needsShipping && !form.shipping_address_id);
const typesBillingAddress = computed(() => billingIsSeparate.value && !form.billing_address_id);
const savesAnAddress = computed(() => typesShippingAddress.value || typesBillingAddress.value);

const shippingPlace = computed(() => {
    const saved = savedById(form.shipping_address_id);

    return saved ?? form.shipping_address;
});

const billingPlace = computed(() => {
    const saved = savedById(form.billing_address_id);

    return saved ?? form.billing_address;
});

// Only the country and state matter for shipping and tax, so only they are sent for a quote.
const quoteParams = computed(() => {
    const params: Record<string, string | number> = {};

    if (props.needsShipping) {
        params.ship_country = shippingPlace.value.country;
        params.ship_region = shippingPlace.value.region;

        if (form.shipping_rate_id) {
            params.rate = form.shipping_rate_id;
        }
    }

    if (billingIsSeparate.value) {
        params.bill_country = billingPlace.value.country;
        params.bill_region = billingPlace.value.region;
    }

    // The shipping country is always sent (empty while a new address is being typed), so the server
    // can tell "nothing entered yet" from a first visit that should use the pre-selected saved address.
    return Object.fromEntries(Object.entries(params).filter(([key, value]) => key === 'ship_country' || (value !== '' && value !== undefined)));
});

// The server picks the first method when none (or an unavailable one) is chosen; follow it.
watch(
    () => props.quote.shipping?.selected_id ?? null,
    (id) => {
        form.shipping_rate_id = id;
    },
    { immediate: true },
);

const QUOTE_KEYS = ['ship_country', 'ship_region', 'bill_country', 'bill_region', 'rate'];

/** The same params as a sorted string, whether they came from the form or from the URL. */
const keyOf = (params: Record<string, string | number | null | undefined>) =>
    JSON.stringify(
        QUOTE_KEYS.filter((key) => params[key] !== undefined && params[key] !== null)
            .sort()
            .map((key) => [key, String(params[key])]),
    );

// What the quote on screen was last worked out for, so a change that alters nothing sends nothing.
let quotedFor = keyOf(quoteParams.value);

let timer: ReturnType<typeof setTimeout> | undefined;

const requestQuote = () => {
    quotedFor = keyOf(quoteParams.value);
    router.get(route('shop.checkout'), quoteParams.value, { only: ['quote'], preserveState: true, preserveScroll: true, replace: true });
};

watch(
    quoteParams,
    () => {
        clearTimeout(timer);

        if (keyOf(quoteParams.value) === quotedFor) {
            return;
        }

        timer = setTimeout(requestQuote, 300);
    },
    { deep: true },
);

// A page reloaded from a URL that names a destination shows that quote, which may not be the
// one for what the form now holds (it starts from the saved address, not the URL). Re-sync it.
onMounted(() => {
    const fromUrl = Object.fromEntries(new URLSearchParams(window.location.search));

    if (QUOTE_KEYS.some((key) => key in fromUrl) && keyOf(fromUrl) !== keyOf(quoteParams.value)) {
        requestQuote();
    }
});

onBeforeUnmount(() => clearTimeout(timer));
const regionRequired = computed(() => Boolean(props.quote.tax?.region_required));

const cannotShip = computed(() => props.needsShipping && props.quote.shipping?.can_ship === false);
const canPay = computed(() => props.available && !cannotShip.value && !props.quote.error);

const submit = () => {
    form.transform((data) => {
        const payload: Record<string, unknown> = {
            email: data.email,
            name: data.name,
            token: data.token,
            save_addresses: data.save_addresses && savesAnAddress.value,
        };

        if (props.needsShipping) {
            if (data.shipping_address_id) {
                payload.shipping_address_id = data.shipping_address_id;
            } else {
                payload.shipping_address = data.shipping_address;
            }

            payload.shipping_rate_id = data.shipping_rate_id;
            payload.billing_same_as_shipping = data.billing_same_as_shipping;
        }

        if (billingIsSeparate.value) {
            if (data.billing_address_id) {
                payload.billing_address_id = data.billing_address_id;
            } else {
                payload.billing_address = data.billing_address;
            }
        }

        return payload;
    }).post(route('shop.checkout.store'), { preserveScroll: true });
};
</script>

<template>
    <AppLayout>
        <Head title="Checkout" />

        <div class="space-y-6">
            <div>
                <p class="text-sm text-muted-foreground uppercase">Checkout</p>
                <h1 class="text-3xl font-bold tracking-tight">Complete your order</h1>
            </div>

            <form class="grid gap-6 lg:grid-cols-[1fr_24rem]" @submit.prevent="submit">
                <div class="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Contact</CardTitle>
                        </CardHeader>
                        <CardContent class="space-y-5">
                            <div class="grid gap-2">
                                <Label for="email">Email address</Label>
                                <Input
                                    id="email"
                                    v-model="form.email"
                                    type="email"
                                    autocomplete="email"
                                    :readonly="!props.isGuest"
                                    :required="props.isGuest"
                                    placeholder="email@example.com"
                                />
                                <p class="text-xs text-muted-foreground">
                                    <template v-if="props.isGuest"
                                        >We'll send your receipt here. Have an account?
                                        <Link :href="route('login')" class="underline">Log in</Link>.</template
                                    >
                                    <template v-else>Your receipt is sent to your account email.</template>
                                </p>
                                <InputError :message="form.errors.email" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="name">Name <span class="text-muted-foreground">(optional)</span></Label>
                                <Input id="name" v-model="form.name" type="text" autocomplete="name" />
                                <InputError :message="form.errors.name" />
                            </div>
                        </CardContent>
                    </Card>

                    <Card v-if="props.needsShipping">
                        <CardHeader>
                            <CardTitle>Shipping address</CardTitle>
                        </CardHeader>
                        <CardContent class="space-y-5">
                            <fieldset v-if="props.addresses.length" class="space-y-2">
                                <legend class="sr-only">Choose a saved address</legend>
                                <label
                                    v-for="address in props.addresses"
                                    :key="address.id"
                                    class="flex cursor-pointer items-start gap-3 rounded-md border p-3 text-sm has-[:checked]:border-primary has-[:checked]:bg-primary/5"
                                >
                                    <input
                                        v-model="form.shipping_address_id"
                                        type="radio"
                                        name="shipping_address_choice"
                                        :value="address.id"
                                        class="mt-1 accent-primary"
                                    />
                                    <span>
                                        <span v-if="address.label" class="font-medium">{{ address.label }}</span>
                                        <span v-for="(line, index) in addressLines(address)" :key="index" class="block text-muted-foreground">{{
                                            line
                                        }}</span>
                                    </span>
                                </label>
                                <label
                                    class="flex cursor-pointer items-center gap-3 rounded-md border p-3 text-sm has-[:checked]:border-primary has-[:checked]:bg-primary/5"
                                >
                                    <input
                                        v-model="form.shipping_address_id"
                                        type="radio"
                                        name="shipping_address_choice"
                                        :value="null"
                                        class="accent-primary"
                                    />
                                    <span>Use a different address</span>
                                </label>
                            </fieldset>
                            <InputError :message="form.errors.shipping_address_id" />

                            <template v-if="!form.shipping_address_id">
                                <AddressFields
                                    v-model="form.shipping_address"
                                    :countries="props.countries"
                                    :errors="errors"
                                    error-prefix="shipping_address"
                                    id-prefix="shipping"
                                    :region-required="regionRequired"
                                />
                                <InputError :message="form.errors.shipping_address" />
                            </template>
                        </CardContent>
                    </Card>

                    <Card v-if="props.needsShipping">
                        <CardHeader>
                            <CardTitle>Shipping method</CardTitle>
                        </CardHeader>
                        <CardContent class="space-y-3">
                            <p
                                v-if="props.quote.shipping?.message"
                                class="text-sm"
                                :class="cannotShip ? 'text-destructive' : 'text-muted-foreground'"
                            >
                                {{ props.quote.shipping.message }}
                            </p>

                            <fieldset v-if="props.quote.shipping?.options.length" class="space-y-2">
                                <legend class="sr-only">Choose a shipping method</legend>
                                <label
                                    v-for="option in props.quote.shipping.options"
                                    :key="option.id"
                                    class="flex cursor-pointer items-center justify-between gap-3 rounded-md border p-3 text-sm has-[:checked]:border-primary has-[:checked]:bg-primary/5"
                                >
                                    <span class="flex items-start gap-3">
                                        <input
                                            v-model="form.shipping_rate_id"
                                            type="radio"
                                            name="shipping_rate"
                                            :value="option.id"
                                            class="mt-1 accent-primary"
                                        />
                                        <span>
                                            <span class="block font-medium">{{ option.name }}</span>
                                            <span v-if="option.description" class="block text-muted-foreground">{{ option.description }}</span>
                                        </span>
                                    </span>
                                    <span class="font-medium tabular-nums">{{ Number(option.amount) === 0 ? 'Free' : money(option.amount) }}</span>
                                </label>
                            </fieldset>
                            <p v-else-if="!props.quote.shipping?.message" class="text-sm text-muted-foreground">
                                Shipping is arranged after you order.
                            </p>
                            <InputError :message="form.errors.shipping_rate_id" />
                        </CardContent>
                    </Card>

                    <Card v-if="props.needsShipping || props.billingRequired">
                        <CardHeader>
                            <CardTitle>Billing address</CardTitle>
                        </CardHeader>
                        <CardContent class="space-y-5">
                            <div v-if="props.needsShipping" class="flex items-center gap-2">
                                <Checkbox id="billing_same" v-model="form.billing_same_as_shipping" />
                                <Label for="billing_same" class="font-normal">Same as the shipping address</Label>
                            </div>
                            <p v-else class="text-sm text-muted-foreground">
                                Digital items are not shipped, but we need a billing address to work out tax.
                            </p>

                            <template v-if="billingIsSeparate">
                                <fieldset v-if="props.addresses.length" class="space-y-2">
                                    <legend class="sr-only">Choose a saved address</legend>
                                    <label
                                        v-for="address in props.addresses"
                                        :key="address.id"
                                        class="flex cursor-pointer items-start gap-3 rounded-md border p-3 text-sm has-[:checked]:border-primary has-[:checked]:bg-primary/5"
                                    >
                                        <input
                                            v-model="form.billing_address_id"
                                            type="radio"
                                            name="billing_address_choice"
                                            :value="address.id"
                                            class="mt-1 accent-primary"
                                        />
                                        <span>
                                            <span v-if="address.label" class="font-medium">{{ address.label }}</span>
                                            <span v-for="(line, index) in addressLines(address)" :key="index" class="block text-muted-foreground">{{
                                                line
                                            }}</span>
                                        </span>
                                    </label>
                                    <label
                                        class="flex cursor-pointer items-center gap-3 rounded-md border p-3 text-sm has-[:checked]:border-primary has-[:checked]:bg-primary/5"
                                    >
                                        <input
                                            v-model="form.billing_address_id"
                                            type="radio"
                                            name="billing_address_choice"
                                            :value="null"
                                            class="accent-primary"
                                        />
                                        <span>Use a different address</span>
                                    </label>
                                </fieldset>
                                <InputError :message="form.errors.billing_address_id" />

                                <AddressFields
                                    v-if="!form.billing_address_id"
                                    v-model="form.billing_address"
                                    :countries="props.billingCountries"
                                    :errors="errors"
                                    error-prefix="billing_address"
                                    id-prefix="billing"
                                    :region-required="regionRequired && !props.needsShipping"
                                />
                                <InputError :message="form.errors.billing_address" />
                            </template>
                        </CardContent>
                    </Card>

                    <div v-if="!props.isGuest && savesAnAddress" class="flex items-center gap-2">
                        <Checkbox id="save_addresses" v-model="form.save_addresses" />
                        <Label for="save_addresses" class="font-normal">
                            {{
                                typesShippingAddress && typesBillingAddress ? 'Save these addresses for next time' : 'Save this address for next time'
                            }}
                        </Label>
                    </div>

                    <div class="space-y-3">
                        <p v-if="props.quote.error" class="text-sm text-destructive">{{ props.quote.error }}</p>
                        <Button type="submit" size="lg" class="w-full" :disabled="form.processing || !canPay">
                            <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
                            <Lock v-else class="size-4" />
                            {{ form.processing ? 'Redirecting…' : `Pay ${money(props.quote.grand_total ?? props.cart.subtotal)}` }}
                        </Button>
                        <p v-if="props.available" class="text-center text-xs text-muted-foreground">
                            You'll pay securely on {{ props.provider }}'s page. We never see your card details.
                        </p>
                        <p v-else class="text-center text-sm text-destructive">Checkout isn't available right now. Please try again later.</p>
                    </div>
                </div>

                <Card class="h-fit lg:sticky lg:top-24">
                    <CardHeader>
                        <CardTitle>Order summary</CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <ul class="space-y-3 text-sm">
                            <li v-for="item in props.cart.items" :key="item.id" class="flex justify-between gap-3">
                                <span class="min-w-0">
                                    <span class="block truncate font-medium">{{ item.name }}</span>
                                    <span class="text-muted-foreground">
                                        <template v-if="item.variant">{{ item.variant }} · </template>Qty {{ item.quantity }}
                                    </span>
                                </span>
                                <span class="tabular-nums">{{ formatMoney(item.total, props.cart.currency) }}</span>
                            </li>
                        </ul>
                        <Separator />
                        <dl class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <dt class="text-muted-foreground">Subtotal</dt>
                                <dd class="tabular-nums">{{ money(props.quote.subtotal ?? props.cart.subtotal) }}</dd>
                            </div>
                            <div v-if="props.needsShipping" class="flex justify-between">
                                <dt class="text-muted-foreground">Shipping</dt>
                                <dd class="tabular-nums">
                                    <template v-if="props.quote.shipping?.options.length">{{
                                        Number(props.quote.shipping.amount) === 0 ? 'Free' : money(props.quote.shipping.amount)
                                    }}</template>
                                    <span v-else class="text-muted-foreground">{{ cannotShip ? 'Unavailable' : 'Enter address' }}</span>
                                </dd>
                            </div>
                            <div v-for="line in props.quote.tax?.lines ?? []" :key="line.name" class="flex justify-between">
                                <dt class="text-muted-foreground">{{ line.name }} ({{ line.rate }}%)</dt>
                                <dd class="tabular-nums">{{ money(line.amount) }}</dd>
                            </div>
                        </dl>
                        <Separator />
                        <div class="flex justify-between text-base font-semibold">
                            <span>Total</span>
                            <span class="tabular-nums">{{ money(props.quote.grand_total ?? props.cart.subtotal) }}</span>
                        </div>
                        <Link :href="route('shop.cart')" class="block text-center text-sm text-muted-foreground underline">Edit cart</Link>
                    </CardContent>
                </Card>
            </form>
        </div>
    </AppLayout>
</template>
