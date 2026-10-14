<script setup lang="ts">
import StarRating from '@/components/commerce/StarRating.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Pagination,
    PaginationEllipsis,
    PaginationFirst,
    PaginationLast,
    PaginationList,
    PaginationListItem,
    PaginationNext,
    PaginationPrev,
} from '@/components/ui/pagination';
import { useInertiaPagination, type PaginationMeta } from '@/composables/useInertiaPagination';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { BadgeCheck, Check, Star, Trash2, X } from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';

type Status = 'pending' | 'published' | 'rejected';

interface ReviewRow {
    id: number;
    rating: number;
    title: string | null;
    body: string;
    status: Status;
    status_label: string;
    verified_purchase: boolean;
    created_at: string | null;
    moderated_at: string | null;
    moderated_by: string | null;
    product: { id: number; name: string; slug: string } | null;
    author: { id: number; nickname: string; email: string } | null;
}

const props = defineProps<{
    reviews: { data: ReviewRow[]; meta?: PaginationMeta | null };
    filters: { status: Status | 'all'; search: string; rating: number | null };
    counts: Record<Status | 'all', number>;
    settings: { enabled: boolean; approval: string; require_purchase: boolean };
    can: { edit: boolean; delete: boolean };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Commerce', href: route('acp.commerce.index') },
    { title: 'Reviews', href: route('acp.commerce.reviews.index') },
];

const rows = computed(() => props.reviews.data ?? []);

const tabs: { value: Status | 'all'; label: string }[] = [
    { value: 'pending', label: 'Waiting' },
    { value: 'published', label: 'Published' },
    { value: 'rejected', label: 'Rejected' },
    { value: 'all', label: 'All' },
];

const filterState = reactive({ search: props.filters.search, rating: props.filters.rating ?? '' });

watch(
    () => props.filters,
    (filters) => {
        filterState.search = filters.search;
        filterState.rating = filters.rating ?? '';
    },
    { deep: true },
);

const go = (overrides: Record<string, string | number> = {}) => {
    const query = Object.fromEntries(
        Object.entries({ status: props.filters.status, search: filterState.search, rating: filterState.rating, ...overrides }).filter(
            ([, value]) => String(value).trim() !== '',
        ),
    );

    router.get(route('acp.commerce.reviews.index'), query, { preserveScroll: true, preserveState: true, replace: true });
};

const { meta, page, pageCount, rangeLabel } = useInertiaPagination({
    meta: computed(() => props.reviews.meta ?? null),
    itemsLength: computed(() => rows.value.length),
    defaultPerPage: 20,
    itemLabel: 'review',
    itemLabelPlural: 'reviews',
    onNavigate: (newPage) => go({ page: newPage }),
});

const day = (iso: string | null) => (iso ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(iso)) : '');

const statusVariant = (status: Status) =>
    status === 'published' ? ('secondary' as const) : status === 'rejected' ? ('destructive' as const) : ('highlight' as const);

const busyId = ref<number | null>(null);

const setStatus = (review: ReviewRow, status: Status) => {
    router.patch(
        route('acp.commerce.reviews.update', review.id),
        { status },
        {
            preserveScroll: true,
            onStart: () => (busyId.value = review.id),
            onFinish: () => (busyId.value = null),
        },
    );
};

const deleting = ref<ReviewRow | null>(null);

const remove = () => {
    if (!deleting.value) {
        return;
    }

    router.delete(route('acp.commerce.reviews.destroy', deleting.value.id), {
        preserveScroll: true,
        onFinish: () => {
            deleting.value = null;
        },
    });
};

const approvalText = computed(() => {
    switch (props.settings.approval) {
        case 'none':
            return 'Reviews are published as soon as they are written.';
        case 'unverified':
            return 'Reviews from customers who bought the product are published at once; the rest wait here.';
        default:
            return 'Every review waits here until you publish it.';
    }
});

