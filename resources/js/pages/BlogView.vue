<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import Button from '@/components/ui/button/Button.vue';
import BlogComments from '@/components/blog/BlogComments.vue';
import { Share2 } from 'lucide-vue-next';
import { useUserTimezone } from '@/composables/useUserTimezone';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { toast } from 'vue-sonner';

type BlogTaxonomyItem = {
    id: number;
    name: string;
    slug: string;
};

type AuthorSocialLink = {
    label: string;
    url: string;
};

type BlogAuthor = {
    id?: number;
    nickname?: string | null;
    avatar_url?: string | null;
    profile_bio?: string | null;
    social_links?: AuthorSocialLink[];
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

type CommentSubscriptionPayload = {
    is_subscribed: boolean;
    subscribers_count: number;
};

type RecommendedPost = {
    id: number;
    title: string;
    slug: string;
    excerpt?: string | null;
    cover_image?: string | null;
    published_at?: string | null;
    views?: number;
    last_viewed_at?: string | null;
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
    comment_subscription?: CommentSubscriptionPayload;
};

type PageProps = {
    auth: {
        user: {
            id: number;
            nickname?: string | null;
        } | null;
    };
};

const props = defineProps<{ blog: BlogPayload }>();

const blog = computed(() => props.blog);
const { formatDate, fromNow } = useUserTimezone();
const numberFormatter = new Intl.NumberFormat();
const formatNumber = (value: number | null | undefined) => numberFormatter.format(value ?? 0);
const lastViewedAgo = computed(() =>
    blog.value.last_viewed_at ? fromNow(blog.value.last_viewed_at) : null,
);

const page = usePage<PageProps>();
const authUser = computed(() => page.props.auth?.user ?? null);

const commentSubscription = computed<CommentSubscriptionPayload>(() => {
    const subscription = blog.value.comment_subscription;

    return {
        is_subscribed: subscription?.is_subscribed ?? false,
        subscribers_count: subscription?.subscribers_count ?? 0,
    };
});

const isSubscribedToComments = ref(commentSubscription.value.is_subscribed);
const subscriptionLoading = ref(false);
const subscribersCount = ref(commentSubscription.value.subscribers_count);

watch(
    () => blog.value.comment_subscription,
    (value) => {
        isSubscribedToComments.value = value?.is_subscribed ?? false;
        subscribersCount.value = value?.subscribers_count ?? 0;
    },
);

const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';

const extractSubscriptionError = async (response: Response): Promise<string> => {
    try {
        const payload = await response.json();

        if (payload?.message) {
            return payload.message;
        }

        if (payload?.errors) {
            const firstError = Object.values(payload.errors)[0];

            if (Array.isArray(firstError) && firstError.length > 0) {
                return String(firstError[0]);
            }
        }
    } catch (error) {
        console.error(error);
    }

    if (response.status === 401) {
        return 'You need to sign in to manage notifications.';
    }

    if (response.status === 403) {
        return 'You are not allowed to manage notifications for this post.';
    }

    return 'We could not update your notification settings. Please try again.';
};

const subscribeToComments = async () => {
    if (!authUser.value) {
        toast.error('Sign in to get notified about new replies.');
        return;
    }

    if (subscriptionLoading.value) {
        return;
    }

    subscriptionLoading.value = true;

    try {
        const response = await fetch(route('blogs.comments.subscriptions.store', { blog: blog.value.slug }), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken,
            },
        });

        if (!response.ok) {
            const message = await extractSubscriptionError(response);
            toast.error(message);
            return;
        }

        const payload = await response.json();

        isSubscribedToComments.value = payload?.subscribed ?? true;
        subscribersCount.value = payload?.subscribers_count ?? subscribersCount.value;
        toast.success('You will be notified about new replies.');
    } catch (error) {
        console.error(error);
        toast.error('Unable to update your notification settings right now.');
    } finally {
        subscriptionLoading.value = false;
    }
};

const unsubscribeFromComments = async () => {
    if (!authUser.value) {
        toast.error('Sign in to manage your notifications.');
        return;
    }

    if (subscriptionLoading.value) {
        return;
    }

    subscriptionLoading.value = true;

    try {
        const response = await fetch(route('blogs.comments.subscriptions.destroy', { blog: blog.value.slug }), {
            method: 'DELETE',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken,
            },
        });

        if (!response.ok) {
            const message = await extractSubscriptionError(response);
            toast.error(message);
            return;
        }

        const payload = await response.json();

        isSubscribedToComments.value = payload?.subscribed ?? false;
        subscribersCount.value = payload?.subscribers_count ?? subscribersCount.value;
        toast.success('You will no longer receive alerts for new replies.');
    } catch (error) {
        console.error(error);
        toast.error('Unable to update your notification settings right now.');
    } finally {
        subscriptionLoading.value = false;
    }
};

