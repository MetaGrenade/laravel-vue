<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue';
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

type BlogTaxonomyOption = {
    id: number;
    name: string;
    slug: string;
};

const props = defineProps<{
    categories: BlogTaxonomyOption[];
    tags: BlogTaxonomyOption[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Blogs ACP', href: route('acp.blogs.index') },
    { title: 'Create blog post', href: route('acp.blogs.create') },
];

const statusOptions = [
    { label: 'Draft', value: 'draft' },
    { label: 'Published', value: 'published' },
    { label: 'Archived', value: 'archived' },
];

type BlogForm = {
    title: string;
    excerpt: string;
    body: string;
    status: 'draft' | 'published' | 'archived';
    cover_image: File | null;
    category_ids: number[];
    tag_ids: number[];
};

const form = useForm<BlogForm>({
    title: '',
    excerpt: '',
    body: '',
    status: 'draft',
    cover_image: null,
    category_ids: [],
    tag_ids: [],
});

const coverImagePreview = ref<string | null>(null);

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
        if (data.cover_image) {
            return data;
        }

        const payload = { ...data };
        delete payload.cover_image;

        return payload;
    });

    form.post(route('acp.blogs.store'), {
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
        <Head title="Create blog post" />

        <AdminLayout>
            <form class="flex flex-1 flex-col gap-6" @submit.prevent="handleSubmit">
                <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight">Create blog post</h1>
                        <p class="text-sm text-muted-foreground">
                            Compose a new article for the community blog and choose when it should go live.
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Button variant="outline" as-child>
                            <Link :href="route('acp.blogs.index')">Cancel</Link>
                        </Button>
                        <Button type="submit" :disabled="form.processing">Save blog post</Button>
                    </div>
                </div>

                <div class="grid gap-6 lg:grid-cols-[minmax(0,_1fr)_320px]">
                    <Card>
                        <CardHeader class="relative overflow-hidden">
                            <PlaceholderPattern class="absolute inset-0 opacity-10" />
                            <div class="relative space-y-1">
                                <CardTitle>Post details</CardTitle>
                                <CardDescription>
                                    Provide the main content for your blog post, including the title, summary and full body.
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
                                    Upload an optional banner image to highlight this post across the site.
                                </p>
                                <InputError :message="form.errors.cover_image" />

                                <div v-if="coverImagePreview" class="mt-2">
                                    <img
                                        :src="coverImagePreview"
                                        alt="Selected cover preview"
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
                                        Choose one or more categories to help readers browse related topics.
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
                                            No categories available yet. Add some in the database seeder or admin tools.
                                        </p>
                                    </div>
                                    <InputError :message="form.errors.category_ids" />
                                </div>

                                <div class="space-y-2">
                                    <Label>Tags</Label>
                                    <p class="text-sm text-muted-foreground">
                                        Add optional tags to highlight key topics or campaigns.
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
                                            No tags available yet. Seed some to enable richer filtering.
                                        </p>
                                    </div>
                                    <InputError :message="form.errors.tag_ids" />
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Publishing options</CardTitle>
                            <CardDescription>Choose the publication state for this post.</CardDescription>
                        </CardHeader>
                        <CardContent class="space-y-4">
                            <div class="grid gap-2">
                                <Label for="status">Status</Label>
                                <select
                                    id="status"
                                    v-model="form.status"
                                    class="
                                        flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm
                                        shadow-sm focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2
                                    "
                                >
                                    <option v-for="option in statusOptions" :key="option.value" :value="option.value">
                                        {{ option.label }}
                                    </option>
                                </select>
                                <InputError :message="form.errors.status" />
                            </div>

                            <p class="text-sm text-muted-foreground">
                                Draft posts remain private until they are published. You can revisit and update them at any
                                time from the Blogs dashboard.
                            </p>
                        </CardContent>
                        <CardFooter class="justify-end">
                            <Button type="submit" :disabled="form.processing">Save blog post</Button>
                        </CardFooter>
                    </Card>
                </div>
            </form>
        </AdminLayout>
    </AppLayout>
</template>
