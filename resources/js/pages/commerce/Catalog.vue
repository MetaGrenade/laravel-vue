<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';

interface Variant {
    id: number;
    name: string;
    sku?: string | null;
}

interface Price {
    id: number;
    currency: string;
    amount: string;
    compare_at_amount?: string | null;
}

interface Product {
    id: number;
    name: string;
    slug: string;
    description?: string | null;
    variants: Variant[];
    prices: Price[];
}

interface Props {
    products: {
        data: Product[];
    };
}

const props = defineProps<Props>();
</script>

<template>
    <AppLayout>
        <Head title="Shop" />

        <div class="space-y-6">
            <div>
                <h1 class="text-3xl font-bold tracking-tight">Shop</h1>
                <p class="text-muted-foreground">Starter catalog page teams can extend into a full storefront.</p>
            </div>

            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <Card v-for="product in props.products.data" :key="product.id" class="flex flex-col">
                    <CardHeader>
                        <CardTitle class="text-xl">{{ product.name }}</CardTitle>
                        <p class="text-sm text-muted-foreground line-clamp-2">{{ product.description || 'No description yet.' }}</p>
                    </CardHeader>
                    <CardContent class="flex flex-1 flex-col justify-between space-y-4">
                        <div class="space-y-1">
                            <div class="font-medium">Variants: {{ product.variants.length }}</div>
                            <div class="text-sm text-muted-foreground">
                                {{ product.prices.length ? 'Pricing configured' : 'Add pricing to this item' }}
                            </div>
                        </div>

                        <div class="flex items-center justify-between">
                            <Link :href="route('shop.products.show', product.slug)">
                                <Button variant="secondary">View details</Button>
                            </Link>
                            <Button variant="outline" disabled>Add to cart</Button>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
