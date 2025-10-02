<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import { type BreadcrumbItem } from '@/types';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useUserTimezone } from '@/composables/useUserTimezone';
import { FolderKanban, Pencil, PlusCircle, Trash2 } from 'lucide-vue-next';

const props = defineProps<{
    categories: Array<{
        id: number;
        name: string;
        tickets_count: number;
        created_at: string | null;
        updated_at: string | null;
    }>;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Support ACP', href: route('acp.support.index') },
    { title: 'Ticket categories', href: route('acp.support.ticket-categories.index') },
];

const hasCategories = computed(() => props.categories.length > 0);
const { formatDate } = useUserTimezone();

const deleteCategory = (categoryId: number) => {
    if (!confirm('Deleting this category will uncategorise any tickets assigned to it. Continue?')) {
        return;
    }

    router.delete(route('acp.support.ticket-categories.destroy', { category: categoryId }), {
        preserveScroll: true,
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Manage ticket categories" />

        <AdminLayout>
            <Card class="flex-1">
                <CardHeader class="relative overflow-hidden">
                    <PlaceholderPattern class="absolute inset-0 opacity-10" />
                    <div class="relative flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <CardTitle class="flex items-center gap-2">
                                <FolderKanban class="h-5 w-5" />
                                Ticket categories
                            </CardTitle>
                            <CardDescription>
                                Group incoming tickets by theme so the team can triage requests faster.
                            </CardDescription>
                        </div>
                        <Button variant="secondary" as-child>
                            <Link :href="route('acp.support.ticket-categories.create')">
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
                        No ticket categories yet. Add one to help agents route conversations appropriately.
                    </div>

                    <div v-else class="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead class="w-1/2">Name</TableHead>
                                    <TableHead class="text-center">Tickets</TableHead>
                                    <TableHead class="text-center">Updated</TableHead>
                                    <TableHead class="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow v-for="category in props.categories" :key="category.id">
                                    <TableCell class="font-medium">{{ category.name }}</TableCell>
                                    <TableCell class="text-center">
                                        <span class="font-semibold">{{ category.tickets_count }}</span>
                                        <span class="ml-1 text-xs text-muted-foreground">ticket{{ category.tickets_count === 1 ? '' : 's' }}</span>
                                    </TableCell>
                                    <TableCell class="text-center">
                                        {{ category.updated_at ? formatDate(category.updated_at, 'MMM D, YYYY h:mm A') : '—' }}
                                    </TableCell>
                                    <TableCell class="flex justify-end gap-2">
                                        <Button variant="outline" size="sm" as-child>
                                            <Link :href="route('acp.support.ticket-categories.edit', { category: category.id })">
                                                <Pencil class="h-4 w-4" />
                                                Edit
                                            </Link>
                                        </Button>
                                        <Button variant="destructive" size="sm" @click="deleteCategory(category.id)">
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
        </AdminLayout>
    </AppLayout>
</template>
