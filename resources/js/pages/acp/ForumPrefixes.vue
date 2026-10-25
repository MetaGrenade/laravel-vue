<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Button } from '@/components/ui/button';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import ForumPrefixBadge from '@/components/forum/ForumPrefixBadge.vue';
import { usePermissions } from '@/composables/usePermissions';
import { PlusCircle } from '@lucide/vue';

interface PrefixRow {
    id: number;
    name: string;
    slug: string;
    color: string;
    position: number;
    thread_count: number;
    boards: Array<{ id: number; title: string }>;
}

const props = defineProps<{
    prefixes: PrefixRow[];
}>();

const { hasPermission } = usePermissions();
const canCreate = computed(() => hasPermission('forums.acp.create'));
const canEdit = computed(() => hasPermission('forums.acp.edit'));
const canDelete = computed(() => hasPermission('forums.acp.delete'));

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Thread prefixes', href: route('acp.forums.prefixes.index') }];

const pending = ref<PrefixRow | null>(null);
const deleting = ref(false);

const remove = () => {
    const prefix = pending.value;

    if (!prefix || deleting.value) {
        return;
    }

    deleting.value = true;
    router.delete(route('acp.forums.prefixes.destroy', { prefix: prefix.id }), {
        preserveScroll: true,
        onFinish: () => {
            deleting.value = false;
            pending.value = null;
        },
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Thread prefixes" />

        <AdminLayout>
            <div class="flex flex-col gap-6">
                <div class="flex flex-col justify-between gap-4 md:flex-row md:items-center">
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight">Thread prefixes</h1>
                        <p class="text-sm text-muted-foreground">A short label a member picks when they start a thread on a board that offers it.</p>
                    </div>
                    <Button v-if="canCreate" as-child>
                        <Link :href="route('acp.forums.prefixes.create')">
                            <PlusCircle class="mr-2 size-4" />
                            New prefix
                        </Link>
                    </Button>
                </div>

                <p v-if="props.prefixes.length === 0" class="rounded-xl border border-dashed px-5 py-10 text-center text-sm text-muted-foreground">
                    No prefixes yet. A board can offer them once you add one.
                </p>

                <div v-else class="overflow-x-auto rounded-xl border bg-card">
                    <table class="w-full text-sm">
                        <thead class="border-b bg-muted/50 text-left text-muted-foreground">
                            <tr>
                                <th class="px-4 py-3 font-medium">Prefix</th>
                                <th class="px-4 py-3 font-medium">Boards</th>
                                <th class="px-4 py-3 font-medium">Threads</th>
                                <th class="px-4 py-3 font-medium"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="prefix in props.prefixes" :key="prefix.id" class="border-b last:border-0">
                                <td class="px-4 py-3">
                                    <ForumPrefixBadge :prefix="prefix" />
                                    <span class="mt-1 block text-xs text-muted-foreground">{{ prefix.slug }}</span>
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    {{ prefix.boards.length ? prefix.boards.map((board) => board.title).join(', ') : 'Nowhere yet' }}
                                </td>
                                <td class="px-4 py-3 tabular-nums">{{ prefix.thread_count }}</td>
                                <td class="px-4 py-3 text-right">
                                    <div class="flex justify-end gap-2">
                                        <Button v-if="canEdit" variant="outline" size="sm" as-child>
                                            <Link :href="route('acp.forums.prefixes.edit', { prefix: prefix.id })">Edit</Link>
                                        </Button>
                                        <Button v-if="canDelete" variant="outline" size="sm" @click="pending = prefix">Delete</Button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <ConfirmDialog
                :open="pending !== null"
                title="Delete this prefix?"
                description="Threads that use it keep their title. The label comes off."
                confirm-label="Delete prefix"
                cancel-label="Cancel"
                :confirm-disabled="deleting"
                @update:open="
                    (open) => {
                        if (!open) pending = null;
                    }
                "
                @confirm="remove"
                @cancel="pending = null"
            />
        </AdminLayout>
    </AppLayout>
</template>
