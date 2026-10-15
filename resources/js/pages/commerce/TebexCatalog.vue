<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatMoney } from '@/lib/money';
import type { TebexPackageCard } from '@/types/tebexStorefront';
import { Head, Link, router } from '@inertiajs/vue3';
import { ImageOff } from '@lucide/vue';
import { computed, reactive } from 'vue';

interface Props {
    packages: {
        data: TebexPackageCard[];
        current_page: number;
        last_page: number;
        total: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    categories: { id: number; name: string; count: number }[];
    filters: { search: string; category: number | null };
}

const props = defineProps<Props>();

const breadcrumbs = [{ title: 'Shop', href: route('shop.packages') }];

const filterState = reactive<{ search: string; category: number | '' }>({
    search: props.filters.search ?? '',
    category: props.filters.category ?? '',
});

const filtering = computed(() => Boolean(props.filters.search || props.filters.category));

const applyFilters = () => {
    router.get(
        route('shop.packages'),
        { search: filterState.search || undefined, category: filterState.category || undefined },
        { preserveScroll: true, replace: true },
    );
};

const clearFilters = () => {
    filterState.search = '';
    filterState.category = '';
    applyFilters();
};

const selectClass = 'w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs focus:border-primary focus:outline-hidden';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Packages" />

        <div class="flex h-full flex-1 flex-col gap-6 rounded-xl p-4">
            <div>
                <h1 class="text-3xl font-semibold tracking-tight">Shop</h1>
                <p class="text-muted-foreground">Packages you can buy. Payment is taken by Tebex.</p>
            </div>

            <Card>
                <CardContent class="flex flex-wrap items-end gap-4 pt-6">
                    <div class="min-w-60 flex-1 space-y-2">
                        <label class="text-sm font-semibold text-foreground" for="package-search">Search</label>
                        <Input
                            id="package-search"
                            v-model="filterState.search"
                            placeholder="Search by name or description"
                            @keyup.enter="applyFilters"
                        />
                    </div>
                    <div v-if="props.categories.length" class="min-w-48 space-y-2">
                        <label class="text-sm font-semibold text-foreground" for="package-category">Category</label>
                        <select id="package-category" v-model="filterState.category" :class="selectClass" @change="applyFilters">
                            <option value="">All categories</option>
                            <option v-for="category in props.categories" :key="category.id" :value="category.id">
                                {{ category.name }} ({{ category.count }})
                            </option>
                        </select>
                    </div>
                    <div class="flex items-center gap-3">
                        <Button @click="applyFilters">Apply</Button>
                        <Button v-if="filtering" variant="ghost" @click="clearFilters">Reset</Button>
                    </div>
                </CardContent>
            </Card>

            <p v-if="props.packages.data.length === 0" class="py-10 text-center text-muted-foreground">
                {{ filtering ? 'Nothing matches that.' : 'There is nothing for sale yet.' }}
            </p>

            <ul v-else class="grid gap-6 sm:grid-cols-2 xl:grid-cols-3">
                <li v-for="item in props.packages.data" :key="item.id">
                    <Card class="h-full overflow-hidden">
                        <Link :href="item.href" class="block">
                            <img v-if="item.image" :src="item.image" alt="" loading="lazy" class="aspect-video w-full border-b object-cover" />
                            <div
                                v-else
                                class="flex aspect-video w-full items-center justify-center border-b bg-muted text-muted-foreground"
                                aria-hidden="true"
                            >
                                <ImageOff class="size-8" />
                            </div>
                        </Link>
                        <CardContent class="space-y-3 pt-4">
                            <div class="flex flex-wrap items-center gap-2">
                                <Badge v-if="item.category" variant="outline">{{ item.category }}</Badge>
                                <Badge v-if="item.recurring" variant="secondary">Recurring</Badge>
                            </div>
                            <h2 class="text-lg font-semibold">
                                <Link :href="item.href" class="hover:underline">{{ item.name }}</Link>
                            </h2>
                            <p v-if="item.excerpt" class="line-clamp-3 text-sm text-muted-foreground">{{ item.excerpt }}</p>
                            <div class="flex items-baseline justify-between gap-3">
                                <span class="text-xl font-bold tabular-nums">{{ formatMoney(item.price, item.currency) }}</span>
                                <span v-if="!item.can_buy" class="text-xs text-muted-foreground">Not for sale here yet</span>
                            </div>
                        </CardContent>
                    </Card>
                </li>
            </ul>

            <nav v-if="props.packages.last_page > 1" class="flex items-center justify-between gap-3" aria-label="Pages">
                <Button v-if="props.packages.prev_page_url" as-child variant="outline">
                    <Link :href="props.packages.prev_page_url" preserve-scroll>Previous</Link>
                </Button>
                <span v-else />
                <span class="text-sm text-muted-foreground">Page {{ props.packages.current_page }} of {{ props.packages.last_page }}</span>
                <Button v-if="props.packages.next_page_url" as-child variant="outline">
                    <Link :href="props.packages.next_page_url" preserve-scroll>Next</Link>
                </Button>
                <span v-else />
            </nav>
        </div>
    </AppLayout>
</template>
