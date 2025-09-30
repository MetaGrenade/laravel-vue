<script setup lang="ts">
import { computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
import Button from '@/components/ui/button/Button.vue';
import BlogComments from '@/components/blog/BlogComments.vue';
import { Share2 } from 'lucide-vue-next';
import { useUserTimezone } from '@/composables/useUserTimezone';

type BlogAuthor = {
    id?: number;
    name?: string | null;
    nickname?: string | null;
};

type BlogCommentAuthor = {
    id: number;
    nickname?: string | null;
    name?: string | null;
};

type BlogComment = {
    id: number;
    body: string;
    created_at?: string | null;
    updated_at?: string | null;
    user?: BlogCommentAuthor | null;
};

type BlogPayload = {
    id: number;
    title: string;
    slug: string;
    excerpt?: string | null;
    body: string;
    published_at?: string | null;
    user?: BlogAuthor | null;
    comments?: BlogComment[];
    cover_image?: string | null;
};

const props = defineProps<{ blog: BlogPayload }>();

const blog = computed(() => props.blog);
const { formatDate } = useUserTimezone();

const comments = computed(() => blog.value.comments ?? []);

const coverImage = computed(
    () => blog.value.cover_image ?? '/images/default-cover.jpg',
);

const authorName = computed(() => {
    const author = blog.value.user;

    if (!author) {
        return 'Unknown author';
    }

    return author.name ?? author.nickname ?? 'Unknown author';
});

const publishedAt = computed(() => {
    if (!blog.value.published_at) {
        return null;
    }

    return formatDate(blog.value.published_at, 'MMMM D, YYYY');
});
</script>

<template>
    <AppLayout>
        <Head :title="blog.title" />
        <div class="container mx-auto px-4 py-8">
            <!-- Blog Post Content -->
            <div class="mb-8 rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-6 shadow">
                <div v-if="coverImage" class="mb-6 overflow-hidden rounded-lg">
                    <img :src="coverImage" alt="Blog cover" class="w-full h-64 object-cover" />
                </div>
                <h1 class="mb-3 text-3xl font-bold">{{ blog.title }}</h1>
                <div class="mb-4 text-sm text-gray-500 dark:text-gray-400">
                    <span>By <span class="font-medium text-foreground">{{ authorName }}</span></span>
                    <span v-if="publishedAt"> | Published on {{ publishedAt }}</span>
                </div>
                <p v-if="blog.excerpt" class="mb-6 text-base text-gray-600 dark:text-gray-300">
                    {{ blog.excerpt }}
                </p>
                <div class="prose max-w-none" v-html="blog.body"></div>
            </div>

            <!-- Share Section -->
            <div class="mb-8 flex items-center justify-between rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-4">
                <span class="text-lg font-semibold">Share this post:</span>
                <div class="flex space-x-2">
                    <Button variant="ghost" class="flex items-center">
                        <Share2 class="mr-1 h-4 w-4" /> Facebook
                    </Button>
                    <Button variant="ghost" class="flex items-center">
                        <Share2 class="mr-1 h-4 w-4" /> Twitter
                    </Button>
                    <Button variant="ghost" class="flex items-center">
                        <Share2 class="mr-1 h-4 w-4" /> LinkedIn
                    </Button>
                </div>
            </div>

            <!-- Comments Section -->
            <BlogComments :blog-slug="blog.slug" :initial-comments="comments" />
        </div>
    </AppLayout>
</template>
