<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import InputError from '@/components/InputError.vue';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import { useUserTimezone } from '@/composables/useUserTimezone';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { CalendarClock, Eye, Link as LinkIcon } from 'lucide-vue-next';

type BlogTaxonomyOption = {
    id: number;
    name: string;
    slug: string;
};

type BlogStatus = 'draft' | 'scheduled' | 'published' | 'archived';

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

type BlogPayload = {
    id: number;
    title: string;
    slug: string;
    excerpt?: string | null;
    body: string;
    status: BlogStatus;
    created_at?: string;
    updated_at?: string;
    published_at?: string | null;
    scheduled_for?: string | null;
    cover_image?: string | null;
    cover_image_url?: string | null;
    preview_url?: string | null;
    categories: BlogTaxonomyOption[];
    tags: BlogTaxonomyOption[];
    user?: BlogAuthor | null;
};

type BlogRevisionMetadata = {
    status?: string | null;
    slug?: string | null;
    published_at?: string | null;
    scheduled_for?: string | null;
};

type BlogRevisionEditor = {
    id: number;
    nickname?: string | null;
    email?: string | null;
};

type BlogRevisionPayload = {
    id: number;
    title: string;
    excerpt?: string | null;
    created_at?: string | null;
    metadata?: BlogRevisionMetadata | null;
    editor?: BlogRevisionEditor | null;
};

type BlogForm = {
    title: string;
    excerpt: string;
    body: string;
    status: BlogStatus;
    cover_image: File | null;
    category_ids: number[];
    tag_ids: number[];
    scheduled_for: string;
    author: {
        avatar_url: string;
        profile_bio: string;
        social_links: AuthorSocialLink[];
    };
};

const props = defineProps<{
    blog: BlogPayload;
    categories: BlogTaxonomyOption[];
    tags: BlogTaxonomyOption[];
    revisions: BlogRevisionPayload[];
    can_restore_revisions: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Blogs ACP', href: route('acp.blogs.index') },
    { title: props.blog.title, href: route('acp.blogs.edit', { blog: props.blog.id }) },
];

const statusOptions: Array<{ label: string; value: BlogStatus }> = [
    { label: 'Draft', value: 'draft' },
    { label: 'Published', value: 'published' },
    { label: 'Archived', value: 'archived' },
];

const form = useForm<BlogForm>({
    title: props.blog.title ?? '',
    excerpt: props.blog.excerpt ?? '',
    body: props.blog.body ?? '',
    status: props.blog.status ?? 'draft',
    cover_image: null,
    category_ids: props.blog.categories?.map((category) => category.id) ?? [],
    tag_ids: props.blog.tags?.map((tag) => tag.id) ?? [],
    scheduled_for: '',
    author: {
        avatar_url: props.blog.user?.avatar_url ?? '',
        profile_bio: props.blog.user?.profile_bio ?? '',
        social_links:
            props.blog.user?.social_links?.map((link) => ({
                label: typeof link?.label === 'string' ? link.label : '',
                url: typeof link?.url === 'string' ? link.url : '',
            })) ?? [],
    },
});

const { formatDate } = useUserTimezone();

const categoryOptions = ref<BlogTaxonomyOption[]>([]);
const refreshingCategories = ref(false);
const refreshCategoriesError = ref('');

const tagOptions = ref<BlogTaxonomyOption[]>([]);
const refreshingTags = ref(false);
const refreshTagsError = ref('');

const normalizeCategoryData = (input: unknown[]): BlogTaxonomyOption[] => {
    return input
        .map((raw) => {
            if (!raw || typeof raw !== 'object') {
                return null;
            }

            const candidate = raw as Record<string, unknown>;
            const id = candidate.id;
            const name = candidate.name;
            const slug = candidate.slug;

            if (typeof id !== 'number' || typeof name !== 'string' || typeof slug !== 'string') {
                return null;
            }

            return { id, name, slug };
        })
        .filter((category): category is BlogTaxonomyOption => Boolean(category));
};

const applyCategoryOptions = (categories: BlogTaxonomyOption[]) => {
    const normalized = categories.map((category) => ({ ...category }));
    categoryOptions.value = normalized;

    const validIds = new Set(normalized.map((category) => category.id));
    form.category_ids = form.category_ids.filter((id) => validIds.has(id));
};

