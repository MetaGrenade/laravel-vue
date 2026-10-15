<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatMoney } from '@/lib/money';
import type { TebexPackageDetail } from '@/types/tebexStorefront';
import { Head, Link, router } from '@inertiajs/vue3';
import { ImageOff } from '@lucide/vue';
import { computed, ref } from 'vue';

const props = defineProps<{ package: TebexPackageDetail }>();

const breadcrumbs = computed(() => [
    { title: 'Shop', href: route('shop.packages') },
    { title: props.package.name, href: props.package.href },
]);

const picture = ref(0);
const mainPicture = computed(() => props.package.pictures[picture.value] ?? null);

const quantity = ref(1);

const setQuantity = (value: number) => {
    quantity.value = Math.min(props.package.max_quantity, Math.max(1, Math.trunc(Number.isFinite(value) ? value : 1)));
};

const adding = ref(false);

const addToCart = () => {
    adding.value = true;

    router.post(
        route('shop.cart.packages.store'),
        { package_id: props.package.id, quantity: quantity.value },
        { preserveScroll: true, onFinish: () => (adding.value = false) },
    );
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="props.package.name" />

        <div class="grid gap-8 p-4 lg:grid-cols-2">
            <div class="space-y-3">
                <img v-if="mainPicture" :src="mainPicture" :alt="props.package.name" class="aspect-video w-full rounded-lg border object-cover" />
                <div
                    v-else
                    class="flex aspect-video w-full items-center justify-center rounded-lg border bg-muted text-muted-foreground"
                    aria-hidden="true"
                >
                    <ImageOff class="size-10" />
                </div>
                <ul v-if="props.package.pictures.length > 1" class="flex flex-wrap gap-2">
                    <li v-for="(url, index) in props.package.pictures" :key="url">
                        <button
                            type="button"
                            class="overflow-hidden rounded-md border"
                            :class="index === picture ? 'ring-2 ring-primary' : ''"
                            :aria-label="`Picture ${index + 1}`"
                            :aria-pressed="index === picture"
                            @click="picture = index"
                        >
                            <img :src="url" alt="" loading="lazy" class="size-16 object-cover" />
                        </button>
                    </li>
                </ul>
            </div>

            <div class="space-y-5">
                <div class="space-y-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <Badge v-if="props.package.category" variant="outline">{{ props.package.category }}</Badge>
                        <Badge v-if="props.package.recurring" variant="secondary">Recurring</Badge>
                    </div>
                    <h1 class="text-3xl font-semibold tracking-tight">{{ props.package.name }}</h1>
                </div>

                <div>
                    <p class="text-3xl font-bold tabular-nums">{{ formatMoney(props.package.price, props.package.currency) }}</p>
                    <p class="text-sm text-muted-foreground">
                        Before tax. Tebex adds any tax for your country, and takes discount codes, creator codes and gift cards, when you pay.
                    </p>
                    <p v-if="props.package.recurring" class="mt-1 text-sm text-muted-foreground">Tebex renews this package until you cancel it.</p>
                </div>

                <!-- Cleaned on the server (HtmlSanitizer): scripts, frames, styles and handlers are gone. -->
                <!-- eslint-disable-next-line vue/no-v-html -->
                <div v-if="props.package.description_html" class="prose dark:prose-invert max-w-none" v-html="props.package.description_html" />

                <div v-if="props.package.can_buy" class="flex flex-wrap items-end gap-4">
                    <div v-if="props.package.max_quantity > 1" class="space-y-2">
                        <label for="package-quantity" class="text-sm font-medium">Quantity</label>
                        <Input
                            id="package-quantity"
                            type="number"
                            inputmode="numeric"
                            min="1"
                            :max="props.package.max_quantity"
                            class="w-24"
                            :model-value="quantity"
                            @change="setQuantity(Number(($event.target as HTMLInputElement).value))"
                        />
                    </div>
                    <Button size="lg" class="sm:px-10" :disabled="adding" @click="addToCart">{{ adding ? 'Adding…' : 'Add to cart' }}</Button>
                </div>
                <p v-else class="text-sm text-muted-foreground">{{ props.package.reason }}</p>

                <Button variant="link" class="px-0" as-child>
                    <Link :href="route('shop.packages')">Back to the shop</Link>
                </Button>
            </div>
        </div>
    </AppLayout>
</template>
