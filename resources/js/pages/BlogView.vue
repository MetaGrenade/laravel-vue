<script setup lang="ts">
import { ref } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
import Button from '@/components/ui/button/Button.vue';
import Input from '@/components/ui/input/Input.vue';
import Avatar from '@/components/ui/avatar/Avatar.vue';
// Use MessageSquare instead of ChatBubble for reply icon
import { Share2, MessageSquare } from 'lucide-vue-next';
import PlaceholderPattern from '../components/PlaceholderPattern.vue';

const blogPost = ref({
    id: 1,
    title: "How to Build a Modern Web Application",
    author: "Alice Johnson",
    publishedAt: "2023-07-28",
    content: `<p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Nullam eget fermentum mauris. Vivamus euismod, ligula vel luctus hendrerit, risus erat dapibus justo, a varius justo odio et mauris. Pellentesque habitant morbi tristique senectus et netus et malesuada fames ac turpis egestas.</p>
  <p>Suspendisse potenti. In hac habitasse platea dictumst. Mauris quis sollicitudin turpis. Donec in sapien non eros tincidunt tincidunt. Cras egestas consectetur dui, eget cursus magna fermentum a.</p>`,
});

const comments = ref([
    {
        id: 1,
        author: "Bob Smith",
        avatar: "/images/avatar1.png",
        postedAt: "2023-07-28 10:15 AM",
        text: "Great article! Really enjoyed reading it.",
    },
    {
        id: 2,
        author: "Charlie Brown",
        avatar: "/images/avatar2.png",
        postedAt: "2023-07-28 11:30 AM",
        text: "I have a question regarding the authentication section.",
    },
]);

const newComment = ref("");

function postComment() {
    if (newComment.value.trim() === "") return;
    comments.value.push({
        id: Date.now(),
        author: "Current User",
        avatar: "/images/avatar-placeholder.png",
        postedAt: new Date().toLocaleString(),
        text: newComment.value,
    });
    newComment.value = "";
}
</script>

<template>
    <AppLayout>
        <Head title="Blog Post" />
        <div class="container mx-auto px-4 py-8">
            <!-- Blog Post Content -->
            <div class="mb-8 rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-6 shadow">
                <h1 class="mb-2 text-3xl font-bold">{{ blogPost.title }}</h1>
                <div class="mb-4 text-sm text-gray-500">
                    By <span class="font-medium">{{ blogPost.author }}</span> | Published on {{ blogPost.publishedAt }}
                </div>
                <div class="prose" v-html="blogPost.content"></div>
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
            <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-6 shadow">
                <h2 class="mb-4 text-2xl font-bold">Comments</h2>
                <!-- Comment Form -->
                <div class="mb-6">
                    <h3 class="mb-2 text-lg font-semibold">Leave a Comment</h3>
                    <div class="mb-4">
                        <Input
                            v-model="newComment"
                            placeholder="Write your comment here..."
                            class="w-full rounded-md"
                        />
                    </div>
                    <Button variant="primary" @click="postComment">
                        Post Comment
                    </Button>
                </div>
                <!-- Comment List -->
                <div>
                    <div
                        v-for="comment in comments"
                        :key="comment.id"
                        class="mb-4 flex space-x-4 border-b border-sidebar-border/70 dark:border-sidebar-border pb-4"
                    >
                        <Avatar :src="comment.avatar" alt="comment author" class="h-10 w-10 rounded-full" />
                        <div>
                            <div class="mb-1 text-sm font-semibold">{{ comment.author }}</div>
                            <div class="mb-1 text-xs text-gray-500">{{ comment.postedAt }}</div>
                            <div class="text-sm">{{ comment.text }}</div>
                            <Button variant="ghost" class="mt-2 flex items-center text-sm">
                                <MessageSquare class="mr-1 h-4 w-4" /> Reply
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
