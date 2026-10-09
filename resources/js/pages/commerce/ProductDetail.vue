<script setup lang="ts">
import ProductGallery from '@/components/commerce/ProductGallery.vue';
import ProductPrice from '@/components/commerce/ProductPrice.vue';
import StockBadge from '@/components/commerce/StockBadge.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import type { StorefrontImage, StorefrontOption, StorefrontPrice, StorefrontStock, StorefrontVariant } from '@/types/commerce';
import { Head, Link, router } from '@inertiajs/vue3';
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
    description: string | null;
    brand: Taxon | null;
    categories: Taxon[];
    tags: Taxon[];
    can_buy: boolean;
    sold_out: boolean;
    /** How much is left of the product itself (when it has no variants). */
    stock: StorefrontStock;
    requires_shipping: boolean;
    images: StorefrontImage[];
    options: StorefrontOption[];
    variants: StorefrontVariant[];
    prices: StorefrontPrice[];
}

const props = defineProps<{
    product: Product;
    maxQuantity: number;
}>();

const breadcrumbs = [
    { title: 'Shop', href: route('shop.index') },
    { title: props.product.name, href: route('shop.products.show', props.product.slug) },
];

// ----- Choosing a variant -------------------------------------------------------------------------

const hasVariants = computed(() => props.product.variants.length > 0);
const start = props.product.variants.find((variant) => variant.is_default) ?? props.product.variants[0] ?? null;

/** What is picked for each option, by option name. */
const picked = reactive<Record<string, string>>({ ...(start?.option_values ?? {}) });
/** For variants that are not made from options, they are simply listed. */
const pickedVariantId = ref<number | null>(start?.id ?? null);

const selectedVariant = computed<StorefrontVariant | null>(() => {
    if (!hasVariants.value) {
        return null;
    }

    if (props.product.options.length === 0) {
        return props.product.variants.find((variant) => variant.id === pickedVariantId.value) ?? null;
    }

    return (
        props.product.variants.find((variant) =>
            props.product.options.every((option) => variant.option_values[option.name] === picked[option.name]),
        ) ?? null
    );
});

/** Whether some variant is for sale with this value, given what is picked for the other options. */
const offered = (option: StorefrontOption, value: string) =>
    props.product.variants.some(
        (variant) =>
            variant.option_values[option.name] === value &&
            props.product.options.every((other) => other.name === option.name || variant.option_values[other.name] === picked[other.name]),
    );

const choose = (option: StorefrontOption, value: string) => {
    picked[option.name] = value;

    // This value does not come in the other things picked: move to a combination that does exist.
    if (!selectedVariant.value) {
        const match = props.product.variants.find((variant) => variant.option_values[option.name] === value);

        if (match) {
            Object.assign(picked, match.option_values);
        }
    }
};

// ----- What it costs and whether it can be had ----------------------------------------------------

/** A variant is sold at its own price, or the product's if it has none (as checkout does). */
const price = computed<StorefrontPrice | null>(() => {
    if (hasVariants.value && !selectedVariant.value) {
        return null;
    }

    return selectedVariant.value?.prices[0] ?? props.product.prices[0] ?? null;
});

const stock = computed<StorefrontStock | null>(() => (hasVariants.value ? (selectedVariant.value?.stock ?? null) : props.product.stock));

const problem = computed(() => {
    if (!props.product.can_buy) {
        return 'This product is currently unavailable.';
    }

    if (hasVariants.value && !selectedVariant.value) {
        return 'That combination is not available. Pick another.';
    }

    if (stock.value === 'out') {
        return 'This is out of stock.';
    }

    return null;
});

const quantity = ref(1);

const setQuantity = (value: number) => {
    quantity.value = Math.min(props.maxQuantity, Math.max(1, Math.trunc(Number.isFinite(value) ? value : 1)));
};

const adding = ref(false);

