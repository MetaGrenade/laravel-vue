<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import { useUserTimezone } from '@/composables/useUserTimezone';
import { FolderTree, Pencil, PlusCircle, Trash2 } from 'lucide-vue-next';

type ManagedCategory = {
    id: number;
    name: string;
    slug: string;
    blogs_count: number;
    created_at: string | null;
    updated_at: string | null;
};

const props = defineProps<{
    categories: ManagedCategory[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Blogs ACP', href: route('acp.blogs.index') },
    { title: 'Manage categories', href: route('acp.blog-categories.index') },
];

const hasCategories = computed(() => props.categories.length > 0);
const { formatDate } = useUserTimezone();

const deleteDialogOpen = ref(false);
const pendingCategory = ref<ManagedCategory | null>(null);
const deletingCategoryId = ref<number | null>(null);
const deleteDialogTitle = computed(() => {
    const target = pendingCategory.value;

    if (!target) {
        return 'Delete category?';
    }

    return `Delete “${target.name}”?`;
});

watch(deleteDialogOpen, (open) => {
    if (!open) {
        pendingCategory.value = null;
    }
});

const deleteCategory = (category: ManagedCategory) => {
    pendingCategory.value = category;
    deleteDialogOpen.value = true;
};

const cancelDeleteCategory = () => {
    deleteDialogOpen.value = false;
};

const confirmDeleteCategory = () => {
    const target = pendingCategory.value;

    if (!target) {
        deleteDialogOpen.value = false;
        return;
    }

    deletingCategoryId.value = target.id;
    deleteDialogOpen.value = false;

    router.delete(route('acp.blog-categories.destroy', { category: target.id }), {
        preserveScroll: true,
        onFinish: () => {
            deletingCategoryId.value = null;
        },
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Manage blog categories" />

        <AdminLayout>
            <Card class="flex-1">
                <CardHeader class="relative overflow-hidden">
                    <PlaceholderPattern class="absolute inset-0 opacity-10" />
                    <div class="relative flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <CardTitle class="flex items-center gap-2">
                                <FolderTree class="h-5 w-5" />
                                Blog categories
                            </CardTitle>
                            <CardDescription>
                                Organize the categories authors can assign to their stories and announcements.
                            </CardDescription>
                        </div>
                        <Button variant="secondary" as-child>
                            <Link :href="route('acp.blog-categories.create')">
                                <PlusCircle class="h-4 w-4" />
                                Create category
                            </Link>
                        </Button>
                    </div>
                </CardHeader>
                <CardContent>
                    <div
                        v-if="!hasCategories"
                        class="rounded-lg border border-dashed border-muted-foreground/40 p-6 text-center text-sm text-muted-foreground"
                    >
                        No categories have been created yet. Use the button above to add the first blog category.
                    </div>

                    <div v-else class="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead class="w-1/4">Name</TableHead>
                                    <TableHead class="w-1/4">Slug</TableHead>
                                    <TableHead class="text-center">Blog usage</TableHead>
                                    <TableHead class="text-center">Created</TableHead>
                                    <TableHead class="text-center">Updated</TableHead>
                                    <TableHead class="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow v-for="category in props.categories" :key="category.id">
                                    <TableCell class="font-medium">{{ category.name }}</TableCell>
                                    <TableCell>
                                        <span class="rounded bg-muted px-2 py-1 text-xs font-mono">{{ category.slug }}</span>
                                    </TableCell>
                                    <TableCell class="text-center">
                                        <span class="font-semibold">{{ category.blogs_count }}</span>
                                        <span class="ml-1 text-xs text-muted-foreground">
                                            post{{ category.blogs_count === 1 ? '' : 's' }}
                                        </span>
                                    </TableCell>
                                    <TableCell class="text-center">
                                        {{ category.created_at ? formatDate(category.created_at, 'MMM D, YYYY h:mm A') : '—' }}
                                    </TableCell>
                                    <TableCell class="text-center">
                                        {{ category.updated_at ? formatDate(category.updated_at, 'MMM D, YYYY h:mm A') : '—' }}
                                    </TableCell>
                                    <TableCell class="flex justify-end gap-2">
                                        <Button variant="outline" size="sm" as-child>
                                            <Link :href="route('acp.blog-categories.edit', { category: category.id })">
                                                <Pencil class="h-4 w-4" />
                                                Edit
                                            </Link>
                                        </Button>
                                        <Button variant="destructive" size="sm" @click="deleteCategory(category)">
                                            <Trash2 class="h-4 w-4" />
                                            Delete
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </div>
                </CardContent>
            </Card>
            <ConfirmDialog
                v-model:open="deleteDialogOpen"
                :title="deleteDialogTitle"
                description="This action cannot be undone."
                confirm-label="Delete"
                cancel-label="Cancel"
                :confirm-disabled="deletingCategoryId !== null"
                @confirm="confirmDeleteCategory"
                @cancel="cancelDeleteCategory"
            />
        </AdminLayout>
    </AppLayout>
</template>
