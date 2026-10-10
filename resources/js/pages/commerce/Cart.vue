<script setup lang="ts">
import CouponCode from '@/components/commerce/CouponCode.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatMoney } from '@/lib/money';
import type { CartSummary } from '@/types';
import type { CartCoupon } from '@/types/commerce';
import { Head, Link, router } from '@inertiajs/vue3';
import { ImageOff, Trash2 } from '@lucide/vue';
import { ref } from 'vue';

interface Props {
    cart: CartSummary | null;
    /** The discount code on the cart, with what it takes off the items now. */
    coupon: CartCoupon | null;
    checkoutAvailable: boolean;
    maxQuantity: number;
}

const props = defineProps<Props>();

const busyItemId = ref<number | null>(null);

const changeQuantity = (itemId: number, value: number) => {
    const quantity = Math.min(props.maxQuantity, Math.max(1, Math.trunc(Number.isFinite(value) ? value : 1)));

    busyItemId.value = itemId;
    router.patch(route('shop.cart.items.update', itemId), { quantity }, { preserveScroll: true, onFinish: () => (busyItemId.value = null) });
};

const removeItem = (itemId: number) => {
    busyItemId.value = itemId;
    router.delete(route('shop.cart.items.destroy', itemId), { preserveScroll: true, onFinish: () => (busyItemId.value = null) });
};
</script>

<template>
    <AppLayout>
        <Head title="Cart" />

        <div class="space-y-6">
            <div>
                <p class="text-sm text-muted-foreground uppercase">Cart</p>
                <h1 class="text-3xl font-bold tracking-tight">Your cart</h1>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Items</CardTitle>
                </CardHeader>
                <CardContent>
                    <ul v-if="props.cart && props.cart.items.length" class="divide-y">
                        <li
                            v-for="item in props.cart.items"
                            :key="item.id"
                            class="flex flex-wrap items-center justify-between gap-4 py-4 first:pt-0 last:pb-0"
                        >
                            <div class="flex min-w-0 items-center gap-3">
                                <img v-if="item.image" :src="item.image" alt="" class="size-16 shrink-0 rounded-md border object-cover" />
                                <span
                                    v-else
                                    class="flex size-16 shrink-0 items-center justify-center rounded-md border border-dashed text-muted-foreground"
                                    aria-hidden="true"
                                >
                                    <ImageOff class="size-5" />
                                </span>
                                <div class="min-w-0">
                                    <Link v-if="item.slug" :href="route('shop.products.show', item.slug)" class="font-medium hover:underline">{{
                                        item.name
                                    }}</Link>
                                    <span v-else class="font-medium">{{ item.name }}</span>
                                    <p v-if="item.variant" class="text-sm text-muted-foreground">{{ item.variant }}</p>
                                    <p class="text-sm text-muted-foreground tabular-nums">
                                        {{ formatMoney(item.unit_price, props.cart.currency) }} each
                                    </p>
                                </div>
                            </div>

                            <div class="flex items-center gap-3">
                                <label class="sr-only" :for="`quantity-${item.id}`">Quantity for {{ item.name }}</label>
                                <Input
                                    :id="`quantity-${item.id}`"
                                    type="number"
                                    inputmode="numeric"
                                    min="1"
                                    :max="props.maxQuantity"
                                    class="w-20"
                                    :model-value="item.quantity"
                                    :disabled="busyItemId === item.id"
                                    @change="changeQuantity(item.id, Number(($event.target as HTMLInputElement).value))"
                                />
                                <div class="w-24 text-right font-semibold tabular-nums">{{ formatMoney(item.total, props.cart.currency) }}</div>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="`Remove ${item.name}`"
                                    :disabled="busyItemId === item.id"
                                    @click="removeItem(item.id)"
                                >
                                    <Trash2 class="size-4" />
                                </Button>
                            </div>
                        </li>
                    </ul>
                    <div v-else class="space-y-3 py-6 text-center">
                        <p class="text-muted-foreground">Your cart is empty.</p>
                        <Button as-child>
                            <Link :href="route('shop.index')">Browse the shop</Link>
                        </Button>
                    </div>
                </CardContent>

                <template v-if="props.cart && props.cart.items.length">
                    <Separator />
                    <CardContent class="max-w-md">
                        <CouponCode :coupon="props.coupon" :currency="props.cart.currency" :discount="props.coupon?.discount" />
                    </CardContent>
                    <Separator />
                    <CardFooter class="flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <div class="text-sm text-muted-foreground">Subtotal</div>
                            <div class="text-2xl font-bold tabular-nums">{{ formatMoney(props.cart.subtotal, props.cart.currency) }}</div>
                            <p v-if="props.coupon?.applied && Number(props.coupon.discount) > 0" class="mt-1 text-sm">
                                Discount ({{ props.coupon.code }}):
                                <span class="font-medium tabular-nums">−{{ formatMoney(props.coupon.discount ?? '0', props.cart.currency) }}</span>
                            </p>
                            <p class="mt-1 text-xs text-muted-foreground">Shipping, tax and the final total are confirmed at checkout.</p>
                        </div>
                        <div class="flex flex-col items-end gap-2">
                            <Button v-if="props.checkoutAvailable" as-child size="lg">
                                <Link :href="route('shop.checkout')">Proceed to checkout</Link>
                            </Button>
                            <Button v-else size="lg" disabled>Checkout unavailable</Button>
                            <p v-if="!props.checkoutAvailable" class="text-xs text-muted-foreground">
                                Payments are not set up yet. Please check back soon.
                            </p>
                        </div>
                    </CardFooter>
                </template>
            </Card>

            <div class="flex justify-end">
                <Link :href="route('shop.index')">
                    <Button variant="link">Continue shopping</Button>
                </Link>
            </div>
        </div>
    </AppLayout>
</template>
