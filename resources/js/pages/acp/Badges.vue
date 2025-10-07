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
import { Award, Pencil, PlusCircle, Trash2 } from 'lucide-vue-next';

interface BadgeSummary {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    points_required: number;
    is_active: boolean;
    awarded_count: number;
    created_at: string | null;
    updated_at: string | null;
}

const props = defineProps<{ badges: BadgeSummary[] }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: route('acp.dashboard') },
    { title: 'Badges', href: route('acp.reputation.badges.index') },
];

const hasBadges = computed(() => props.badges.length > 0);
const { formatDate } = useUserTimezone();

const deleteDialogOpen = ref(false);
const pendingBadge = ref<BadgeSummary | null>(null);
const deletingBadgeId = ref<number | null>(null);

const deleteDialogTitle = computed(() => {
    const target = pendingBadge.value;

    if (!target) {
        return 'Delete badge?';
    }

    return `Delete “${target.name}”?`;
});

watch(deleteDialogOpen, (open) => {
    if (!open) {
        pendingBadge.value = null;
    }
});

const deleteBadge = (badge: BadgeSummary) => {
    pendingBadge.value = badge;
    deleteDialogOpen.value = true;
};

const cancelDeleteBadge = () => {
    deleteDialogOpen.value = false;
};

const confirmDeleteBadge = () => {
    const target = pendingBadge.value;

    if (!target) {
        deleteDialogOpen.value = false;
        return;
    }

    deletingBadgeId.value = target.id;
    deleteDialogOpen.value = false;

    router.delete(route('acp.reputation.badges.destroy', { badge: target.id }), {
        preserveScroll: true,
        onFinish: () => {
            deletingBadgeId.value = null;
        },
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Manage badges" />

        <AdminLayout>
            <Card class="flex-1">
                <CardHeader class="relative overflow-hidden">
                    <PlaceholderPattern class="absolute inset-0 opacity-10" />
                    <div class="relative flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <CardTitle class="flex items-center gap-2">
                                <Award class="h-5 w-5" />
                                Reputation badges
                            </CardTitle>
                            <CardDescription>
                                Celebrate contributors by defining milestones that award badges automatically.
                            </CardDescription>
                        </div>
                        <Button variant="secondary" as-child>
                            <Link :href="route('acp.reputation.badges.create')">
                                <PlusCircle class="h-4 w-4" />
                                Create badge
                            </Link>
                        </Button>
                    </div>
                </CardHeader>
                <CardContent>
                    <div
                        v-if="!hasBadges"
                        class="rounded-lg border border-dashed border-muted-foreground/40 p-6 text-center text-sm text-muted-foreground"
                    >
                        No badges yet. Create one to start rewarding community members for their contributions.
                    </div>

                    <div v-else class="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead class="w-1/4">Name</TableHead>
                                    <TableHead class="w-1/4">Slug</TableHead>
                                    <TableHead>Description</TableHead>
                                    <TableHead class="text-center">Points</TableHead>
                                    <TableHead class="text-center">Awarded</TableHead>
                                    <TableHead class="text-right">Updated</TableHead>
                                    <TableHead class="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow v-for="badge in props.badges" :key="badge.id">
                                    <TableCell class="font-medium">
                                        <div class="flex items-center gap-2">
                                            <span>{{ badge.name }}</span>
                                            <span
                                                v-if="!badge.is_active"
                                                class="rounded-full bg-yellow-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-yellow-800"
                                            >
                                                Inactive
                                            </span>
                                        </div>
                                    </TableCell>
                                    <TableCell class="font-mono text-xs text-muted-foreground">{{ badge.slug }}</TableCell>
                                    <TableCell class="text-sm">
                                        {{ badge.description ?? '—' }}
                                    </TableCell>
                                    <TableCell class="text-center">
                                        <span class="font-semibold">{{ badge.points_required }}</span>
                                    </TableCell>
                                    <TableCell class="text-center">
                                        <span class="font-semibold">{{ badge.awarded_count }}</span>
                                    </TableCell>
                                    <TableCell class="text-right text-sm text-muted-foreground">
                                        {{ badge.updated_at ? formatDate(badge.updated_at, 'MMM D, YYYY h:mm A') : '—' }}
                                    </TableCell>
                                    <TableCell class="flex justify-end gap-2">
                                        <Button variant="outline" size="sm" as-child>
                                            <Link :href="route('acp.reputation.badges.edit', { badge: badge.id })">
                                                <Pencil class="h-4 w-4" />
                                                Edit
                                            </Link>
                                        </Button>
                                        <Button
                                            variant="destructive"
                                            size="sm"
                                            :disabled="deletingBadgeId === badge.id"
                                            @click="deleteBadge(badge)"
                                        >
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
                :confirm-disabled="deletingBadgeId !== null"
                @confirm="confirmDeleteBadge"
                @cancel="cancelDeleteBadge"
            />
        </AdminLayout>
    </AppLayout>
</template>