applyCategoryOptions([...props.categories]);

watch(
    () => props.categories,
    (categories) => {
        applyCategoryOptions([...categories]);
    },
    { deep: true },
);

const normalizeTagData = (input: unknown[]): BlogTaxonomyOption[] => {
    return input
        .map((raw) => {
            if (!raw || typeof raw !== 'object') {
                return null;
            }

            const candidate = raw as Record<string, unknown>;
            const id = candidate.id;
            const name = candidate.name;
            const slug = candidate.slug;

            if (typeof id !== 'number' || typeof name !== 'string' || typeof slug !== 'string') {
                return null;
            }

            return { id, name, slug };
        })
        .filter((tag): tag is BlogTaxonomyOption => Boolean(tag));
};

const applyTagOptions = (tags: BlogTaxonomyOption[]) => {
    const normalized = tags.map((tag) => ({ ...tag }));
    tagOptions.value = normalized;

    const validIds = new Set(normalized.map((tag) => tag.id));
    form.tag_ids = form.tag_ids.filter((id) => validIds.has(id));
};

applyTagOptions([...props.tags]);

watch(
    () => props.tags,
    (tags) => {
        applyTagOptions([...tags]);
    },
    { deep: true },
);

const addAuthorSocialLink = () => {
    form.author.social_links.push({ label: '', url: '' });
};

const removeAuthorSocialLink = (index: number) => {
    form.author.social_links.splice(index, 1);
};

const extractRawTags = (payload: unknown): unknown[] => {
    if (Array.isArray(payload)) {
        return payload;
    }

    if (payload && typeof payload === 'object' && 'tags' in payload) {
        const tagsValue = (payload as Record<string, unknown>).tags;

        return Array.isArray(tagsValue) ? tagsValue : [];
    }

    return [];
};

const extractRawCategories = (payload: unknown): unknown[] => {
    if (Array.isArray(payload)) {
        return payload;
    }

    if (payload && typeof payload === 'object' && 'categories' in payload) {
        const categoriesValue = (payload as Record<string, unknown>).categories;

        return Array.isArray(categoriesValue) ? categoriesValue : [];
    }

    return [];
};

