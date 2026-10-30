<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { SearchHealthReport } from '@/types/search';
import { router } from '@inertiajs/vue3';
import { LoaderCircle } from '@lucide/vue';
import { computed, ref } from 'vue';

/**
 * Whether Meilisearch is reachable, holds the documents it should and has the settings the app codes (docs/search-index.md).
 * The report is asked for after the page is shown, so this only draws it and asks again on request.
 */
const props = defineProps<{ report: SearchHealthReport }>();

const checking = ref(false);

function checkAgain() {
    router.reload({
        only: ['searchHealth'],
        onStart: () => (checking.value = true),
        onFinish: () => (checking.value = false),
    });
}

const when = (value: string | null) =>
    value ? new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : 'Never recorded';

const label = computed(() => {
    switch (props.report.status) {
        case 'healthy':
            return 'Healthy';
        case 'attention':
            return 'Needs attention';
        case 'down':
            return 'Not working';
        default:
            return 'Not in use';
    }
});

const variant = computed(() => (props.report.status === 'down' ? 'destructive' : props.report.status === 'attention' ? 'highlight' : 'outline'));
</script>

<template>
    <div class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border" data-testid="search-health">
        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
            <h3 class="flex items-center gap-2 text-lg font-semibold">
                Search
                <Badge :variant="variant" :class="report.status === 'healthy' ? 'text-success' : ''">{{ label }}</Badge>
            </h3>
            <Button v-if="report.status !== 'unused'" type="button" size="sm" variant="outline" :disabled="checking" @click="checkAgain">
                <LoaderCircle v-if="checking" class="mr-2 size-4 animate-spin" aria-hidden="true" /> Check again
            </Button>
        </div>

        <p v-if="report.status === 'unused'" class="text-sm text-muted-foreground">
            Search uses the database ({{ report.driver }}). Meilisearch is not in use; set <code>SEARCH_DRIVER=meilisearch</code> to use it.
        </p>

        <template v-else>
            <ul class="space-y-2 text-sm">
                <li>
                    <span class="font-medium text-muted-foreground">Host: </span>
                    <span class="font-medium text-muted-foreground">{{ report.host }}</span>
                </li>
                <li>
                    <span class="font-medium text-muted-foreground">Version: </span>
                    <span class="font-medium text-muted-foreground">{{ report.version ?? 'Unknown' }}</span>
                </li>
                <li>
                    <span class="font-medium text-muted-foreground">Settings last pushed: </span>
                    <span class="font-medium text-muted-foreground">{{ when(report.last_synced_at) }}</span>
                </li>
                <li>
                    <span class="font-medium text-muted-foreground">Documents last rebuilt: </span>
                    <span class="font-medium text-muted-foreground">{{ when(report.last_reindexed_at) }}</span>
                </li>
            </ul>

            <div v-if="report.indexes.length" class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs text-muted-foreground">
                        <tr>
                            <th class="py-1 pr-4 font-medium">Index</th>
                            <th class="py-1 pr-4 font-medium">Documents</th>
                            <th class="py-1 pr-4 font-medium">Should be</th>
                            <th class="py-1 pr-4 font-medium">Settings</th>
                            <th class="py-1 font-medium">Updating</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="index in report.indexes" :key="index.name" class="border-t border-sidebar-border/50">
                            <td class="py-1.5 pr-4 font-mono text-xs">{{ index.name }}</td>
                            <td class="py-1.5 pr-4">{{ index.exists ? index.documents : 'Missing' }}</td>
                            <td class="py-1.5 pr-4">{{ index.expected }}</td>
                            <td class="py-1.5 pr-4">
                                {{ index.settings_in_sync === null ? '-' : index.settings_in_sync ? 'As coded' : 'Different' }}
                            </td>
                            <td class="py-1.5">{{ index.indexing ? 'Yes' : 'No' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <ul v-if="report.problems.length" class="mt-4 space-y-2" role="alert">
                <li v-for="problem in report.problems" :key="problem" class="rounded-md border border-highlight/40 bg-highlight/10 p-2 text-sm">
                    {{ problem }}
                </li>
            </ul>

            <ul v-if="report.notes.length" class="mt-3 space-y-1 text-xs text-muted-foreground">
                <li v-for="note in report.notes" :key="note">{{ note }}</li>
            </ul>
        </template>
    </div>
</template>