const addToCart = () => {
    if (problem.value) {
        return;
    }

    router.post(
        route('shop.cart.items.store'),
        { product_id: props.product.id, product_variant_id: selectedVariant.value?.id ?? null, quantity: quantity.value },
        {
            preserveScroll: true,
            onStart: () => (adding.value = true),
            onFinish: () => (adding.value = false),
        },
    );
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="product.name" />

        <div class="mx-auto grid w-full max-w-6xl gap-8 p-4 lg:grid-cols-2 lg:gap-12">
            <ProductGallery :images="product.images" />

            <div class="space-y-6">
                <div class="space-y-2">
                    <Badge v-if="product.brand" variant="outline">{{ product.brand.name }}</Badge>
                    <h1 class="text-3xl font-semibold tracking-tight">{{ product.name }}</h1>
                    <ProductPrice :price="price" large />
                    <StockBadge v-if="stock" :status="stock" />
                </div>

                <p v-if="product.description" class="leading-relaxed whitespace-pre-line text-muted-foreground">{{ product.description }}</p>

                <div v-if="hasVariants" class="space-y-4">
                    <fieldset v-for="option in product.options" :key="option.id" class="space-y-2">
                        <legend class="text-sm font-medium">
                            {{ option.display_name
                            }}<span v-if="picked[option.name]" class="font-normal text-muted-foreground">: {{ picked[option.name] }}</span>
                        </legend>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="value in option.values"
                                :key="value"
                                type="button"
                                class="rounded-md border px-3 py-1.5 text-sm transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
                                :class="[
                                    picked[option.name] === value ? 'border-primary bg-primary text-primary-foreground' : 'hover:border-primary/60',
                                    offered(option, value) || picked[option.name] === value ? '' : 'text-muted-foreground line-through',
                                ]"
                                :aria-pressed="picked[option.name] === value"
                                @click="choose(option, value)"
                            >
                                {{ value }}
                            </button>
                        </div>
                    </fieldset>

                    <div v-if="product.options.length === 0 && product.variants.length > 1" class="space-y-2">
                        <label for="variant" class="text-sm font-medium">Choose one</label>
                        <select
                            id="variant"
                            v-model="pickedVariantId"
                            class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs focus:border-primary focus:outline-hidden"
                        >
                            <option v-for="variant in product.variants" :key="variant.id" :value="variant.id">{{ variant.name }}</option>
                        </select>
                    </div>
                </div>

                <div class="space-y-3">
                    <div class="flex flex-wrap items-end gap-3">
                        <div class="space-y-1">
                            <label for="quantity" class="text-sm font-medium">Quantity</label>
                            <Input
                                id="quantity"
                                type="number"
                                inputmode="numeric"
                                min="1"
                                :max="maxQuantity"
                                class="w-24"
                                :model-value="quantity"
                                @change="setQuantity(Number(($event.target as HTMLInputElement).value))"
                            />
                        </div>
                        <Button size="lg" class="flex-1 sm:flex-none sm:px-10" :disabled="problem !== null || adding" @click="addToCart">
                            {{ adding ? 'Adding…' : 'Add to cart' }}
                        </Button>
                    </div>
                    <p v-if="problem" class="text-sm text-muted-foreground" role="status">{{ problem }}</p>
                    <p v-if="!product.requires_shipping" class="text-sm text-muted-foreground">This item does not need shipping.</p>
                </div>

                <div v-if="product.categories.length || product.tags.length" class="flex flex-wrap gap-2 border-t pt-4">
                    <Link v-for="category in product.categories" :key="`c${category.id}`" :href="route('shop.index', { category: [category.id] })">
                        <Badge variant="secondary">{{ category.name }}</Badge>
                    </Link>
                    <Link v-for="tag in product.tags" :key="`t${tag.id}`" :href="route('shop.index', { tags: [tag.id] })">
                        <Badge variant="outline">{{ tag.name }}</Badge>
                    </Link>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
