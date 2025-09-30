<script setup lang="ts">
import { computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { CalendarClock } from 'lucide-vue-next';
import { useUserTimezone } from '@/composables/useUserTimezone';

interface BlogPreviewPayload {
    id: number;
    title: string;
    slug: string;
    excerpt?: string | null;
    body: string;
    cover_image?: string | null;
    published_at?: string | null;
    scheduled_for?: string | null;
    status: string;
    user?: {
        id?: number;
        nickname?: string | null;
    } | null;
    categories?: Array<{ id: number; name: string; slug: string }>;
    tags?: Array<{ id: number; name: string; slug: string }>;
}

const props = defineProps<{ blog: BlogPreviewPayload }>();

const { formatDate } = useUserTimezone();
const blog = computed(() => props.blog);

const coverImage = computed(() => blog.value.cover_image ?? '/images/default-cover.jpg');

const authorName = computed(() => blog.value.user?.nickname ?? 'Unknown author');

const scheduledMessage = computed(() => {
    if (blog.value.status === 'scheduled' && blog.value.scheduled_for) {
        return `Scheduled for ${formatDate(blog.value.scheduled_for, 'MMMM D, YYYY h:mm A')}`;
    }

    if (blog.value.status === 'draft') {
        return 'Draft — not visible to readers yet.';
    }

    if (blog.value.status === 'archived') {
        return 'Archived — this preview is only available to editors.';
    }

    if (blog.value.published_at) {
        return `Published on ${formatDate(blog.value.published_at, 'MMMM D, YYYY h:mm A')}`;
    }

    return 'This post is ready for review.';
});

const categories = computed(() => blog.value.categories ?? []);
const tags = computed(() => blog.value.tags ?? []);
</script>

<template>
    <AppLayout>
        <Head :title="`${blog.title} (Preview)`" />
        <div class="container mx-auto px-4 py-8">
            <Alert class="mb-6">
                <component :is="CalendarClock" class="h-5 w-5" />
                <AlertTitle>Editor preview</AlertTitle>
                <AlertDescription>
                    {{ scheduledMessage }}
                </AlertDescription>
            </Alert>

            <div class="mb-8 rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-6 shadow">
                <div v-if="coverImage" class="mb-6 overflow-hidden rounded-lg">
                    <img :src="coverImage" alt="Blog cover" class="h-64 w-full object-cover" />
                </div>
                <h1 class="mb-3 text-3xl font-bold">{{ blog.title }}</h1>
                <div class="mb-4 text-sm text-gray-500 dark:text-gray-400">
                    <span>By <span class="font-medium text-foreground">{{ authorName }}</span></span>
                </div>
                <p v-if="blog.excerpt" class="mb-6 text-base text-gray-600 dark:text-gray-300">
                    {{ blog.excerpt }}
                </p>
                <div class="prose max-w-none" v-html="blog.body"></div>
            </div>

            <div v-if="categories.length || tags.length" class="mb-8 rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-4">
                <h2 class="mb-3 text-lg font-semibold">Metadata</h2>
                <div class="flex flex-wrap gap-2 text-xs">
                    <span
                        v-for="category in categories"
                        :key="`category-${category.id}`"
                        class="inline-flex items-center rounded-full border border-primary/30 bg-primary/10 px-3 py-1 font-medium text-primary"
                    >
                        {{ category.name }}
                    </span>
                    <span
                        v-for="tag in tags"
                        :key="`tag-${tag.id}`"
                        class="inline-flex items-center rounded-full border border-muted-foreground/30 bg-muted px-3 py-1 font-medium text-muted-foreground"
                    >
                        #{{ tag.name }}
                    </span>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
