<script setup lang="ts">
import { computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import Button from '@/components/ui/button/Button.vue';
import BlogComments from '@/components/blog/BlogComments.vue';
import { Share2 } from 'lucide-vue-next';
import { useUserTimezone } from '@/composables/useUserTimezone';

type BlogTaxonomyItem = {
    id: number;
    name: string;
    slug: string;
};

type BlogAuthor = {
    id?: number;
    nickname?: string | null;
};

type BlogCommentAuthor = {
    id: number;
    nickname?: string | null;
};

type BlogComment = {
    id: number;
    body: string;
    created_at?: string | null;
    updated_at?: string | null;
    user?: BlogCommentAuthor | null;
};

type PaginationMeta = {
    current_page: number;
    from?: number | null;
    last_page: number;
    per_page: number;
    to?: number | null;
    total: number;
};

type PaginationLinks = {
    first?: string | null;
    last?: string | null;
    prev?: string | null;
    next?: string | null;
};

type PaginatedComments = {
    data: BlogComment[];
    meta: PaginationMeta;
    links: PaginationLinks;
};

type RecommendedPost = {
    id: number;
    title: string;
    slug: string;
    excerpt?: string | null;
    cover_image?: string | null;
    published_at?: string | null;
};

type BlogPayload = {
    id: number;
    title: string;
    slug: string;
    excerpt?: string | null;
    body: string;
    published_at?: string | null;
    user?: BlogAuthor | null;
    comments?: PaginatedComments;
    cover_image?: string | null;
    categories?: BlogTaxonomyItem[];
    tags?: BlogTaxonomyItem[];
    canonical_url?: string | null;
    recommendations?: RecommendedPost[];
};

const props = defineProps<{ blog: BlogPayload }>();

const blog = computed(() => props.blog);
const { formatDate } = useUserTimezone();

const comments = computed<PaginatedComments>(() => {
    if (blog.value.comments) {
        return blog.value.comments;
    }

    return {
        data: [],
        meta: {
            current_page: 1,
            from: null,
            last_page: 1,
            per_page: 10,
            to: null,
            total: 0,
        },
        links: {
            first: null,
            last: null,
            prev: null,
            next: null,
        },
    };
});
const categories = computed(() => blog.value.categories ?? []);
const tags = computed(() => blog.value.tags ?? []);
const recommendations = computed<RecommendedPost[]>(() => blog.value.recommendations ?? []);

const coverImage = computed(
    () => blog.value.cover_image ?? '/images/default-cover.jpg',
);
const metaDescription = computed(() => blog.value.excerpt ?? '');

const authorName = computed(() => blog.value.user?.nickname ?? 'Unknown author');

const publishedAt = computed(() => {
    if (!blog.value.published_at) {
        return null;
    }

    return formatDate(blog.value.published_at, 'MMMM D, YYYY');
});

const buildAbsoluteUrl = (path: string) => {
    if (typeof window === 'undefined') {
        return path;
    }

    try {
        return new URL(path, window.location.origin).toString();
    } catch {
        return path;
    }
};

const shareUrl = computed(() => buildAbsoluteUrl(route('blogs.view', { slug: blog.value.slug })));
const canonicalUrl = computed(() => blog.value.canonical_url ?? shareUrl.value);
const encodedShareUrl = computed(() => encodeURIComponent(shareUrl.value));
const encodedTitle = computed(() => encodeURIComponent(blog.value.title));
const metaImage = computed(() => {
    const image = blog.value.cover_image;

    if (!image) {
        return null;
    }

    return buildAbsoluteUrl(image);
});
const twitterCardType = computed(() => (metaImage.value ? 'summary_large_image' : 'summary'));
const metaAuthor = computed(() => authorName.value);

const shareLinks = computed(() => ({
    facebook: `https://www.facebook.com/sharer/sharer.php?u=${encodedShareUrl.value}`,
    twitter: `https://twitter.com/intent/tweet?url=${encodedShareUrl.value}&text=${encodedTitle.value}`,
    linkedin: `https://www.linkedin.com/sharing/share-offsite/?url=${encodedShareUrl.value}`,
}));
</script>

<template>
    <AppLayout>
        <Head :title="blog.title">
            <meta v-if="metaDescription" name="description" :content="metaDescription" />
            <link rel="canonical" :href="canonicalUrl" />
            <meta property="og:type" content="article" />
            <meta property="og:title" :content="blog.title" />
            <meta v-if="metaDescription" property="og:description" :content="metaDescription" />
            <meta property="og:url" :content="canonicalUrl" />
            <meta v-if="metaImage" property="og:image" :content="metaImage" />
            <meta property="article:author" :content="metaAuthor" />
            <meta name="twitter:card" :content="twitterCardType" />
            <meta name="twitter:title" :content="blog.title" />
            <meta v-if="metaDescription" name="twitter:description" :content="metaDescription" />
            <meta v-if="metaImage" name="twitter:image" :content="metaImage" />
            <meta name="twitter:creator" :content="metaAuthor" />
        </Head>
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
                <div v-if="categories.length || tags.length" class="mb-4 flex flex-wrap gap-2 text-xs">
                    <Link
                        v-for="category in categories"
                        :key="`category-${category.id}`"
                        :href="route('blogs.index', { category: category.slug })"
                        class="inline-flex items-center rounded-full border border-primary/30 bg-primary/10 px-3 py-1 font-medium text-primary transition hover:border-primary hover:bg-primary/20"
                    >
                        {{ category.name }}
                    </Link>
                    <Link
                        v-for="tag in tags"
                        :key="`tag-${tag.id}`"
                        :href="route('blogs.index', { tag: tag.slug })"
                        class="inline-flex items-center rounded-full border border-muted-foreground/30 bg-muted px-3 py-1 font-medium text-muted-foreground transition hover:border-muted-foreground/60 hover:bg-muted/80"
                    >
                        #{{ tag.name }}
                    </Link>
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
                    <Button
                        as="a"
                        :href="shareLinks.facebook"
                        target="_blank"
                        rel="noopener noreferrer"
                        variant="ghost"
                        class="flex items-center"
                    >
                        <Share2 class="mr-1 h-4 w-4" aria-hidden="true" />
                        <span class="sr-only">Share on Facebook</span>
                        <span aria-hidden="true">Facebook</span>
                    </Button>
                    <Button
                        as="a"
                        :href="shareLinks.twitter"
                        target="_blank"
                        rel="noopener noreferrer"
                        variant="ghost"
                        class="flex items-center"
                    >
                        <Share2 class="mr-1 h-4 w-4" aria-hidden="true" />
                        <span class="sr-only">Share on Twitter</span>
                        <span aria-hidden="true">Twitter</span>
                    </Button>
                    <Button
                        as="a"
                        :href="shareLinks.linkedin"
                        target="_blank"
                        rel="noopener noreferrer"
                        variant="ghost"
                        class="flex items-center"
                    >
                        <Share2 class="mr-1 h-4 w-4" aria-hidden="true" />
                        <span class="sr-only">Share on LinkedIn</span>
                        <span aria-hidden="true">LinkedIn</span>
                    </Button>
                </div>
            </div>

            <!-- Recommendations Section -->
            <div
                v-if="recommendations.length"
                class="mb-8 rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-6 shadow"
            >
                <h2 class="mb-4 text-2xl font-semibold">Recommended articles</h2>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <Link
                        v-for="post in recommendations"
                        :key="post.id"
                        :href="route('blogs.view', { slug: post.slug })"
                        class="group flex h-full flex-col overflow-hidden rounded-lg border border-border/70 bg-card transition hover:border-primary hover:shadow-lg"
                    >
                        <div class="aspect-video w-full overflow-hidden bg-muted">
                            <img
                                v-if="post.cover_image"
                                :src="post.cover_image"
                                :alt="`Cover image for ${post.title}`"
                                class="h-full w-full object-cover transition duration-300 group-hover:scale-105"
                            />
                            <div v-else class="flex h-full w-full items-center justify-center text-sm text-muted-foreground">
                                No cover image
                            </div>
                        </div>
                        <div class="flex flex-1 flex-col p-4">
                            <h3 class="mb-2 text-lg font-semibold text-foreground group-hover:text-primary">
                                {{ post.title }}
                            </h3>
                            <p v-if="post.excerpt" class="mb-3 line-clamp-3 text-sm text-muted-foreground">
                                {{ post.excerpt }}
                            </p>
                            <span
                                v-if="post.published_at"
                                class="mt-auto text-xs uppercase tracking-wide text-muted-foreground"
                            >
                                {{ formatDate(post.published_at, 'MMMM D, YYYY') }}
                            </span>
                        </div>
                    </Link>
                </div>
            </div>

            <!-- Comments Section -->
            <BlogComments :blog-slug="blog.slug" :initial-comments="comments" />
        </div>
    </AppLayout>
</template>
