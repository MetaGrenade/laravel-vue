<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { Card, CardContent, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';

interface Product {
    id: number;
    name: string;
}

interface Variant {
    id: number;
    name: string;
    sku?: string | null;
}

interface CartItem {
    id: number;
    quantity: number;
    unit_price: string;
    total: string;
    product?: Product | null;
    variant?: Variant | null;
}

interface Cart {
    id: number;
    status: string;
    currency: string;
    subtotal: string;
    items: CartItem[];
}

interface Props {
    cart: Cart | null;
}

const props = defineProps<Props>();
</script>

<template>
    <AppLayout>
        <Head title="Cart" />

        <div class="space-y-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-muted-foreground uppercase">Cart</p>
                    <h1 class="text-3xl font-bold tracking-tight">Your cart</h1>
                    <p class="text-muted-foreground">Stubbed cart view wired for future checkout.</p>
                </div>
                <Button variant="secondary" disabled>Proceed to checkout</Button>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Line items</CardTitle>
                </CardHeader>
                <CardContent>
                    <div v-if="props.cart && props.cart.items.length" class="space-y-4">
                        <div v-for="item in props.cart.items" :key="item.id" class="rounded border p-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="font-medium">{{ item.product?.name || 'Product' }}</div>
                                    <p class="text-sm text-muted-foreground">{{ item.variant?.name || 'Base product' }}</p>
                                    <p class="text-sm text-muted-foreground">Qty: {{ item.quantity }}</p>
                                </div>
                                <div class="text-right">
                                    <div class="font-semibold">{{ item.total }} {{ props.cart.currency }}</div>
                                    <p class="text-sm text-muted-foreground">{{ item.unit_price }} each</p>
                                </div>
                            </div>
                            <div class="mt-3 flex items-center justify-end gap-2">
                                <Button variant="outline" size="sm" disabled>Update</Button>
                                <Button variant="ghost" size="sm" disabled>Remove</Button>
                            </div>
                        </div>
                    </div>
                    <p v-else class="text-sm text-muted-foreground">No items yet. Connect add-to-cart actions to populate this view.</p>
                </CardContent>
                <Separator />
                <CardFooter class="flex items-center justify-between">
                    <div class="text-sm text-muted-foreground">Status: {{ props.cart?.status || 'open' }}</div>
                    <div class="text-right">
                        <div class="text-sm text-muted-foreground">Subtotal</div>
                        <div class="text-2xl font-bold">{{ props.cart?.subtotal || '0.00' }} {{ props.cart?.currency || 'USD' }}</div>
                    </div>
                </CardFooter>
            </Card>

            <div class="flex justify-end">
                <Link :href="route('shop.index')">
                    <Button variant="link">Continue shopping</Button>
                </Link>
            </div>
        </div>
    </AppLayout>
</template>
