<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatMoney } from '@/lib/money';
import type { CartSummary } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { LoaderCircle, Lock } from '@lucide/vue';

interface Props {
    cart: CartSummary;
    customer: { email: string | null; name: string | null };
    isGuest: boolean;
    provider: string;
    available: boolean;
    token: string;
}

const props = defineProps<Props>();

const form = useForm({
    email: props.customer.email ?? '',
    name: props.customer.name ?? '',
    token: props.token,
});

// The browser leaves for the payment provider's page when this succeeds, so the
// button stays busy until then; it is only released again if the server refuses.
const submit = () => {
    form.post(route('shop.checkout.store'), { preserveScroll: true });
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

            <div class="grid gap-6 lg:grid-cols-[1fr_22rem]">
                <Card>
                    <CardHeader>
                        <CardTitle>Your details</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form class="space-y-5" @submit.prevent="submit">
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

                            <InputError :message="form.errors.token" />

                            <div class="space-y-3 pt-2">
                                <Button type="submit" size="lg" class="w-full" :disabled="form.processing || !props.available">
                                    <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
                                    <Lock v-else class="size-4" />
                                    {{ form.processing ? 'Redirecting…' : `Pay ${formatMoney(props.cart.subtotal, props.cart.currency)}` }}
                                </Button>
                                <p v-if="props.available" class="text-center text-xs text-muted-foreground">
                                    You'll pay securely on {{ props.provider }}'s page. We never see your card details.
                                </p>
                                <p v-else class="text-center text-sm text-destructive">Checkout isn't available right now. Please try again later.</p>
                            </div>
                        </form>
                    </CardContent>
                </Card>

                <Card class="h-fit">
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
                        <div class="flex justify-between text-base font-semibold">
                            <span>Total</span>
                            <span class="tabular-nums">{{ formatMoney(props.cart.subtotal, props.cart.currency) }}</span>
                        </div>
                        <Link :href="route('shop.cart')" class="block text-center text-sm text-muted-foreground underline">Edit cart</Link>
                    </CardContent>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
