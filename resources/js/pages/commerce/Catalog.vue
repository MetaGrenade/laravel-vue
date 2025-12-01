<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { reactive, ref } from 'vue';

interface Category {
    id: number;
    name: string;
    slug: string;
}

interface Tag {
    id: number;
    name: string;
    slug: string;
}

interface Brand {
    id: number;
    name: string;
    slug: string;
}

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
    brand?: Brand | null;
    categories: Category[];
    tags: Tag[];
}

interface Props {
    products: {
        data: Product[];
    };
    filters: {
        search: string | null;
        category: number[];
        tags: number[];
        brand: number | null;
    };
    categories: Category[];
    tags: Tag[];
    brands: Brand[];
}

const props = defineProps<Props>();

const selectedVariants = reactive<Record<number, number | null>>({});
const quantities = reactive<Record<number, number>>({});
const submittingProductId = ref<number | null>(null);
const filterState = reactive({
    search: props.filters.search ?? '',
    category: props.filters.category?.[0] ?? '',
    tags: [...(props.filters.tags ?? [])],
    brand: props.filters.brand ?? '',
});

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

const applyFilters = () => {
    router.get(
        route('shop.index'),
        {
            search: filterState.search || undefined,
            category: filterState.category ? [Number(filterState.category)] : undefined,
            tags: filterState.tags.length ? filterState.tags : undefined,
            brand: filterState.brand || undefined,
        },
        {
            preserveScroll: true,
            replace: true,
        },
    );
};

const clearFilters = () => {
    filterState.search = '';
    filterState.category = '';
    filterState.tags = [];
    filterState.brand = '';
    applyFilters();
};

const toggleTag = (tagId: number) => {
    if (filterState.tags.includes(tagId)) {
        filterState.tags = filterState.tags.filter((id) => id !== tagId);
    } else {
        filterState.tags.push(tagId);
    }
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

            <Card>
                <CardHeader>
                    <CardTitle>Filter products</CardTitle>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="grid gap-4 md:grid-cols-4">
                        <div class="space-y-2">
                            <label class="text-sm font-semibold text-foreground" for="search">Search</label>
                            <Input
                                id="search"
                                v-model="filterState.search"
                                placeholder="Search by name or description"
                                @keyup.enter="applyFilters"
                            />
                        </div>

                        <div class="space-y-2">
                            <label class="text-sm font-semibold text-foreground" for="category">Category</label>
                            <select
                                id="category"
                                v-model="filterState.category"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:border-primary focus:outline-none"
                            >
                                <option value="">All categories</option>
                                <option v-for="category in props.categories" :key="category.id" :value="category.id">
                                    {{ category.name }}
                                </option>
                            </select>
                        </div>

                        <div class="space-y-2">
                            <label class="text-sm font-semibold text-foreground" for="brand">Brand</label>
                            <select
                                id="brand"
                                v-model="filterState.brand"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:border-primary focus:outline-none"
                            >
                                <option value="">All brands</option>
                                <option v-for="brand in props.brands" :key="brand.id" :value="brand.id">
                                    {{ brand.name }}
                                </option>
                            </select>
                        </div>

                        <div class="space-y-2">
                            <label class="text-sm font-semibold text-foreground">Tags</label>
                            <div class="flex flex-wrap gap-2">
                                <Button
                                    v-for="tag in props.tags"
                                    :key="tag.id"
                                    size="sm"
                                    variant="outline"
                                    :class="filterState.tags.includes(tag.id) ? 'border-primary text-primary' : ''"
                                    @click="toggleTag(tag.id)">
                                    {{ tag.name }}
                                </Button>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <Button @click="applyFilters">Apply filters</Button>
                        <Button variant="ghost" @click="clearFilters">Reset</Button>
                    </div>
                </CardContent>
            </Card>

            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <Card v-for="product in props.products.data" :key="product.id" class="flex flex-col">
                    <CardHeader>
                        <CardTitle class="text-xl">{{ product.name }}</CardTitle>
                        <div v-if="product.brand" class="flex items-center gap-2 text-sm text-muted-foreground">
                            <Badge variant="outline">{{ product.brand.name }}</Badge>
                        </div>
                        <p class="text-sm text-muted-foreground line-clamp-2">{{ product.description || 'No description yet.' }}</p>
                    </CardHeader>
                    <CardContent class="flex flex-1 flex-col justify-between space-y-4">
                        <div class="space-y-3">
                            <div class="space-y-2">
                                <div class="font-medium">Variants: {{ product.variants.length }}</div>
                                <div class="text-sm text-muted-foreground">
                                    {{ getPriceRangeLabel(product) }}
                                </div>
                                <div class="text-sm font-semibold text-foreground">
                                    {{ getProductPriceLabel(product) }}
                                </div>
                            </div>

                            <div class="flex flex-wrap gap-2">
                                <Badge v-for="category in product.categories" :key="category.id" variant="secondary">
                                    {{ category.name }}
                                </Badge>
                                <Badge v-for="tag in product.tags" :key="tag.id" variant="outline">
                                    {{ tag.name }}
                                </Badge>
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
