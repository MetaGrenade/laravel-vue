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
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { CalendarClock, Eye } from 'lucide-vue-next';
import { useUserTimezone } from '@/composables/useUserTimezone';

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
    { label: 'Scheduled', value: 'scheduled' },
    { label: 'Published', value: 'published' },
    { label: 'Archived', value: 'archived' },
];

type BlogForm = {
    title: string;
    excerpt: string;
    body: string;
    status: 'draft' | 'scheduled' | 'published' | 'archived';
    cover_image: File | null;
    category_ids: number[];
    tag_ids: number[];
    scheduled_for: string;
};

const form = useForm<BlogForm>({
    title: '',
    excerpt: '',
    body: '',
    status: 'draft',
    cover_image: null,
    category_ids: [],
    tag_ids: [],
    scheduled_for: '',
});

const coverImagePreview = ref<string | null>(null);
const previewOpen = ref(false);

const { formatDate } = useUserTimezone();

const formatForInput = (date: Date) => {
    const pad = (value: number) => value.toString().padStart(2, '0');
    const year = date.getFullYear();
    const month = pad(date.getMonth() + 1);
    const day = pad(date.getDate());
    const hours = pad(date.getHours());
    const minutes = pad(date.getMinutes());

    return `${year}-${month}-${day}T${hours}:${minutes}`;
};

const defaultScheduledAt = () => {
    const date = new Date();
    date.setMinutes(date.getMinutes() + 30);
    date.setSeconds(0, 0);

    return formatForInput(date);
};

const minScheduleValue = computed(() => formatForInput(new Date()));

watch(
    () => form.status,
    (status) => {
        if (status === 'scheduled' && !form.scheduled_for) {
            form.scheduled_for = defaultScheduledAt();
        }

        if (status !== 'scheduled') {
            form.scheduled_for = '';
        }
    },
    { immediate: true },
);

const previewCoverImage = computed(() => coverImagePreview.value ?? null);
const previewCover = computed(() => previewCoverImage.value ?? '/images/default-cover.jpg');
const selectedCategories = computed(() =>
    props.categories.filter((category) => form.category_ids.includes(category.id)),
);
const selectedTags = computed(() => props.tags.filter((tag) => form.tag_ids.includes(tag.id)));
const previewScheduledMessage = computed(() => {
    if (form.status === 'scheduled' && form.scheduled_for) {
        return `Scheduled for ${formatDate(form.scheduled_for, 'MMMM D, YYYY h:mm A')}`;
    }

    if (form.status === 'draft') {
        return 'Draft — this post will stay private until you publish it.';
    }

    if (form.status === 'archived') {
        return 'Archived — readers will not see this post until it is reactivated.';
    }

    return 'Publish immediately once saved.';
});

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
        const payload = { ...data } as Record<string, unknown>;

        if (!data.cover_image) {
            delete payload.cover_image;
        }

        if (data.status !== 'scheduled') {
            delete payload.scheduled_for;
        }

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
                        <Button
                            type="button"
                            variant="secondary"
                            class="flex items-center gap-2"
                            @click="previewOpen = true"
                        >
                            <Eye class="h-4 w-4" />
                            Preview
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
                                    We'll automatically publish the post at this date and time.
                                </p>
                                <InputError :message="form.errors.scheduled_for" />
                            </div>

                            <p class="text-sm text-muted-foreground">
                                Draft posts remain private until they are published. Scheduled posts will go live
                                automatically, while archived entries stay hidden from readers.
                            </p>
                        </CardContent>
                        <CardFooter class="justify-end">
                            <Button type="submit" :disabled="form.processing">Save blog post</Button>
                        </CardFooter>
                    </Card>
                </div>
            </form>

            <Dialog v-model:open="previewOpen">
                <DialogContent class="sm:max-w-3xl">
                    <DialogHeader>
                        <DialogTitle>Preview blog post</DialogTitle>
                        <DialogDescription>
                            Review how your article will appear before saving or scheduling it.
                        </DialogDescription>
                    </DialogHeader>

                    <div class="space-y-6">
                        <Alert variant="warning" class="items-start">
                            <component :is="CalendarClock" class="mt-0.5 h-4 w-4" />
                            <AlertTitle>Publication summary</AlertTitle>
                            <AlertDescription>{{ previewScheduledMessage }}</AlertDescription>
                        </Alert>

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
