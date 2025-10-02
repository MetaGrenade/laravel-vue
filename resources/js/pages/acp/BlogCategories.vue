<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { useUserTimezone } from '@/composables/useUserTimezone';
import { Plus, Edit3, Trash2 } from 'lucide-vue-next';

const props = defineProps<{
    categories: Array<{
        id: number;
        name: string;
        slug: string;
        blogs_count: number;
        created_at: string | null;
        updated_at: string | null;
    }>;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Blogs ACP', href: route('acp.blogs.index') },
    { title: 'Blog categories', href: route('acp.blog-categories.index') },
];

const hasCategories = computed(() => props.categories.length > 0);
const { formatDate } = useUserTimezone();

const deleteCategory = (categoryId: number) => {
    if (
        confirm(
            'Deleting this category will remove it from all blog posts. Posts will remain but without this category. Continue?',
        )
    ) {
        router.delete(route('acp.blog-categories.destroy', { category: categoryId }), {
            preserveScroll: true,
        });
    }
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Manage blog categories" />

        <AdminLayout>
            <div class="flex flex-1 flex-col gap-6">
                <div class="flex flex-col justify-between gap-4 md:flex-row md:items-center">
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight">Blog categories</h1>
                        <p class="text-sm text-muted-foreground">
                            Organize blog posts by topic to help readers discover related articles faster.
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Button variant="outline" as-child>
                            <Link :href="route('acp.blogs.index')">Back to blogs</Link>
                        </Button>
                        <Button as-child>
                            <Link :href="route('acp.blog-categories.create')" class="flex items-center gap-2">
                                <Plus class="h-4 w-4" />
                                New category
                            </Link>
                        </Button>
                    </div>
                </div>

                <Card>
                    <CardHeader class="relative overflow-hidden">
                        <PlaceholderPattern class="absolute inset-0 opacity-10" />
                        <div class="relative space-y-1">
                            <CardTitle>Available categories</CardTitle>
                            <CardDescription>
                                Track your taxonomy at a glance, including how many posts use each category and when it was last
                                updated.
                            </CardDescription>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div v-if="!hasCategories" class="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                            No categories yet. Create your first one to start organizing the blog.
                        </div>

                        <div v-else class="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead class="w-1/6">Name</TableHead>
                                        <TableHead class="w-1/6">Slug</TableHead>
                                        <TableHead class="w-1/6 text-center">Posts</TableHead>
                                        <TableHead class="w-1/6">Created</TableHead>
                                        <TableHead class="w-1/6">Updated</TableHead>
                                        <TableHead class="w-1/6 text-right">Actions</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    <TableRow v-for="category in props.categories" :key="category.id">
                                        <TableCell class="font-medium">{{ category.name }}</TableCell>
                                        <TableCell>{{ category.slug }}</TableCell>
                                        <TableCell class="text-center">{{ category.blogs_count }}</TableCell>
                                        <TableCell>
                                            {{ category.created_at ? formatDate(category.created_at, 'MMM D, YYYY h:mm A') : '—' }}
                                        </TableCell>
                                        <TableCell>
                                            {{ category.updated_at ? formatDate(category.updated_at, 'MMM D, YYYY h:mm A') : '—' }}
                                        </TableCell>
                                        <TableCell>
                                            <div class="flex justify-end gap-2">
                                                <Button variant="outline" size="sm" as-child>
                                                    <Link :href="route('acp.blog-categories.edit', { category: category.id })" class="flex items-center gap-2">
                                                        <Edit3 class="h-4 w-4" />
                                                        Edit
                                                    </Link>
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant="destructive"
                                                    size="sm"
                                                    class="flex items-center gap-2"
                                                    @click="deleteCategory(category.id)"
                                                >
                                                    <Trash2 class="h-4 w-4" />
                                                    Delete
                                                </Button>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                </TableBody>
                            </Table>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    </AppLayout>
</template>
