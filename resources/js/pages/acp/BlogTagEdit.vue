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

interface TagPayload {
    id: number;
    name: string;
    slug: string;
    blogs_count: number;
    created_at: string | null;
    updated_at: string | null;
}

const props = defineProps<{
    tag: TagPayload;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Blogs ACP', href: route('acp.blogs.index') },
    { title: 'Manage tags', href: route('acp.blog-tags.index') },
    { title: props.tag.name, href: route('acp.blog-tags.edit', { tag: props.tag.id }) },
];

const form = useForm({
    name: props.tag.name,
    slug: props.tag.slug,
});

const handleSubmit = () => {
    form.put(route('acp.blog-tags.update', { tag: props.tag.id }), {
        preserveScroll: true,
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`Edit ${props.tag.name}`" />

        <AdminLayout>
            <form class="flex flex-1 flex-col gap-6" @submit.prevent="handleSubmit">
                <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight">Edit blog tag</h1>
                        <p class="text-sm text-muted-foreground">
                            Update the tag name or slug. {{ props.tag.blogs_count }} blog post{{ props.tag.blogs_count === 1 ? '' : 's' }} currently use this tag.
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Button variant="outline" as-child>
                            <Link :href="route('acp.blog-tags.index')">Back</Link>
                        </Button>
                        <Button type="submit" :disabled="form.processing">Update tag</Button>
                    </div>
                </div>

                <Card>
                    <CardHeader class="relative overflow-hidden">
                        <PlaceholderPattern class="absolute inset-0 opacity-10" />
                        <div class="relative space-y-1">
                            <CardTitle>Tag details</CardTitle>
                            <CardDescription>
                                Adjust the display name and slug. The slug determines how the tag appears in URLs and filters.
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
                        <Button type="submit" :disabled="form.processing">Update tag</Button>
                    </CardFooter>
                </Card>
            </form>
        </AdminLayout>
    </AppLayout>
</template>