const refreshTags = async () => {
    if (refreshingTags.value) {
        return;
    }

    refreshingTags.value = true;
    refreshTagsError.value = '';

    try {
        const response = await fetch(route('acp.blog-tags.index'), {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (!response.ok) {
            throw new Error(`Request failed with status ${response.status}`);
        }

        const payload: unknown = await response.json();
        const normalized = normalizeTagData(extractRawTags(payload));
        applyTagOptions(normalized);
    } catch (error) {
        console.error('Failed to refresh tags', error);
        refreshTagsError.value = 'Unable to refresh tags. Please try again.';
    } finally {
        refreshingTags.value = false;
    }
};

const refreshCategories = async () => {
    if (refreshingCategories.value) {
        return;
    }

    refreshingCategories.value = true;
    refreshCategoriesError.value = '';

    try {
        const response = await fetch(route('acp.blog-categories.index'), {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (!response.ok) {
            throw new Error(`Request failed with status ${response.status}`);
        }

        const payload: unknown = await response.json();
        const normalized = normalizeCategoryData(extractRawCategories(payload));
        applyCategoryOptions(normalized);
    } catch (error) {
        console.error('Failed to refresh categories', error);
        refreshCategoriesError.value = 'Unable to refresh categories. Please try again.';
    } finally {
        refreshingCategories.value = false;
    }
};

const createdAt = computed(() =>
    props.blog.created_at ? formatDate(props.blog.created_at) : '—'
);
const updatedAt = computed(() =>
    props.blog.updated_at ? formatDate(props.blog.updated_at) : '—'
);
const publishedAt = computed(() =>
    props.blog.published_at ? formatDate(props.blog.published_at) : 'Not published'
);
const scheduledAt = computed(() =>
    props.blog.scheduled_for ? formatDate(props.blog.scheduled_for) : '—'
);

const coverImagePreview = ref<string | null>(null);
const existingCoverImage = computed(() => coverImagePreview.value ?? props.blog.cover_image_url ?? null);

const previewOpen = ref(false);
const previewCopied = ref(false);

const formatForInput = (date: Date) => {
    const pad = (value: number) => value.toString().padStart(2, '0');
    const year = date.getFullYear();
    const month = pad(date.getMonth() + 1);
    const day = pad(date.getDate());
    const hours = pad(date.getHours());
    const minutes = pad(date.getMinutes());

    return `${year}-${month}-${day}T${hours}:${minutes}`;
};

const toInputValue = (value?: string | null) => {
    if (!value) {
        return '';
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return formatForInput(date);
};

const defaultScheduledAt = () => {
    const date = new Date();
    date.setMinutes(date.getMinutes() + 30);
    date.setSeconds(0, 0);

    return formatForInput(date);
};

const minScheduleValue = computed(() => formatForInput(new Date()));

form.scheduled_for = props.blog.status === 'scheduled' ? toInputValue(props.blog.scheduled_for) : '';

watch(
    () => form.status,
    (status) => {
        if (status === 'scheduled' && !form.scheduled_for) {
            form.scheduled_for =
                props.blog.status === 'scheduled' && props.blog.scheduled_for
                    ? toInputValue(props.blog.scheduled_for)
                    : defaultScheduledAt();
        }

        if (status !== 'scheduled') {
            form.scheduled_for = '';
        }
    },
    { immediate: true },
);

const selectedCategories = computed(() =>
    categoryOptions.value.filter((category) => form.category_ids.includes(category.id)),
);
const selectedTags = computed(() => tagOptions.value.filter((tag) => form.tag_ids.includes(tag.id)));

const previewCover = computed(() => existingCoverImage.value ?? '/images/default-cover.jpg');
const previewScheduledMessage = computed(() => {
    if (form.status === 'scheduled' && form.scheduled_for) {
        return `Scheduled for ${formatDate(form.scheduled_for, 'MMMM D, YYYY h:mm A')}`;
    }

    if (form.status === 'draft') {
        return 'Draft — this post will remain private until published.';
    }

    if (form.status === 'archived') {
        return 'Archived — this post is hidden from readers.';
    }

    if (form.status === 'published') {
        return 'Published — updates will go live immediately after saving.';
    }

    return 'Ready to publish when you save changes.';
});

const previewLink = computed(() => props.blog.preview_url ?? null);

let copyTimeout: ReturnType<typeof setTimeout> | null = null;

const restoringRevisionId = ref<number | null>(null);

const formatRevisionDate = (value?: string | null) => {
    if (!value) {
        return '—';
    }

    return formatDate(value);
};

const formatRevisionStatus = (status?: string | null) => {
    if (!status) {
        return null;
    }

    return status.charAt(0).toUpperCase() + status.slice(1);
};

const restoreRevision = (revisionId: number) => {
    if (!props.can_restore_revisions) {
        return;
    }

    if (restoringRevisionId.value !== null) {
        return;
    }

    const confirmed = typeof window === 'undefined' ? true : window.confirm(
        'Restore this revision? This will overwrite the current blog content.'
    );

    if (!confirmed) {
        return;
    }

    restoringRevisionId.value = revisionId;

    router.post(
        route('acp.blogs.revisions.restore', { blog: props.blog.id, revision: revisionId }),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                restoringRevisionId.value = null;
            },
        },
    );
};

const copyPreviewLink = async () => {
    if (!previewLink.value) {
        return;
    }

    try {
        await navigator.clipboard.writeText(previewLink.value);
        previewCopied.value = true;

        if (copyTimeout) {
            clearTimeout(copyTimeout);
        }

        copyTimeout = setTimeout(() => {
            previewCopied.value = false;
        }, 2000);
    } catch {
        previewCopied.value = false;
    }
};

const handleCoverImageChange = (event: Event) => {
    const target = event.target as HTMLInputElement;
    const file = target.files?.[0] ?? null;

    if (coverImagePreview.value) {
        URL.revokeObjectURL(coverImagePreview.value);
    }

    form.cover_image = file;
    coverImagePreview.value = file ? URL.createObjectURL(file) : null;
};

onBeforeUnmount(() => {
    if (coverImagePreview.value) {
        URL.revokeObjectURL(coverImagePreview.value);
    }

    if (copyTimeout) {
        clearTimeout(copyTimeout);
    }
});

const handleSubmit = () => {
    form.transform((data) => {
        const payload: Record<string, unknown> = {
            ...data,
            _method: 'PUT',
        };

        if (!data.cover_image) {
            delete payload.cover_image;
        }

        if (data.status !== 'scheduled') {
            delete payload.scheduled_for;
        }

        return payload;
    });

    form.post(route('acp.blogs.update', { blog: props.blog.id }), {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => {
            form.transform((data) => ({ ...data }));
        },
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`Edit: ${props.blog.title}`" />

        <AdminLayout>
            <form class="flex flex-1 flex-col gap-6" @submit.prevent="handleSubmit">
                <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight">Edit blog post</h1>
                        <p class="text-sm text-muted-foreground">
                            Update the article content or adjust its publication status.
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Button variant="outline" as-child>
                            <Link :href="route('acp.blogs.index')">Back to blogs</Link>
                        </Button>
                        <Button
                            type="button"
                            variant="secondary"
                            class="flex items-center gap-2"
                            @click="previewOpen = true"
                        >
                            <Eye class="h-4 w-4" />
                            Preview changes
                        </Button>
                        <Button
                            v-if="previewLink"
                            variant="outline"
                            as-child
                            class="flex items-center gap-2"
                        >
                            <a :href="previewLink" target="_blank" rel="noopener">
                                <LinkIcon class="h-4 w-4" />
                                Open preview link
                            </a>
                        </Button>
                        <Button type="submit" :disabled="form.processing">Save changes</Button>
                    </div>
                </div>

                <div class="grid gap-6 lg:grid-cols-[minmax(0,_1fr)_320px]">
                    <Card>
                        <CardHeader class="relative overflow-hidden">
                            <PlaceholderPattern class="absolute inset-0 opacity-10" />
                            <div class="relative space-y-1">
                                <CardTitle>Post content</CardTitle>
                                <CardDescription>
                                    Keep the information accurate and engaging for readers.
                                </CardDescription>
                            </div>
                        </CardHeader>
                        <CardContent class="space-y-6">
                            <div class="grid gap-2">
                                <Label for="title">Title</Label>
                                <Input id="title" v-model="form.title" type="text" autocomplete="off" required />
                                <InputError :message="form.errors.title" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="excerpt">Excerpt</Label>
                                <Textarea
                                    id="excerpt"
                                    v-model="form.excerpt"
                                    placeholder="A short summary that will appear on listing pages."
                                    class="min-h-24"
                                />
                                <InputError :message="form.errors.excerpt" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="cover_image">Cover image</Label>
                                <Input
                                    id="cover_image"
                                    type="file"
                                    accept="image/*"
                                    @change="handleCoverImageChange"
                                />
                                <p class="text-xs text-muted-foreground">
                                    Replace the existing banner to refresh how this post appears across the site.
                                </p>
                                <InputError :message="form.errors.cover_image" />

                                <div v-if="existingCoverImage" class="mt-2">
                                    <img
                                        :src="existingCoverImage"
                                        alt="Blog cover preview"
                                        class="h-32 w-full rounded-md object-cover border border-dashed border-muted"
                                    />
                                </div>
                            </div>

                            <div class="grid gap-2">
                                <Label for="body">Body</Label>
                                <Textarea
                                    id="body"
                                    v-model="form.body"
                                    placeholder="Write the full content for the blog post."
                                    class="min-h-48"
                                    required
                                />
                                <InputError :message="form.errors.body" />
                            </div>

                            <div class="grid gap-4">
                                <div class="space-y-3">
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                        <div>
                                            <Label>Categories</Label>
                                            <p class="text-sm text-muted-foreground">
                                                Select the categories that best represent this article.
                                            </p>
                                        </div>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            class="self-start"
                                            :disabled="refreshingCategories"
                                            @click="refreshCategories"
                                        >
                                            <span v-if="refreshingCategories">Refreshing…</span>
                                            <span v-else>Refresh categories</span>
                                        </Button>
                                    </div>
                                    <div class="grid gap-2 sm:grid-cols-2">
                                        <label
                                            v-for="category in categoryOptions"
                                            :key="category.id"
                                            class="flex items-center gap-2 rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm"
                                        >
                                            <input
                                                type="checkbox"
                                                class="h-4 w-4 rounded border-input text-primary focus:ring-primary"
                                                :value="category.id"
                                                v-model="form.category_ids"
                                            />
                                            <span>{{ category.name }}</span>
                                        </label>
                                        <p
                                            v-if="categoryOptions.length === 0"
                                            class="text-sm text-muted-foreground sm:col-span-2"
                                        >
                                            No categories available yet. Add some options to improve navigation.
                                        </p>
                                    </div>
                                    <p v-if="refreshCategoriesError" class="text-xs text-red-500">
                                        {{ refreshCategoriesError }}
                                    </p>
                                    <InputError :message="form.errors.category_ids" />
                                </div>

                                <div class="space-y-3">
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                        <div>
                                            <Label>Tags</Label>
                                            <p class="text-sm text-muted-foreground">
                                                Use tags to surface cross-cutting topics and campaigns.
                                            </p>
                                        </div>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            class="self-start"
                                            :disabled="refreshingTags"
                                            @click="refreshTags"
                                        >
                                            <span v-if="refreshingTags">Refreshing…</span>
                                            <span v-else>Refresh tags</span>
                                        </Button>
                                    </div>
                                    <div class="grid gap-2 sm:grid-cols-2">
                                        <label
                                            v-for="tag in tagOptions"
                                            :key="tag.id"
                                            class="flex items-center gap-2 rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm"
                                        >
                                            <input
                                                type="checkbox"
                                                class="h-4 w-4 rounded border-input text-primary focus:ring-primary"
                                                :value="tag.id"
                                                v-model="form.tag_ids"
                                            />
                                            <span>{{ tag.name }}</span>
                                        </label>
                                        <p v-if="tagOptions.length === 0" class="text-sm text-muted-foreground sm:col-span-2">
                                            No tags configured yet. Seed some to support editorial organization.
                                        </p>
                                    </div>
                                    <p v-if="refreshTagsError" class="text-xs text-red-500">{{ refreshTagsError }}</p>
                                    <InputError :message="form.errors.tag_ids" />
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <div class="flex flex-col gap-6">
                        <Card>
                            <CardHeader>
                                <CardTitle>Publication status</CardTitle>
                                <CardDescription>Change how this post is displayed to readers.</CardDescription>
                            </CardHeader>
                            <CardContent class="space-y-4">
                                <div class="grid gap-2">
                                    <Label for="status">Status</Label>
                                    <select
                                        id="status"
                                        v-model="form.status"
                                        class="
                                            flex h-10 w-full rounded-md border border-input bg-background px-3 py-2
                                            text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2
                                        "
                                    >
                                        <option v-for="option in statusOptions" :key="option.value" :value="option.value">
                                            {{ option.label }}
                                        </option>
                                    </select>
                                    <InputError :message="form.errors.status" />
                                </div>

                                <div v-if="form.status === 'scheduled'" class="grid gap-2">
                                    <Label for="scheduled_for">Schedule for</Label>
                                    <Input
                                        id="scheduled_for"
                                        v-model="form.scheduled_for"
                                        type="datetime-local"
                                        :min="minScheduleValue"
                                        required
                                    />
                                    <p class="text-xs text-muted-foreground">
                                        We'll queue the post to publish automatically at this time.
                                    </p>
                                    <InputError :message="form.errors.scheduled_for" />
                                </div>

                                <p class="text-sm text-muted-foreground">
                                    Publishing immediately sets the article live and records the publish time. Draft posts stay
                                    private, scheduled entries go live automatically, and archived posts remain hidden.
                                </p>
                            </CardContent>
                            <CardFooter class="justify-end">
                                <Button type="submit" :disabled="form.processing">Save changes</Button>
                            </CardFooter>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>Author profile</CardTitle>
                                <CardDescription>
                                    Maintain how {{ props.blog.user?.nickname ?? 'this author' }} appears on the blog.
                                </CardDescription>
                            </CardHeader>
                            <CardContent class="space-y-4">
                                <div class="grid gap-2">
                                    <Label for="author_avatar_url">Avatar URL</Label>
                                    <Input
                                        id="author_avatar_url"
                                        v-model="form.author.avatar_url"
                                        type="url"
                                        placeholder="https://example.com/avatar.png"
                                    />
                                    <InputError :message="form.errors['author.avatar_url']" />
                                </div>

                                <div class="grid gap-2">
                                    <Label for="author_profile_bio">Author bio</Label>
                                    <Textarea
                                        id="author_profile_bio"
                                        v-model="form.author.profile_bio"
                                        placeholder="Share a concise description of the author’s experience."
                                        class="min-h-28"
                                    />
                                    <InputError :message="form.errors['author.profile_bio']" />
                                </div>

                                <div class="space-y-3">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <Label class="text-sm font-medium">Social links</Label>
                                        <Button type="button" variant="outline" size="sm" @click="addAuthorSocialLink">
                                            Add link
                                        </Button>
                                    </div>
                                    <p class="text-xs text-muted-foreground">
                                        List destinations where readers can continue following this author.
                                    </p>

                                    <div v-if="form.author.social_links.length" class="space-y-3">
                                        <div
                                            v-for="(link, index) in form.author.social_links"
                                            :key="`author-social-link-${index}`"
                                            class="space-y-3 rounded-md border border-dashed p-3"
                                        >
                                            <div class="grid gap-3 sm:grid-cols-2 sm:gap-4">
                                                <div class="grid gap-2">
                                                    <Label :for="`author-social-link-label-${index}`">Label</Label>
                                                    <Input
                                                        :id="`author-social-link-label-${index}`"
                                                        v-model="form.author.social_links[index].label"
                                                        type="text"
                                                        placeholder="Mastodon"
                                                    />
                                                    <InputError :message="form.errors[`author.social_links.${index}.label`]" />
                                                </div>
                                                <div class="grid gap-2">
                                                    <Label :for="`author-social-link-url-${index}`">URL</Label>
                                                    <Input
                                                        :id="`author-social-link-url-${index}`"
                                                        v-model="form.author.social_links[index].url"
                                                        type="url"
                                                        placeholder="https://example.social/@author"
                                                    />
                                                    <InputError :message="form.errors[`author.social_links.${index}.url`]" />
                                                </div>
                                            </div>
                                            <div class="flex justify-end">
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    @click="removeAuthorSocialLink(index)"
                                                >
                                                    Remove
                                                </Button>
                                            </div>
                                        </div>
                                    </div>
                                    <div v-else class="rounded-md border border-dashed p-4 text-sm text-muted-foreground">
                                        No social links specified.
                                    </div>
                                    <InputError :message="form.errors['author.social_links']" />
                                </div>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>Timeline</CardTitle>
                                <CardDescription>Key milestones for this post.</CardDescription>
                            </CardHeader>
                            <CardContent class="space-y-2 text-sm text-muted-foreground">
                                <div class="flex justify-between">
                                    <span>Created</span>
                                    <span>{{ createdAt }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span>Last updated</span>
                                    <span>{{ updatedAt }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span>Published</span>
                                    <span>{{ publishedAt }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span>Scheduled</span>
                                    <span>{{ scheduledAt }}</span>
                                </div>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>Revision history</CardTitle>
                                <CardDescription>Review saved versions of this article.</CardDescription>
                            </CardHeader>
                            <CardContent class="space-y-4">
                                <div v-if="props.revisions.length" class="space-y-4">
                                    <div
                                        v-for="revision in props.revisions"
                                        :key="`revision-${revision.id}`"
                                        class="space-y-2 rounded-md border border-border p-3"
                                    >
                                        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                            <div class="space-y-1">
                                                <p class="font-medium leading-none">{{ revision.title }}</p>
                                                <p class="text-xs text-muted-foreground">
                                                    Saved {{ formatRevisionDate(revision.created_at) }}
                                                    <template v-if="revision.editor">
                                                        by {{ revision.editor.nickname ?? revision.editor.email ?? 'Unknown editor' }}
                                                    </template>
                                                </p>
                                                <p v-if="formatRevisionStatus(revision.metadata?.status)" class="text-xs text-muted-foreground">
                                                    Status: {{ formatRevisionStatus(revision.metadata?.status) }}
                                                </p>
                                            </div>
                                            <Button
                                                v-if="props.can_restore_revisions"
                                                type="button"
                                                size="sm"
                                                :disabled="restoringRevisionId === revision.id"
                                                @click="restoreRevision(revision.id)"
                                            >
                                                {{ restoringRevisionId === revision.id ? 'Restoring...' : 'Restore' }}
                                            </Button>
                                        </div>
                                        <p v-if="revision.excerpt" class="text-xs text-muted-foreground">
                                            {{ revision.excerpt }}
                                        </p>
                                    </div>
                                </div>
                                <p v-else class="text-sm text-muted-foreground">No revisions captured yet.</p>

                                <p
                                    v-if="!props.can_restore_revisions"
                                    class="text-xs text-muted-foreground"
                                >
                                    You don't have permission to restore revisions.
                                </p>
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </form>

            <Dialog v-model:open="previewOpen">
                <DialogContent class="sm:max-w-3xl">
                    <DialogHeader>
                        <DialogTitle>Preview blog post</DialogTitle>
                        <DialogDescription>
                            Confirm how the article will look once saved or scheduled.
                        </DialogDescription>
                    </DialogHeader>

                    <div class="space-y-6">
                        <Alert variant="warning" class="items-start">
                            <component :is="CalendarClock" class="mt-0.5 h-4 w-4" />
                            <AlertTitle>Publication summary</AlertTitle>
                            <AlertDescription>{{ previewScheduledMessage }}</AlertDescription>
                        </Alert>

                        <div class="flex flex-wrap items-center gap-2">
                            <Button
                                v-if="previewLink"
                                variant="outline"
                                as-child
                                class="flex items-center gap-2"
                            >
                                <a :href="previewLink" target="_blank" rel="noopener">
                                    <LinkIcon class="h-4 w-4" />
                                    Open shareable preview
                                </a>
                            </Button>
                            <Button
                                v-if="previewLink"
                                type="button"
                                variant="ghost"
                                class="flex items-center gap-2"
                                @click="copyPreviewLink"
                            >
                                <LinkIcon class="h-4 w-4" />
                                {{ previewCopied ? 'Copied!' : 'Copy preview link' }}
                            </Button>
                        </div>

                        <div class="space-y-6">
                            <div class="overflow-hidden rounded-lg border border-muted">
                                <img :src="previewCover" alt="Preview cover" class="h-56 w-full object-cover" />
                            </div>

                            <div class="space-y-4">
                                <div>
                                    <p class="text-xs uppercase tracking-wide text-muted-foreground">Title</p>
                                    <h2 class="text-2xl font-semibold">{{ form.title || 'Untitled post' }}</h2>
                                </div>
                                <div v-if="form.excerpt" class="text-sm text-muted-foreground">
                                    {{ form.excerpt }}
                                </div>
                                <div class="prose max-w-none" v-html="form.body || '<p>No body content yet.</p>'"></div>
                            </div>

                            <div v-if="selectedCategories.length || selectedTags.length" class="space-y-3">
                                <p class="text-xs uppercase tracking-wide text-muted-foreground">Metadata</p>
                                <div class="flex flex-wrap gap-2 text-xs">
                                    <span
                                        v-for="category in selectedCategories"
                                        :key="`preview-category-${category.id}`"
                                        class="inline-flex items-center rounded-full border border-primary/30 bg-primary/10 px-3 py-1 font-medium text-primary"
                                    >
                                        {{ category.name }}
                                    </span>
                                    <span
                                        v-for="tag in selectedTags"
                                        :key="`preview-tag-${tag.id}`"
                                        class="inline-flex items-center rounded-full border border-muted-foreground/30 bg-muted px-3 py-1 font-medium text-muted-foreground"
                                    >
                                        #{{ tag.name }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    </AppLayout>
</template>
