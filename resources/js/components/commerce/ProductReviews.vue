<script setup lang="ts">
import StarRating from '@/components/commerce/StarRating.vue';
import StarRatingInput from '@/components/commerce/StarRatingInput.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import type { ProductReviewsPayload, ReviewSort } from '@/types/storefront';
import { Link, router, useForm } from '@inertiajs/vue3';
import { BadgeCheck } from '@lucide/vue';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    reviews: ProductReviewsPayload;
    productSlug: string;
}>();

const summary = computed(() => props.reviews.summary);
const mine = computed(() => props.reviews.mine);

const day = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString(undefined, { dateStyle: 'medium' }) : '');

const percentOf = (stars: number) => (summary.value.count > 0 ? ((summary.value.distribution[stars] ?? 0) / summary.value.count) * 100 : 0);

// ----- Reading: order and pages -------------------------------------------------------------------

const sorts: { value: ReviewSort; label: string }[] = [
    { value: 'newest', label: 'Newest first' },
    { value: 'highest', label: 'Highest rated' },
    { value: 'lowest', label: 'Lowest rated' },
];

/** Only the reviews are fetched again, so the rest of the page (and a half-written review) stays as it is. */
const reload = (data: Record<string, string | number>) =>
    router.get(route('shop.products.show', props.productSlug), data, {
        only: ['reviews'],
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });

const setSort = (event: Event) => reload({ review_sort: (event.target as HTMLSelectElement).value });

const goToPage = (page: number) => reload({ review_sort: props.reviews.sort, reviews_page: page });

// ----- Writing ------------------------------------------------------------------------------------

const form = useForm({
    rating: props.reviews.mine?.rating ?? 0,
    title: props.reviews.mine?.title ?? '',
    body: props.reviews.mine?.body ?? '',
});

// What was said about a field is out of date as soon as the field changes.
watch(
    () => form.rating,
    () => form.clearErrors('rating'),
);
watch(
    () => form.title,
    () => form.clearErrors('title'),
);
watch(
    () => form.body,
    () => form.clearErrors('body'),
);

const editing = ref(false);

/** A new review can be written when allowed; one that exists is changed through "Edit". */
const showForm = computed(() => editing.value || (mine.value === null && props.reviews.can_write));

const startEditing = () => {
    form.rating = mine.value?.rating ?? 0;
    form.title = mine.value?.title ?? '';
    form.body = mine.value?.body ?? '';
    form.clearErrors();
    editing.value = true;
};

const submit = () => {
    form.post(route('shop.products.review.store', props.productSlug), {
        preserveScroll: true,
        onSuccess: () => {
            editing.value = false;
        },
    });
};

const confirmingDelete = ref(false);

const remove = () => {
    router.delete(route('shop.products.review.destroy', props.productSlug), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
        },
        onFinish: () => {
            confirmingDelete.value = false;
        },
    });
};

const selectClass = 'rounded-md border border-input bg-background px-3 py-1.5 text-sm shadow-xs focus:border-primary focus:outline-hidden';
</script>

