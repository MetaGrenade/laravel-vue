<script setup lang="ts">
import { computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
import Button from '@/components/ui/button/Button.vue';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import { Share2 } from 'lucide-vue-next';
import { useUserTimezone } from '@/composables/useUserTimezone';

type BlogAuthor = {
    id?: number;
    name?: string | null;
    nickname?: string | null;
};

type BlogPayload = {
    id: number;
    title: string;
    slug: string;
    excerpt?: string | null;
    body: string;
    published_at?: string | null;
    user?: BlogAuthor | null;
};

const props = defineProps<{ blog: BlogPayload }>();

const blog = computed(() => props.blog);
const { formatDate } = useUserTimezone();

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

            <!-- Comments Section - this needs to have scaffolding and apis built before being implemented -->
<!--            <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-6 shadow">-->
<!--                <h2 class="mb-4 text-2xl font-bold">Comments</h2>-->
<!--                &lt;!&ndash; Comment Form &ndash;&gt;-->
<!--                <div class="mb-6">-->
<!--                    <h3 class="mb-2 text-lg font-semibold">Leave a Comment</h3>-->
<!--                    <div class="mb-4">-->
<!--                        <Input-->
<!--                            v-model="newComment"-->
<!--                            placeholder="Write your comment here..."-->
<!--                            class="w-full rounded-md"-->
<!--                        />-->
<!--                    </div>-->
<!--                    <Button variant="primary" @click="postComment">-->
<!--                        Post Comment-->
<!--                    </Button>-->
<!--                </div>-->
<!--                &lt;!&ndash; Comment List &ndash;&gt;-->
<!--                <div>-->
<!--                    <div-->
<!--                        v-for="comment in comments"-->
<!--                        :key="comment.id"-->
<!--                        class="mb-4 flex space-x-4 border-b border-sidebar-border/70 dark:border-sidebar-border pb-4"-->
<!--                    >-->
<!--                        <Avatar :src="comment.avatar" alt="comment author" class="h-10 w-10 rounded-full" />-->
<!--                        <div>-->
<!--                            <div class="mb-1 text-sm font-semibold">{{ comment.author }}</div>-->
<!--                            <div class="mb-1 text-xs text-gray-500">{{ comment.postedAt }}</div>-->
<!--                            <div class="text-sm">{{ comment.text }}</div>-->
<!--                            <Button variant="ghost" class="mt-2 flex items-center text-sm">-->
<!--                                <MessageSquare class="mr-1 h-4 w-4" /> Reply-->
<!--                            </Button>-->
<!--                        </div>-->
<!--                    </div>-->
<!--                </div>-->
<!--            </div>-->
            <div class="relative overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-6 shadow">
                <PlaceholderPattern />
                <div class="relative space-y-3">
                    <h2 class="text-2xl font-bold">Comments</h2>
                    <p class="max-w-prose text-sm text-gray-600 dark:text-gray-300">
                        Commenting isn’t available just yet. We’ll light this up once the discussion API is ready.
                    </p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
