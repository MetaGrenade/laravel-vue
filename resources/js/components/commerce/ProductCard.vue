<script setup lang="ts">
import ProductPrice from '@/components/commerce/ProductPrice.vue';
import StarRating from '@/components/commerce/StarRating.vue';
import WishlistButton from '@/components/commerce/WishlistButton.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import type { StorefrontPrice } from '@/types/commerce';
import type { StorefrontCard } from '@/types/storefront';
import { Link, router } from '@inertiajs/vue3';
import { ImageOff } from '@lucide/vue';
import { computed, ref } from 'vue';

const props = defineProps<{ product: StorefrontCard }>();

/** What a shopper pays for a variant: its own price, or the product's (as checkout does). */
const effectivePrices = computed<StorefrontPrice[]>(() => {
    if (!props.product.variants.length) {
        return props.product.prices.slice(0, 1);
    }

    return props.product.variants
        .map((variant) => variant.prices[0] ?? props.product.prices[0])
        .filter((price): price is StorefrontPrice => Boolean(price));
});

/** The cheapest it can be had for, and whether other choices cost more. */
const cardPrice = computed(() => {
    const prices = [...effectivePrices.value].sort((a, b) => Number(a.amount) - Number(b.amount));

    return { price: prices[0] ?? null, from: new Set(prices.map((price) => price.amount)).size > 1 };
});

/** Products with nothing to choose can be added straight from the list. */
const quickAdd = computed(() => props.product.can_buy && !props.product.sold_out && props.product.variants.length === 0);

const adding = ref(false);

const addToCart = () => {
    router.post(
        route('shop.cart.items.store'),
        { product_id: props.product.id, quantity: 1 },
        {
            preserveScroll: true,
            onStart: () => (adding.value = true),
            onFinish: () => (adding.value = false),
        },
    );
};
</script>

<template>
    <Card class="h-full gap-0 overflow-hidden py-0">
        <div class="relative bg-muted/30">
            <Link :href="route('shop.products.show', product.slug)" class="block" :aria-label="`View ${product.name}`" tabindex="-1">
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
            </Link>
            <Badge v-if="product.sold_out" class="absolute top-3 left-3" variant="destructive">Sold out</Badge>
            <WishlistButton
                class="absolute top-3 right-3"
                display="icon"
                :product-id="product.id"
                :product-name="product.name"
                :wishlisted="Boolean(product.wishlisted)"
            />
        </div>

        <div class="flex flex-1 flex-col gap-3 p-4">
            <div class="space-y-1">
                <p v-if="product.brand" class="text-xs tracking-wide text-muted-foreground uppercase">{{ product.brand.name }}</p>
                <h2 class="line-clamp-2 text-lg leading-snug font-semibold">
                    <Link :href="route('shop.products.show', product.slug)" class="hover:underline">{{ product.name }}</Link>
                </h2>
                <p v-if="product.rating" class="flex items-center gap-1.5 text-sm">
                    <StarRating :value="product.rating.average" size="sm" />
                    <span class="text-muted-foreground">
                        {{ product.rating.average.toFixed(1) }} ({{ product.rating.count }}<span class="sr-only"> reviews</span>)
                    </span>
                </p>
                <ProductPrice :price="cardPrice.price" :from="cardPrice.from" />
            </div>

            <p v-if="product.description" class="line-clamp-2 text-sm text-muted-foreground">{{ product.description }}</p>

            <div class="mt-auto flex gap-2 pt-2">
                <Button as-child variant="secondary" class="flex-1">
                    <Link :href="route('shop.products.show', product.slug)">{{ product.variants.length ? 'Choose options' : 'View' }}</Link>
                </Button>
                <Button v-if="quickAdd" variant="outline" :disabled="adding" @click="addToCart">
                    {{ adding ? 'Adding…' : 'Add to cart' }}
                </Button>
            </div>
        </div>
    </Card>
</template>