const selectClass = 'rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs focus:border-primary focus:outline-hidden';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Reviews" />

        <AdminLayout>
            <div class="space-y-6 pb-4">
                <div>
                    <h2 class="flex items-center gap-2 text-xl font-semibold tracking-tight"><Star class="size-5" /> Reviews</h2>
                    <p class="mt-1 max-w-2xl text-sm text-muted-foreground">
                        What customers wrote about your products. Only published reviews are shown, or counted in a rating. {{ approvalText }}
                    </p>
                    <p v-if="!settings.enabled" class="mt-2 text-sm text-destructive" role="status">
                        Reviews are switched off (COMMERCE_REVIEWS), so none are shown in the shop.
                    </p>
                </div>

                <div class="flex flex-wrap gap-2" role="tablist" aria-label="Review status">
                    <Button
                        v-for="tab in tabs"
                        :key="tab.value"
                        size="sm"
                        :variant="filters.status === tab.value ? 'default' : 'outline'"
                        role="tab"
                        :aria-selected="filters.status === tab.value"
                        @click="go({ status: tab.value, page: 1 })"
                    >
                        {{ tab.label }}
                        <span class="tabular-nums opacity-80">{{ counts[tab.value] }}</span>
                    </Button>
                </div>

                <form class="flex flex-wrap items-end gap-3 rounded-lg border border-border bg-card p-4 shadow-xs" @submit.prevent="go({ page: 1 })">
                    <div class="flex min-w-60 flex-1 flex-col gap-2">
                        <label for="review-search" class="text-sm font-medium">Search</label>
                        <Input id="review-search" v-model="filterState.search" type="search" placeholder="Review text, product or customer" />
                    </div>
                    <div class="flex flex-col gap-2">
                        <label for="review-rating" class="text-sm font-medium">Rating</label>
                        <select id="review-rating" v-model="filterState.rating" :class="selectClass">
                            <option value="">Any</option>
                            <option v-for="stars in [5, 4, 3, 2, 1]" :key="stars" :value="stars">
                                {{ stars }} {{ stars === 1 ? 'star' : 'stars' }}
                            </option>
                        </select>
                    </div>
                    <Button type="submit">Filter</Button>
                    <Button
                        v-if="filterState.search || filterState.rating"
                        type="button"
                        variant="outline"
                        @click="
                            filterState.search = '';
                            filterState.rating = '';
                            go({ page: 1, search: '', rating: '' });
                        "
                        >Clear</Button
                    >
                </form>

                <div v-if="rows.length" class="space-y-4">
                    <Card v-for="review in rows" :key="review.id">
                        <CardContent class="space-y-3 pt-6">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0 space-y-1">
                                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                        <StarRating :value="review.rating" />
                                        <Badge :variant="statusVariant(review.status)">{{ review.status_label }}</Badge>
                                        <Badge v-if="review.verified_purchase" variant="secondary"
                                            ><BadgeCheck aria-hidden="true" /> Verified purchase</Badge
                                        >
                                    </div>
                                    <p class="text-sm text-muted-foreground">
                                        <template v-if="review.product">
                                            On
                                            <Link
                                                :href="route('acp.commerce.products.edit', review.product.id)"
                                                class="font-medium text-foreground hover:underline"
                                                >{{ review.product.name }}</Link
                                            >
                                            (<a
                                                :href="route('shop.products.show', review.product.slug) + '#reviews'"
                                                class="hover:underline"
                                                target="_blank"
                                                rel="noopener"
                                                >view in shop</a
                                            >)
                                        </template>
                                        <template v-if="review.author">
                                            · by <span class="font-medium text-foreground">{{ review.author.nickname }}</span> ({{
                                                review.author.email
                                            }})
                                        </template>
                                        · {{ day(review.created_at) }}
                                    </p>
                                </div>

                                <div v-if="can.edit || can.delete" class="flex flex-wrap gap-2">
                                    <Button
                                        v-if="can.edit && review.status !== 'published'"
                                        size="sm"
                                        :disabled="busyId === review.id"
                                        @click="setStatus(review, 'published')"
                                    >
                                        <Check class="size-4" /> Publish
                                    </Button>
                                    <Button
                                        v-if="can.edit && review.status !== 'rejected'"
                                        size="sm"
                                        variant="outline"
                                        :disabled="busyId === review.id"
                                        @click="setStatus(review, 'rejected')"
                                    >
                                        <X class="size-4" /> Reject
                                    </Button>
                                    <Button v-if="can.delete" size="sm" variant="ghost" class="text-destructive" @click="deleting = review">
                                        <Trash2 class="size-4" /> Delete
                                    </Button>
                                </div>
                            </div>

                            <div class="space-y-1">
                                <p v-if="review.title" class="font-medium">{{ review.title }}</p>
                                <p class="text-sm whitespace-pre-line">{{ review.body }}</p>
                            </div>

                            <p v-if="review.moderated_by && review.status !== 'pending'" class="text-xs text-muted-foreground">
                                {{ review.status === 'published' ? 'Published' : 'Rejected' }} by {{ review.moderated_by }} on
                                {{ day(review.moderated_at) }}
                            </p>
                        </CardContent>
                    </Card>
                </div>
                <Card v-else>
                    <CardContent class="py-10 text-center text-sm text-muted-foreground">
                        <template v-if="filterState.search || filterState.rating">No reviews match.</template>
                        <template v-else-if="filters.status === 'pending'">Nothing is waiting for you.</template>
                        <template v-else>No reviews here yet.</template>
                    </CardContent>
                </Card>

                <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <p class="text-sm text-muted-foreground">{{ rangeLabel }}</p>
                    <Pagination
                        v-if="pageCount > 1"
                        v-slot="{ page: currentPage }"
                        v-model:page="page"
                        :items-per-page="Math.max(meta.per_page, 1)"
                        :total="meta.total"
                        :sibling-count="1"
                        show-edges
                    >
                        <PaginationList v-slot="{ items }" class="flex flex-wrap items-center justify-center gap-1">
                            <PaginationFirst />
                            <PaginationPrev />

                            <template v-for="(item, index) in items" :key="index">
                                <PaginationListItem v-if="item.type === 'page'" :value="item.value" as-child>
                                    <Button class="h-9 w-9 p-0" :variant="item.value === currentPage ? 'default' : 'outline'">
                                        {{ item.value }}
                                    </Button>
                                </PaginationListItem>
                                <PaginationEllipsis v-else :index="index" />
                            </template>

                            <PaginationNext />
                            <PaginationLast />
                        </PaginationList>
                    </Pagination>
                </div>
            </div>

            <ConfirmDialog
                :open="deleting !== null"
                title="Delete this review?"
                description="It is removed for good, including from the ratings. To keep it but hide it, reject it instead (its writer then cannot post it again)."
                confirm-label="Delete review"
                @update:open="(open) => !open && (deleting = null)"
                @confirm="remove"
                @cancel="deleting = null"
            />
        </AdminLayout>
    </AppLayout>
</template>