<template>
    <section id="reviews" class="scroll-mt-20 space-y-6" aria-labelledby="reviews-heading">
        <h2 id="reviews-heading" class="text-2xl font-semibold tracking-tight">Customer reviews</h2>

        <div class="grid gap-8 lg:grid-cols-[17rem_1fr]">
            <div class="space-y-3">
                <template v-if="summary.count > 0 && summary.average !== null">
                    <p class="flex items-baseline gap-2">
                        <span class="text-4xl font-semibold tabular-nums">{{ summary.average.toFixed(1) }}</span>
                        <span class="text-muted-foreground">out of 5</span>
                    </p>
                    <StarRating :value="summary.average" size="lg" />
                    <p class="text-sm text-muted-foreground">Based on {{ summary.count }} {{ summary.count === 1 ? 'review' : 'reviews' }}</p>

                    <dl class="space-y-1.5 pt-2">
                        <div v-for="stars in [5, 4, 3, 2, 1]" :key="stars" class="flex items-center gap-2 text-sm">
                            <dt class="w-12 shrink-0 text-muted-foreground">{{ stars }} {{ stars === 1 ? 'star' : 'stars' }}</dt>
                            <dd class="flex flex-1 items-center gap-2">
                                <span class="h-2 flex-1 overflow-hidden rounded-full bg-muted" aria-hidden="true">
                                    <span class="block h-full rounded-full bg-warning" :style="{ width: `${percentOf(stars)}%` }" />
                                </span>
                                <span class="w-6 text-right text-muted-foreground tabular-nums">{{ summary.distribution[stars] ?? 0 }}</span>
                            </dd>
                        </div>
                    </dl>
                </template>
                <p v-else class="text-sm text-muted-foreground">No reviews yet. Be the first to share what you think.</p>
            </div>

            <div class="space-y-6">
                <!-- The visitor's own review, whatever state it is in -->
                <div v-if="mine && !editing" class="space-y-3 rounded-lg border border-primary/30 bg-primary/5 p-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="font-semibold">Your review</h3>
                        <Badge v-if="mine.status !== 'published'" :variant="mine.status === 'rejected' ? 'destructive' : 'highlight'">{{
                            mine.status_label
                        }}</Badge>
                    </div>
                    <StarRating :value="mine.rating" />
                    <p v-if="mine.title" class="font-medium">{{ mine.title }}</p>
                    <p class="text-sm whitespace-pre-line">{{ mine.body }}</p>
                    <p v-if="mine.status === 'pending'" class="text-sm text-muted-foreground" role="status">
                        It is waiting for approval and only you can see it until then.
                    </p>
                    <p v-else-if="mine.status === 'rejected'" class="text-sm text-muted-foreground" role="status">
                        This review was not published, and it cannot be changed. If you think that was a mistake, contact us.
                    </p>
                    <div v-if="mine.status !== 'rejected'" class="flex flex-wrap gap-2">
                        <Button size="sm" variant="outline" :disabled="!reviews.can_write" @click="startEditing">Edit</Button>
                        <Button size="sm" variant="ghost" class="text-destructive" @click="confirmingDelete = true">Delete</Button>
                    </div>
                </div>

                <form v-if="showForm" class="space-y-4 rounded-lg border p-4" novalidate @submit.prevent="submit">
                    <h3 class="font-semibold">{{ mine ? 'Edit your review' : 'Write a review' }}</h3>

                    <div class="space-y-1">
                        <StarRatingInput v-model="form.rating" :invalid="Boolean(form.errors.rating)" />
                        <p v-if="form.errors.rating" class="text-sm text-destructive" role="alert">{{ form.errors.rating }}</p>
                    </div>

                    <div class="space-y-1.5">
                        <label for="review-title" class="text-sm font-medium">
                            Headline <span class="font-normal text-muted-foreground">(optional)</span>
                        </label>
                        <Input id="review-title" v-model="form.title" :maxlength="reviews.limits.title_max" autocomplete="off" />
                        <p v-if="form.errors.title" class="text-sm text-destructive" role="alert">{{ form.errors.title }}</p>
                    </div>

                    <div class="space-y-1.5">
                        <label for="review-body" class="text-sm font-medium">Your review</label>
                        <Textarea
                            id="review-body"
                            v-model="form.body"
                            rows="5"
                            :maxlength="reviews.limits.body_max"
                            :aria-invalid="Boolean(form.errors.body) || undefined"
                        />
                        <div class="flex justify-between gap-3 text-xs text-muted-foreground">
                            <span>At least {{ reviews.limits.body_min }} characters. Plain text only.</span>
                            <span class="tabular-nums">{{ form.body.length }} / {{ reviews.limits.body_max }}</span>
                        </div>
                        <p v-if="form.errors.body" class="text-sm text-destructive" role="alert">{{ form.errors.body }}</p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Button type="submit" :disabled="form.processing">{{
                            form.processing ? 'Saving…' : mine ? 'Update review' : 'Post review'
                        }}</Button>
                        <Button v-if="editing" type="button" variant="ghost" @click="editing = false">Cancel</Button>
                    </div>
                </form>

                <p v-else-if="!mine && !reviews.can_write" class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                    {{ reviews.message }}
                    <Link v-if="reviews.reason === 'login'" :href="route('login')" class="font-medium text-primary hover:underline">Sign in</Link>
                </p>

                <!-- What others wrote -->
                <div v-if="summary.count > 0" class="space-y-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <p class="text-sm text-muted-foreground">{{ reviews.page.total }} {{ reviews.page.total === 1 ? 'review' : 'reviews' }}</p>
                        <div class="flex items-center gap-2">
                            <label for="review-sort" class="text-sm text-muted-foreground">Sort by</label>
                            <select id="review-sort" :class="selectClass" :value="reviews.sort" @change="setSort">
                                <option v-for="sort in sorts" :key="sort.value" :value="sort.value">{{ sort.label }}</option>
                            </select>
                        </div>
                    </div>

                    <ul class="divide-y">
                        <li v-for="review in reviews.items" :key="review.id" class="space-y-2 py-5 first:pt-0">
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                <StarRating :value="review.rating" />
                                <span v-if="review.title" class="font-medium">{{ review.title }}</span>
                            </div>
                            <p class="text-sm whitespace-pre-line">{{ review.body }}</p>
                            <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                                <span>{{ review.author }}</span>
                                <span aria-hidden="true">·</span>
                                <time :datetime="review.created_at ?? undefined">{{ day(review.created_at) }}</time>
                                <Badge v-if="review.verified_purchase" variant="secondary" class="ml-1">
                                    <BadgeCheck aria-hidden="true" /> Verified purchase
                                </Badge>
                                <Badge v-if="review.mine" variant="outline">Your review</Badge>
                            </p>
                        </li>
                    </ul>

                    <nav v-if="reviews.page.last > 1" class="flex items-center justify-between gap-3" aria-label="Review pages">
                        <Button variant="outline" size="sm" :disabled="reviews.page.current <= 1" @click="goToPage(reviews.page.current - 1)">
                            Previous
                        </Button>
                        <span class="text-sm text-muted-foreground">Page {{ reviews.page.current }} of {{ reviews.page.last }}</span>
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="reviews.page.current >= reviews.page.last"
                            @click="goToPage(reviews.page.current + 1)"
                        >
                            Next
                        </Button>
                    </nav>
                </div>
            </div>
        </div>

        <ConfirmDialog
            :open="confirmingDelete"
            title="Delete your review?"
            description="It will be removed from the product page. You can write a new one afterwards."
            confirm-label="Delete review"
            @update:open="(open) => !open && (confirmingDelete = false)"
            @confirm="remove"
            @cancel="confirmingDelete = false"
        />
    </section>
</template>
