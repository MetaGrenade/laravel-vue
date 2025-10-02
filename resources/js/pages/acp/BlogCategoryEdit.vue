<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';

interface CategoryPayload {
    id: number;
    name: string;
    slug: string;
    blogs_count: number;
    created_at: string | null;
    updated_at: string | null;
}

const props = defineProps<{
    category: CategoryPayload;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Blogs ACP', href: route('acp.blogs.index') },
    { title: 'Manage categories', href: route('acp.blog-categories.index') },
    { title: props.category.name, href: route('acp.blog-categories.edit', { category: props.category.id }) },
];

const form = useForm({
    name: props.category.name,
    slug: props.category.slug,
});

const handleSubmit = () => {
    form.put(route('acp.blog-categories.update', { category: props.category.id }), {
        preserveScroll: true,
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`Edit ${props.category.name}`" />

        <AdminLayout>
            <form class="flex flex-1 flex-col gap-6" @submit.prevent="handleSubmit">
                <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight">Edit blog category</h1>
                        <p class="text-sm text-muted-foreground">
                            Update the category name or slug. {{ props.category.blogs_count }} blog post{{
                                props.category.blogs_count === 1 ? '' : 's'
                            }} currently use this category.
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Button variant="outline" as-child>
                            <Link :href="route('acp.blog-categories.index')">Back</Link>
                        </Button>
                        <Button type="submit" :disabled="form.processing">Update category</Button>
                    </div>
                </div>

                <Card>
                    <CardHeader class="relative overflow-hidden">
                        <PlaceholderPattern class="absolute inset-0 opacity-10" />
                        <div class="relative space-y-1">
                            <CardTitle>Category details</CardTitle>
                            <CardDescription>
                                Adjust the display name and slug. The slug determines how the category appears in URLs and filters.
                            </CardDescription>
                        </div>
                    </CardHeader>
                    <CardContent class="space-y-6">
                        <div class="grid gap-2">
                            <Label for="name">Name</Label>
                            <Input id="name" v-model="form.name" type="text" autocomplete="off" required />
                            <InputError :message="form.errors.name" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="slug">Slug</Label>
                            <Input
                                id="slug"
                                v-model="form.slug"
                                type="text"
                                autocomplete="off"
                                placeholder="Leave blank to auto-generate from the name"
                            />
                            <InputError :message="form.errors.slug" />
                        </div>
                    </CardContent>
                    <CardFooter class="justify-end gap-2">
                        <Button type="submit" :disabled="form.processing">Update category</Button>
                    </CardFooter>
                </Card>
            </form>
        </AdminLayout>
    </AppLayout>
</template>
