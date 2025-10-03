<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import InputError from '@/components/InputError.vue';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';

const props = defineProps<{
    category: {
        id: number;
        title: string;
        slug: string;
        description: string | null;
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Forums ACP', href: route('acp.forums.index') },
    { title: 'Edit category', href: route('acp.forums.categories.edit', { category: props.category.id }) },
];

const form = useForm({
    title: props.category.title ?? '',
    slug: props.category.slug ?? '',
    description: props.category.description ?? '',
});

const deleteDialogOpen = ref(false);
const isDeleting = ref(false);
const deleteDialogTitle = computed(() => `Delete “${props.category.title}”?`);

const handleSubmit = () => {
    form.put(route('acp.forums.categories.update', { category: props.category.id }), {
        preserveScroll: true,
    });
};

const handleDelete = () => {
    deleteDialogOpen.value = true;
};

const cancelDelete = () => {
    deleteDialogOpen.value = false;
};

const confirmDelete = () => {
    if (isDeleting.value) {
        return;
    }

    isDeleting.value = true;
    deleteDialogOpen.value = false;

    router.delete(route('acp.forums.categories.destroy', { category: props.category.id }), {
        preserveScroll: true,
        onFinish: () => {
            isDeleting.value = false;
        },
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Edit forum category" />

        <AdminLayout>
            <form class="flex flex-1 flex-col gap-6" @submit.prevent="handleSubmit">
                <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight">Edit forum category</h1>
                        <p class="text-sm text-muted-foreground">
                            Update the category name, slug, or description. Changes apply immediately across the forums.
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Button variant="outline" as-child>
                            <Link :href="route('acp.forums.index')">Back to forums</Link>
                        </Button>
                        <Button type="submit" :disabled="form.processing">Save changes</Button>
                    </div>
                </div>

                <Card>
                    <CardHeader class="relative overflow-hidden">
                        <PlaceholderPattern class="absolute inset-0 opacity-10" />
                        <div class="relative space-y-1">
                            <CardTitle>Category details</CardTitle>
                            <CardDescription>Adjust how this category appears to community members.</CardDescription>
                        </div>
                    </CardHeader>
                    <CardContent class="space-y-6">
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
                                placeholder="Describe the kinds of boards or discussions that belong in this category."
                                class="min-h-24"
                            />
                            <InputError :message="form.errors.description" />
                        </div>
                    </CardContent>
                    <CardFooter class="flex flex-col gap-4 border-t border-border/50 pt-6 md:flex-row md:items-center md:justify-between">
                        <div class="text-sm text-muted-foreground">
                            Removing this category also deletes every board and discussion nested inside it.
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <Button type="submit" :disabled="form.processing">Save changes</Button>
                            <Button type="button" variant="destructive" @click="handleDelete">Delete category</Button>
                        </div>
                    </CardFooter>
                </Card>
            </form>
            <ConfirmDialog
                v-model:open="deleteDialogOpen"
                :title="deleteDialogTitle"
                description="Deleting this category removes all boards, threads, and posts within it."
                confirm-label="Delete category"
                cancel-label="Cancel"
                :confirm-disabled="isDeleting"
                @confirm="confirmDelete"
                @cancel="cancelDelete"
            />
        </AdminLayout>
    </AppLayout>
</template>
