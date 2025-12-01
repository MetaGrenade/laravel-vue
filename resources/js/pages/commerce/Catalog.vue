<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { reactive, ref } from 'vue';

interface Variant {
    id: number;
    name: string;
    sku?: string | null;
    prices: Price[];
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

const selectedVariants = reactive<Record<number, number | null>>({});
const quantities = reactive<Record<number, number>>({});
const submittingProductId = ref<number | null>(null);

const getSelectedVariantId = (product: Product) => {
    if (selectedVariants[product.id] === undefined) {
        selectedVariants[product.id] = product.variants[0]?.id ?? null;
    }

    return selectedVariants[product.id];
};

const getQuantity = (productId: number) => quantities[productId] ?? 1;

const setQuantity = (productId: number, value: number) => {
    const nextValue = Number.isFinite(value) ? value : 1;

    quantities[productId] = Math.max(1, nextValue);
};

const canAddToCart = (product: Product) => {
    const hasPricing = product.prices.length > 0 || product.variants.some((variant) => variant.prices.length);

    return hasPricing;
};

const addToCart = (product: Product) => {
    if (!canAddToCart(product)) {
        return;
    }

    submittingProductId.value = product.id;

    router.post(
        route('shop.cart.items.store'),
        {
            product_id: product.id,
            product_variant_id: getSelectedVariantId(product),
            quantity: getQuantity(product.id),
        },
        {
            preserveScroll: true,
            onFinish: () => {
                submittingProductId.value = null;
            },
        },
    );
};

const formatCurrency = (amount: number, currency: string) => {
    return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: currency.toUpperCase(),
    }).format(amount);
};

const getPriceRangeLabel = (product: Product) => {
    const allPrices = [
        ...product.prices,
        ...product.variants.flatMap((variant) => variant.prices || []),
    ];

    if (!allPrices.length) {
        return 'Add pricing to this item';
    }

    const amounts = allPrices.map((price) => ({
        amount: Number(price.amount),
        currency: price.currency || 'USD',
    }));

    const minAmount = Math.min(...amounts.map((price) => price.amount));
    const maxAmount = Math.max(...amounts.map((price) => price.amount));
    const currency = amounts[0].currency;

    if (minAmount === maxAmount) {
        return formatCurrency(minAmount, currency);
    }

    return `${formatCurrency(minAmount, currency)} - ${formatCurrency(maxAmount, currency)}`;
};

const getProductPriceLabel = (product: Product) => {
    const selectionId = getSelectedVariantId(product);

    const selectedVariant = product.variants.find((variant) => variant.id === selectionId);
    const price = selectedVariant?.prices[0] ?? product.prices[0];

    if (!price) {
        return 'Pricing pending';
    }

    return formatCurrency(Number(price.amount), price.currency);
};
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
                        <div class="space-y-2">
                            <div class="font-medium">Variants: {{ product.variants.length }}</div>
                            <div class="text-sm text-muted-foreground">
                                {{ getPriceRangeLabel(product) }}
                            </div>
                            <div class="text-sm font-semibold text-foreground">
                                {{ getProductPriceLabel(product) }}
                            </div>
                        </div>

                        <div class="space-y-3">
                            <div v-if="product.variants.length" class="space-y-1">
                                <label class="text-sm font-semibold text-foreground">Select variant</label>
                                <select
                                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:border-primary focus:outline-none"
                                    :value="getSelectedVariantId(product) ?? ''"
                                    @change="selectedVariants[product.id] = ($event.target as HTMLSelectElement).value ? Number(($event.target as HTMLSelectElement).value) : null"
                                >
                                    <option v-for="variant in product.variants" :key="variant.id" :value="variant.id">
                                        {{ variant.name }}
                                    </option>
                                </select>
                            </div>

                            <div class="space-y-1">
                                <label class="text-sm font-semibold text-foreground" :for="`quantity-${product.id}`">Quantity</label>
                                <Input
                                    :id="`quantity-${product.id}`"
                                    type="number"
                                    min="1"
                                    class="w-full"
                                    :value="getQuantity(product.id)"
                                    @input="setQuantity(product.id, Number(($event.target as HTMLInputElement).value))"
                                />
                            </div>

                            <div class="flex items-center justify-between gap-3">
                                <Link :href="route('shop.products.show', product.slug)">
                                    <Button variant="secondary">View details</Button>
                                </Link>
                                <Button
                                    class="flex-1"
                                    variant="outline"
                                    :disabled="!canAddToCart(product) || submittingProductId === product.id"
                                    @click="addToCart(product)"
                                >
                                    {{ submittingProductId === product.id ? 'Adding…' : 'Add to cart' }}
                                </Button>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
