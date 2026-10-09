<script setup lang="ts">
import ProductPrice from '@/components/commerce/ProductPrice.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import type { StorefrontImage, StorefrontPrice, StorefrontVariant } from '@/types/commerce';
import { Head, Link, router } from '@inertiajs/vue3';
import { ImageOff } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';

interface Taxon {
    id: number;
    name: string;
    slug: string;
}

interface Product {
    id: number;
    name: string;
    slug: string;
    description?: string | null;
    /** Decided by the server with the same rules checkout uses, so the button is never on for something checkout would refuse. */
    can_buy: boolean;
    sold_out: boolean;
    image: StorefrontImage | null;
    variants: StorefrontVariant[];
    prices: StorefrontPrice[];
    brand?: Taxon | null;
    categories: Taxon[];
    tags: Taxon[];
}

interface Props {
    products: {
        data: Product[];
        current_page: number;
        last_page: number;
        total: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    filters: {
        search: string | null;
        category: number[];
        tags: number[];
        brand: number | null;
    };
    categories: Taxon[];
    tags: Taxon[];
    brands: Taxon[];
}

const props = defineProps<Props>();

const breadcrumbs = [{ title: 'Shop', href: route('shop.index') }];

// Selects bind '' for "any", or the selected id.
const filterState = reactive<{ search: string; category: number | ''; tags: number[]; brand: number | '' }>({
    search: props.filters.search ?? '',
    category: props.filters.category?.[0] ?? '',
    tags: [...(props.filters.tags ?? [])],
    brand: props.filters.brand ?? '',
});

const filtering = computed(() =>
    Boolean(props.filters.search || props.filters.category?.length || props.filters.tags?.length || props.filters.brand),
);

/** What a shopper pays for a variant: its own price, or the product's (as checkout does). */
const effectivePrices = (product: Product): StorefrontPrice[] => {
    if (!product.variants.length) {
        return product.prices.slice(0, 1);
    }

    return product.variants.map((variant) => variant.prices[0] ?? product.prices[0]).filter((price): price is StorefrontPrice => Boolean(price));
};

/** The cheapest it can be had for, and whether other choices cost more. */
const cardPrice = (product: Product) => {
    const prices = [...effectivePrices(product)].sort((a, b) => Number(a.amount) - Number(b.amount));

    return { price: prices[0] ?? null, from: new Set(prices.map((price) => price.amount)).size > 1 };
};

/** Products with nothing to choose can be added straight from the list. */
const quickAdd = (product: Product) => product.can_buy && !product.sold_out && product.variants.length === 0;

const addingId = ref<number | null>(null);

const addToCart = (product: Product) => {
    addingId.value = product.id;

    router.post(
        route('shop.cart.items.store'),
        { product_id: product.id, quantity: 1 },
        {
            preserveScroll: true,
            onFinish: () => {
                addingId.value = null;
            },
        },
    );
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

const selectClass = 'w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs focus:border-primary focus:outline-hidden';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Shop" />

        <div class="flex h-full flex-1 flex-col gap-6 rounded-xl p-4">
            <div>
                <h1 class="text-3xl font-semibold tracking-tight">Shop</h1>
                <p class="text-muted-foreground">Browse our products.</p>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Find something</CardTitle>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="grid gap-4 md:grid-cols-3">
                        <div class="space-y-2">
                            <label class="text-sm font-semibold text-foreground" for="search">Search</label>
                            <Input id="search" v-model="filterState.search" placeholder="Search by name or description" @keyup.enter="applyFilters" />
                        </div>

                        <div class="space-y-2">
                            <label class="text-sm font-semibold text-foreground" for="category">Category</label>
                            <select id="category" v-model="filterState.category" :class="selectClass">
                                <option value="">All categories</option>
                                <option v-for="category in props.categories" :key="category.id" :value="category.id">{{ category.name }}</option>
                            </select>
                        </div>

                        <div class="space-y-2">
                            <label class="text-sm font-semibold text-foreground" for="brand">Brand</label>
                            <select id="brand" v-model="filterState.brand" :class="selectClass">
                                <option value="">All brands</option>
                                <option v-for="brand in props.brands" :key="brand.id" :value="brand.id">{{ brand.name }}</option>
                            </select>
                        </div>
                    </div>

                    <div v-if="props.tags.length" class="space-y-2">
                        <span class="text-sm font-semibold text-foreground">Tags</span>
                        <div class="flex flex-wrap gap-2">
                            <Button
                                v-for="tag in props.tags"
                                :key="tag.id"
                                size="sm"
                                variant="outline"
                                :aria-pressed="filterState.tags.includes(tag.id)"
                                :class="filterState.tags.includes(tag.id) ? 'border-primary text-primary' : ''"
                                @click="toggleTag(tag.id)"
                            >
                                {{ tag.name }}
                            </Button>
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <Button @click="applyFilters">Apply filters</Button>
                        <Button variant="ghost" @click="clearFilters">Reset</Button>
                    </div>
                </CardContent>
            </Card>

            <p v-if="props.products.data.length === 0" class="py-10 text-center text-muted-foreground">
                {{ filtering ? 'Nothing matches those filters.' : 'There is nothing in the shop yet.' }}
            </p>

            <ul v-else class="grid gap-6 sm:grid-cols-2 xl:grid-cols-3">
                <li v-for="product in props.products.data" :key="product.id">
                    <Card class="h-full gap-0 overflow-hidden py-0">
                        <Link
                            :href="route('shop.products.show', product.slug)"
                            class="relative block bg-muted/30"
                            :aria-label="`View ${product.name}`"
                            tabindex="-1"
                        >
                            <img
                                v-if="product.image"
                                :src="product.image.medium"
                                :alt="product.image.alt"
                                :width="product.image.width"
                                :height="product.image.height"
                                class="aspect-[4/3] w-full object-cover"
                                loading="lazy"
                            />
                            <div v-else class="flex aspect-[4/3] items-center justify-center text-muted-foreground" aria-hidden="true">
                                <ImageOff class="size-10" />
                            </div>
                            <Badge v-if="product.sold_out" class="absolute top-3 left-3" variant="destructive">Sold out</Badge>
                        </Link>

                        <div class="flex flex-1 flex-col gap-3 p-4">
                            <div class="space-y-1">
                                <p v-if="product.brand" class="text-xs tracking-wide text-muted-foreground uppercase">{{ product.brand.name }}</p>
                                <h2 class="line-clamp-2 text-lg leading-snug font-semibold">
                                    <Link :href="route('shop.products.show', product.slug)" class="hover:underline">{{ product.name }}</Link>
                                </h2>
                                <ProductPrice :price="cardPrice(product).price" :from="cardPrice(product).from" />
                            </div>

                            <p v-if="product.description" class="line-clamp-2 text-sm text-muted-foreground">{{ product.description }}</p>

                            <div class="mt-auto flex gap-2 pt-2">
                                <Button as-child variant="secondary" class="flex-1">
                                    <Link :href="route('shop.products.show', product.slug)">{{
                                        product.variants.length ? 'Choose options' : 'View'
                                    }}</Link>
                                </Button>
                                <Button v-if="quickAdd(product)" variant="outline" :disabled="addingId === product.id" @click="addToCart(product)">
                                    {{ addingId === product.id ? 'Adding…' : 'Add to cart' }}
                                </Button>
                            </div>
                        </div>
                    </Card>
                </li>
            </ul>

            <nav v-if="props.products.last_page > 1" class="flex items-center justify-between gap-3" aria-label="Pages">
                <Button v-if="props.products.prev_page_url" as-child variant="outline">
                    <Link :href="props.products.prev_page_url" preserve-scroll>Previous</Link>
                </Button>
                <span v-else />
                <span class="text-sm text-muted-foreground">Page {{ props.products.current_page }} of {{ props.products.last_page }}</span>
                <Button v-if="props.products.next_page_url" as-child variant="outline">
                    <Link :href="props.products.next_page_url" preserve-scroll>Next</Link>
                </Button>
                <span v-else />
            </nav>
        </div>
    </AppLayout>
</template>
