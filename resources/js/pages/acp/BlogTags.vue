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
import { Pencil, PlusCircle, Tag as TagIcon, Trash2 } from 'lucide-vue-next';

type ManagedTag = {
    id: number;
    name: string;
    slug: string;
    blogs_count: number;
    created_at: string | null;
    updated_at: string | null;
};

const props = defineProps<{
    tags: ManagedTag[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Blogs ACP', href: route('acp.blogs.index') },
    { title: 'Manage tags', href: route('acp.blog-tags.index') },
];

const hasTags = computed(() => props.tags.length > 0);
const { formatDate } = useUserTimezone();

const deleteDialogOpen = ref(false);
const pendingTag = ref<ManagedTag | null>(null);
const deletingTagId = ref<number | null>(null);
const deleteDialogTitle = computed(() => {
    const target = pendingTag.value;

    if (!target) {
        return 'Delete tag?';
    }

    return `Delete “${target.name}”?`;
});

watch(deleteDialogOpen, (open) => {
    if (!open) {
        pendingTag.value = null;
    }
});

const deleteTag = (tag: ManagedTag) => {
    pendingTag.value = tag;
    deleteDialogOpen.value = true;
};

const cancelDeleteTag = () => {
    deleteDialogOpen.value = false;
};

const confirmDeleteTag = () => {
    const target = pendingTag.value;

    if (!target) {
        deleteDialogOpen.value = false;
        return;
    }

    deletingTagId.value = target.id;
    deleteDialogOpen.value = false;

    router.delete(route('acp.blog-tags.destroy', { tag: target.id }), {
        preserveScroll: true,
        onFinish: () => {
            deletingTagId.value = null;
        },
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Manage blog tags" />

        <AdminLayout>
            <Card class="flex-1">
                <CardHeader class="relative overflow-hidden">
                    <PlaceholderPattern class="absolute inset-0 opacity-10" />
                    <div class="relative flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <CardTitle class="flex items-center gap-2">
                                <TagIcon class="h-5 w-5" />
                                Blog tags
                            </CardTitle>
                            <CardDescription>
                                Create, edit, and organize the tags available to writers when publishing blog posts.
                            </CardDescription>
                        </div>
                        <Button variant="secondary" as-child>
                            <Link :href="route('acp.blog-tags.create')">
                                <PlusCircle class="h-4 w-4" />
                                Create tag
                            </Link>
                        </Button>
                    </div>
                </CardHeader>
                <CardContent>
                    <div v-if="!hasTags" class="rounded-lg border border-dashed border-muted-foreground/40 p-6 text-center text-sm text-muted-foreground">
                        No tags have been created yet. Use the button above to add the first blog tag.
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
                                <TableRow v-for="tag in props.tags" :key="tag.id">
                                    <TableCell class="font-medium">{{ tag.name }}</TableCell>
                                    <TableCell>
                                        <span class="rounded bg-muted px-2 py-1 text-xs font-mono">{{ tag.slug }}</span>
                                    </TableCell>
                                    <TableCell class="text-center">
                                        <span class="font-semibold">{{ tag.blogs_count }}</span>
                                        <span class="ml-1 text-xs text-muted-foreground">post{{ tag.blogs_count === 1 ? '' : 's' }}</span>
                                    </TableCell>
                                    <TableCell class="text-center">
                                        {{ tag.created_at ? formatDate(tag.created_at, 'MMM D, YYYY h:mm A') : '—' }}
                                    </TableCell>
                                    <TableCell class="text-center">
                                        {{ tag.updated_at ? formatDate(tag.updated_at, 'MMM D, YYYY h:mm A') : '—' }}
                                    </TableCell>
                                    <TableCell class="flex justify-end gap-2">
                                        <Button variant="outline" size="sm" as-child>
                                            <Link :href="route('acp.blog-tags.edit', { tag: tag.id })">
                                                <Pencil class="h-4 w-4" />
                                                Edit
                                            </Link>
                                        </Button>
                                        <Button variant="destructive" size="sm" @click="deleteTag(tag)">
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
                :confirm-disabled="deletingTagId !== null"
                @confirm="confirmDeleteTag"
                @cancel="cancelDeleteTag"
            />
        </AdminLayout>
    </AppLayout>
</template>
