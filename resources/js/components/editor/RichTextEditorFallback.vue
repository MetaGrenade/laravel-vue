<script setup lang="ts">
import { Skeleton } from '@/components/ui/skeleton';

// Stands in for the rich-text editor while its code downloads, and says so if it
// cannot be downloaded. It keeps the editor's id, so a scroll or link aimed at the
// editor still lands, and its height, so the page does not jump when it arrives.
// The async wrapper hands over every prop of the editor (the whole draft among
// them), which belong on the editor, not on this element: only `id` is used.
defineOptions({ inheritAttrs: false });

defineProps<{
    id?: string;
    error?: unknown;
}>();
</script>

<template>
    <div :id="id" class="flex flex-col gap-2">
        <div class="overflow-hidden rounded-lg border border-border bg-card">
            <div class="h-[calc(2.5rem+1px)] border-b border-border bg-muted/40" />
            <div class="min-h-64 bg-background px-3 py-2">
                <p v-if="error" role="alert" class="text-sm text-destructive">The editor could not be loaded. Reload the page to try again.</p>
                <div v-else class="space-y-3" role="status">
                    <span class="sr-only">Loading the editor</span>
                    <Skeleton class="h-4 w-2/3" />
                    <Skeleton class="h-4 w-1/2" />
                </div>
            </div>
        </div>
        <div class="h-4" />
    </div>
</template>
