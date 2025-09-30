<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';
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

type BlogTaxonomyOption = {
    id: number;
    name: string;
    slug: string;
};

type BlogStatus = 'draft' | 'published' | 'archived';

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
    cover_image?: string | null;
    cover_image_url?: string | null;
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
});

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

const coverImagePreview = ref<string | null>(null);

const existingCoverImage = computed(() => coverImagePreview.value ?? props.blog.cover_image_url ?? null);

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
                                    <Label>Categories</Label>
                                    <p class="text-sm text-muted-foreground">
                                        Select the categories that best represent this article.
                                    </p>
                                    <div class="grid gap-2 sm:grid-cols-2">
                                        <label
                                            v-for="category in props.categories"
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
                                        <p v-if="props.categories.length === 0" class="text-sm text-muted-foreground sm:col-span-2">
                                            No categories available yet. Add some options to improve navigation.
                                        </p>
                                    </div>
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

                                <p class="text-sm text-muted-foreground">
                                    Publishing immediately sets the article live and records the publish time. Draft posts stay
                                    private to the editorial team.
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
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </form>
        </AdminLayout>
    </AppLayout>
</template>
