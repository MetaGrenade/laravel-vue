<script setup lang="ts">
import { computed } from 'vue';
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

const props = defineProps<{
    categories: Array<{ id: number; title: string }>;
    defaultCategoryId?: number | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Forums ACP', href: route('acp.forums.index') },
    { title: 'Create board', href: route('acp.forums.boards.create') },
];

const initialCategoryId = props.defaultCategoryId ?? props.categories[0]?.id ?? '';

const form = useForm({
    forum_category_id: initialCategoryId ? String(initialCategoryId) : '',
    title: '',
    slug: '',
    description: '',
});

const hasCategories = computed(() => props.categories.length > 0);

const handleSubmit = () => {
    form.post(route('acp.forums.boards.store'), {
        preserveScroll: true,
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Create forum board" />

        <AdminLayout>
            <form class="flex flex-1 flex-col gap-6" @submit.prevent="handleSubmit">
                <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight">Create forum board</h1>
                        <p class="text-sm text-muted-foreground">
                            Boards live inside categories and hold the threads and discussions for a specific topic.
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Button variant="outline" as-child>
                            <Link :href="route('acp.forums.index')">Cancel</Link>
                        </Button>
                        <Button type="submit" :disabled="form.processing || !hasCategories">
                            Save board
                        </Button>
                    </div>
                </div>

                <Card>
                    <CardHeader class="relative overflow-hidden">
                        <PlaceholderPattern class="absolute inset-0 opacity-10" />
                        <div class="relative space-y-1">
                            <CardTitle>Board details</CardTitle>
                            <CardDescription>
                                Choose which category this board belongs to and describe its purpose for members.
                            </CardDescription>
                        </div>
                    </CardHeader>
                    <CardContent class="space-y-6">
                        <div class="grid gap-2">
                            <Label for="forum_category_id">Category</Label>
                            <select
                                id="forum_category_id"
                                v-model="form.forum_category_id"
                                class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
                                :disabled="!hasCategories"
                                required
                            >
                                <option value="" disabled>Select a category</option>
                                <option v-for="category in props.categories" :key="category.id" :value="String(category.id)">
                                    {{ category.title }}
                                </option>
                            </select>
                            <InputError :message="form.errors.forum_category_id" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="title">Title</Label>
                            <Input id="title" v-model="form.title" type="text" autocomplete="off" required />
                            <InputError :message="form.errors.title" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="slug">Slug</Label>
                            <Input
                                id="slug"
                                v-model="form.slug"
                                type="text"
                                autocomplete="off"
                                placeholder="Optional custom slug (leave blank to auto-generate)"
                            />
                            <InputError :message="form.errors.slug" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="description">Description</Label>
                            <Textarea
                                id="description"
                                v-model="form.description"
                                placeholder="Explain the focus of this board to help members know what belongs here."
                                class="min-h-24"
                            />
                            <InputError :message="form.errors.description" />
                        </div>
                    </CardContent>
                    <CardFooter class="justify-end gap-2">
                        <Button type="submit" :disabled="form.processing || !hasCategories">Save board</Button>
                    </CardFooter>
                </Card>

                <p v-if="!hasCategories" class="rounded-lg border border-dashed border-sidebar-border/70 p-4 text-sm text-muted-foreground">
                    You need to create a forum category before you can add boards.
                </p>
            </form>
        </AdminLayout>
    </AppLayout>
</template>
