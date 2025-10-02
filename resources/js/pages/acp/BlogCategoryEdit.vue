<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';

const props = defineProps<{
    category: {
        id: number;
        name: string;
        slug: string;
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Blogs ACP', href: route('acp.blogs.index') },
    { title: 'Edit category', href: route('acp.blog-categories.edit', { category: props.category.id }) },
];

const form = useForm({
    name: props.category.name ?? '',
    slug: props.category.slug ?? '',
});

const handleSubmit = () => {
    form.put(route('acp.blog-categories.update', { category: props.category.id }), {
        preserveScroll: true,
    });
};

const handleDelete = () => {
    if (
        confirm(
            'Deleting this category will remove it from all blog posts. Posts will remain but without this category. Continue?',
        )
    ) {
        router.delete(route('acp.blog-categories.destroy', { category: props.category.id }), {
            preserveScroll: true,
        });
    }
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Edit blog category" />

        <AdminLayout>
            <form class="flex flex-1 flex-col gap-6" @submit.prevent="handleSubmit">
                <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight">Edit blog category</h1>
                        <p class="text-sm text-muted-foreground">
                            Update the category name or slug. Changes apply immediately across the blog.
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Button variant="outline" as-child>
                            <Link :href="route('acp.blog-categories.index')">Back to categories</Link>
                        </Button>
                        <Button type="submit" :disabled="form.processing">Save changes</Button>
                    </div>
                </div>

                <Card>
                    <CardHeader class="relative overflow-hidden">
                        <PlaceholderPattern class="absolute inset-0 opacity-10" />
                        <div class="relative space-y-1">
                            <CardTitle>Category details</CardTitle>
                            <CardDescription>Adjust how this category appears to readers and editors.</CardDescription>
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
                                placeholder="Optional custom slug (leave blank to auto-generate)"
                            />
                            <InputError :message="form.errors.slug" />
                        </div>
                    </CardContent>
                    <CardFooter class="flex flex-col gap-4 border-t border-border/50 pt-6 md:flex-row md:items-center md:justify-between">
                        <div class="text-sm text-muted-foreground">
                            Deleting this category will not delete posts but will remove their association with it.
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <Button type="submit" :disabled="form.processing">Save changes</Button>
                            <Button type="button" variant="destructive" @click="handleDelete">Delete category</Button>
                        </div>
                    </CardFooter>
                </Card>
            </form>
        </AdminLayout>
    </AppLayout>
</template>
