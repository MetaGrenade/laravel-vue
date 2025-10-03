<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import { type BreadcrumbItem } from '@/types';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useUserTimezone } from '@/composables/useUserTimezone';
import { LifeBuoy, Pencil, PlusCircle, Trash2 } from 'lucide-vue-next';

const props = defineProps<{
    categories: Array<{
        id: number;
        name: string;
        slug: string;
        description: string | null;
        order: number;
        faqs_count: number;
        created_at: string | null;
        updated_at: string | null;
    }>;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Support ACP', href: route('acp.support.index') },
    { title: 'FAQ categories', href: route('acp.support.faq-categories.index') },
];

const hasCategories = computed(() => props.categories.length > 0);
const { formatDate } = useUserTimezone();

const deleteDialogOpen = ref(false);
const pendingCategory = ref<(typeof props.categories)[number] | null>(null);
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

const deleteCategory = (category: (typeof props.categories)[number]) => {
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

    router.delete(route('acp.support.faq-categories.destroy', { category: target.id }), {
        preserveScroll: true,
        onFinish: () => {
            deletingCategoryId.value = null;
        },
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Manage FAQ categories" />

        <AdminLayout>
            <Card class="flex-1">
                <CardHeader class="relative overflow-hidden">
                    <PlaceholderPattern class="absolute inset-0 opacity-10" />
                    <div class="relative flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <CardTitle class="flex items-center gap-2">
                                <LifeBuoy class="h-5 w-5" />
                                FAQ categories
                            </CardTitle>
                            <CardDescription>
                                Group related questions together so visitors can skim support topics more easily.
                            </CardDescription>
                        </div>
                        <Button variant="secondary" as-child>
                            <Link :href="route('acp.support.faq-categories.create')">
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
                        No FAQ categories yet. Create one to start organizing your knowledge base content.
                    </div>

                    <div v-else class="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead class="w-1/4">Name</TableHead>
                                    <TableHead class="w-1/4">Slug</TableHead>
                                    <TableHead class="w-1/6 text-center">Display order</TableHead>
                                    <TableHead>Description</TableHead>
                                    <TableHead class="text-center">FAQ count</TableHead>
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
                                    <TableCell class="text-center">{{ category.order }}</TableCell>
                                    <TableCell class="max-w-sm text-sm text-muted-foreground">
                                        {{ category.description ?? '—' }}
                                    </TableCell>
                                    <TableCell class="text-center">
                                        <span class="font-semibold">{{ category.faqs_count }}</span>
                                        <span class="ml-1 text-xs text-muted-foreground">
                                            item{{ category.faqs_count === 1 ? '' : 's' }}
                                        </span>
                                    </TableCell>
                                    <TableCell class="text-center">
                                        {{ category.updated_at ? formatDate(category.updated_at, 'MMM D, YYYY h:mm A') : '—' }}
                                    </TableCell>
                                    <TableCell class="flex justify-end gap-2">
                                        <Button variant="outline" size="sm" as-child>
                                            <Link :href="route('acp.support.faq-categories.edit', { category: category.id })">
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
