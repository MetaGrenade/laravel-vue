<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

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
import { CalendarClock, Eye, Link as LinkIcon, Loader2, RefreshCcw } from 'lucide-vue-next';

type BlogTaxonomyOption = {
    id: number;
    name: string;
    slug: string;
};

type BlogStatus = 'draft' | 'scheduled' | 'published' | 'archived';

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
};

const props = defineProps<{
    blog: BlogPayload;
    categories: BlogTaxonomyOption[];
    tags: BlogTaxonomyOption[];
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
});

const availableCategories = ref<BlogTaxonomyOption[]>([]);
const categoriesRefreshing = ref(false);
const refreshCategoriesError = ref<string | null>(null);

const syncCategorySelection = (categories: BlogTaxonomyOption[]) => {
    const categoryIds = new Set(categories.map((category) => category.id));
    form.category_ids = form.category_ids.filter((categoryId) => categoryIds.has(categoryId));
};

watch(
    () => props.categories,
    (categories) => {
        availableCategories.value = [...categories];
        syncCategorySelection(categories);
    },
    { immediate: true },
);

const parseCategoryOptions = (input: unknown): BlogTaxonomyOption[] => {
    if (!Array.isArray(input)) {
        return [];
    }

    return input
        .map((item) => item as Record<string, unknown>)
        .map((item) => ({
            id: Number(item.id),
            name: String(item.name ?? ''),
            slug: String(item.slug ?? ''),
        }))
        .filter((item) => Number.isInteger(item.id) && item.name.trim().length > 0);
};

const refreshCategories = async () => {
    categoriesRefreshing.value = true;
    refreshCategoriesError.value = null;

    try {
        const response = await fetch(route('acp.blog-categories.index'), {
            headers: {
                Accept: 'application/json',
            },
        });

        if (!response.ok) {
            throw new Error('Unable to refresh categories.');
        }

        const payload = await response.json();
        const refreshed = parseCategoryOptions(payload?.data ?? []);

        availableCategories.value = refreshed;
        syncCategorySelection(refreshed);
    } catch (error) {
        console.error(error);
        refreshCategoriesError.value =
            error instanceof Error ? error.message : 'Unable to refresh categories.';
    } finally {
        categoriesRefreshing.value = false;
    }
};

const { formatDate } = useUserTimezone();

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
    availableCategories.value.filter((category) => form.category_ids.includes(category.id)),
);
const selectedTags = computed(() => props.tags.filter((tag) => form.tag_ids.includes(tag.id)));

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
                                <div class="space-y-2">
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
                                            class="flex items-center gap-2 self-start"
                                            :disabled="categoriesRefreshing"
                                            @click="refreshCategories"
                                        >
                                            <Loader2 v-if="categoriesRefreshing" class="h-4 w-4 animate-spin" />
                                            <RefreshCcw v-else class="h-4 w-4" />
                                            Refresh
                                        </Button>
                                    </div>
                                    <div class="grid gap-2 sm:grid-cols-2">
                                        <label
                                            v-for="category in availableCategories"
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
                                            v-if="availableCategories.length === 0"
                                            class="text-sm text-muted-foreground sm:col-span-2"
                                        >
                                            No categories available yet. Add some options to improve navigation.
                                        </p>
                                    </div>
                                    <p v-if="refreshCategoriesError" class="text-sm text-destructive">
                                        {{ refreshCategoriesError }}
                                    </p>
                                    <InputError :message="form.errors.category_ids" />
                                </div>

                                <div class="space-y-2">
                                    <Label>Tags</Label>
                                    <p class="text-sm text-muted-foreground">
                                        Use tags to surface cross-cutting topics and campaigns.
                                    </p>
                                    <div class="grid gap-2 sm:grid-cols-2">
                                        <label
                                            v-for="tag in props.tags"
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
                                        <p v-if="props.tags.length === 0" class="text-sm text-muted-foreground sm:col-span-2">
                                            No tags configured yet. Seed some to support editorial organization.
                                        </p>
                                    </div>
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
