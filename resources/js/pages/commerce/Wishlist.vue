<script setup lang="ts">
import ProductCard from '@/components/commerce/ProductCard.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import type { StorefrontCard } from '@/types/storefront';
import { Head, Link } from '@inertiajs/vue3';
import { Heart } from '@lucide/vue';

defineProps<{
    products: {
        data: StorefrontCard[];
        current_page: number;
        last_page: number;
        total: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
}>();

const breadcrumbs = [
    { title: 'Shop', href: route('shop.index') },
    { title: 'Wishlist', href: route('shop.wishlist') },
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Your wishlist" />

        <div class="flex h-full flex-1 flex-col gap-6 rounded-xl p-4">
            <div>
                <h1 class="text-3xl font-semibold tracking-tight">Your wishlist</h1>
                <p class="text-muted-foreground">Products you saved to come back to. Only you can see this list.</p>
            </div>

            <div v-if="products.data.length === 0" class="flex flex-col items-center gap-3 py-16 text-center">
                <Heart class="size-10 text-muted-foreground/60" aria-hidden="true" />
                <p class="text-muted-foreground">Nothing saved yet. Tap the heart on a product to keep it here.</p>
                <Button as-child>
                    <Link :href="route('shop.index')">Browse the shop</Link>
                </Button>
            </div>

            <ul v-else class="grid gap-6 sm:grid-cols-2 xl:grid-cols-3">
                <li v-for="product in products.data" :key="product.id">
                    <ProductCard :product="product" />
                </li>
            </ul>

            <nav v-if="products.last_page > 1" class="flex items-center justify-between gap-3" aria-label="Pages">
                <Button v-if="products.prev_page_url" as-child variant="outline">
                    <Link :href="products.prev_page_url" preserve-scroll>Previous</Link>
                </Button>
                <span v-else />
                <span class="text-sm text-muted-foreground">Page {{ products.current_page }} of {{ products.last_page }}</span>
                <Button v-if="products.next_page_url" as-child variant="outline">
                    <Link :href="products.next_page_url" preserve-scroll>Next</Link>
                </Button>
                <span v-else />
            </nav>
        </div>
    </AppLayout>
</template>