const author = computed<BlogAuthor | null>(() => blog.value.user ?? null);

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

const authorName = computed(() => author.value?.nickname ?? 'Unknown author');

const authorAvatarUrl = computed(() => author.value?.avatar_url ?? '');

const authorInitials = computed(() => {
    const name = authorName.value.trim();

    if (!name) {
        return '?';
    }

    return name
        .split(' ')
        .filter(Boolean)
        .map((part) => part[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();
});

const authorBio = computed(() => {
    const bio = author.value?.profile_bio ?? '';

    return typeof bio === 'string' ? bio.trim() : '';
});

const hasAuthorBio = computed(() => authorBio.value.trim().length > 0);

const authorSocialLinks = computed<AuthorSocialLink[]>(() => {
    const links = author.value?.social_links ?? [];

    return links
        .map((link) => {
            const label = typeof link.label === 'string' ? link.label.trim() : '';
            const url = typeof link.url === 'string' ? link.url.trim() : '';

            return { label, url };
        })
        .filter((link) => link.label.length > 0 && link.url.length > 0);
});

const hasAuthorSocialLinks = computed(() => authorSocialLinks.value.length > 0);

const showAuthorCard = computed(() => Boolean(author.value));

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
                    <span v-if="typeof blog.views === 'number'"> | {{ formatNumber(blog.views) }} views</span>
                    <span v-if="lastViewedAgo"> | Last read {{ lastViewedAgo }}</span>
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

            <div
                v-if="showAuthorCard"
                class="mb-8 rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-6 shadow"
            >
                <div class="flex flex-col items-center gap-4 sm:flex-row sm:items-start">
                    <Avatar class="h-20 w-20">
                        <AvatarImage v-if="authorAvatarUrl" :src="authorAvatarUrl" :alt="authorName" />
                        <AvatarFallback>{{ authorInitials }}</AvatarFallback>
                    </Avatar>
                    <div class="flex-1 space-y-4 text-center sm:text-left">
                        <div class="space-y-1">
                            <h2 class="text-xl font-semibold text-foreground">About {{ authorName }}</h2>
                            <p class="text-sm text-muted-foreground">
                                Insights from one of our community storytellers.
                            </p>
                        </div>
                        <p
                            v-if="hasAuthorBio"
                            class="text-sm leading-relaxed text-muted-foreground whitespace-pre-line"
                        >
                            {{ authorBio }}
                        </p>
                        <div
                            v-if="hasAuthorSocialLinks"
                            class="flex flex-wrap justify-center gap-2 sm:justify-start"
                        >
                            <a
                                v-for="link in authorSocialLinks"
                                :key="`${link.label}-${link.url}`"
                                :href="link.url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-2 rounded-md border border-primary/40 bg-primary/10 px-3 py-1 text-sm font-medium text-primary transition hover:border-primary hover:bg-primary/20"
                            >
                                <span>{{ link.label }}</span>
                            </a>
                        </div>
                    </div>
                </div>
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

            <div class="mb-8 rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-6 shadow">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="space-y-1">
                        <h2 class="text-xl font-semibold text-foreground">Stay in the loop</h2>
                        <p class="text-sm text-muted-foreground">
                            <template v-if="authUser">
                                We'll send you a quick alert whenever someone replies here.
                            </template>
                            <template v-else>
                                Sign in to receive alerts when the conversation continues.
                            </template>
                        </p>
                    </div>
                    <div class="flex flex-col items-start gap-2 sm:items-end">
                        <span class="text-xs text-muted-foreground">
                            {{ subscribersCount }}
                            {{ subscribersCount === 1 ? 'person is following replies' : 'people are following replies' }}
                        </span>
                        <Button
                            v-if="authUser"
                            :variant="isSubscribedToComments ? 'default' : 'outline'"
                            :disabled="subscriptionLoading"
                            @click="isSubscribedToComments ? unsubscribeFromComments() : subscribeToComments()"
                        >
                            <span v-if="subscriptionLoading">
                                {{ isSubscribedToComments ? 'Updating…' : 'Subscribing…' }}
                            </span>
                            <span v-else>
                                {{ isSubscribedToComments ? 'Following replies' : 'Notify me about replies' }}
                            </span>
                        </Button>
                        <Button v-else as="a" :href="route('login')" variant="outline">
                            Sign in to subscribe
                        </Button>
                    </div>
                </div>
            </div>

            <!-- Comments Section -->
            <BlogComments :blog-slug="blog.slug" :initial-comments="comments" />
        </div>
    </AppLayout>
</template>
