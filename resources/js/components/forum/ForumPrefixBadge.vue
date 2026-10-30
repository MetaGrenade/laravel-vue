<script setup lang="ts">
export interface ForumPrefixLabel {
    id: number;
    name: string;
    slug: string;
    color: string;
}

defineProps<{
    prefix: Pick<ForumPrefixLabel, 'name' | 'color'>;
}>();

const tones: Record<string, string> = {
    slate: 'bg-muted text-muted-foreground',
    sky: 'bg-info/15 text-[color-mix(in_srgb,var(--color-info)_60%,black)] dark:text-info',
    green: 'bg-success/15 text-[color-mix(in_srgb,var(--color-success)_60%,black)] dark:text-success',
    amber: 'bg-warning/15 text-[color-mix(in_srgb,var(--color-warning)_60%,black)] dark:text-warning',
    rose: 'bg-destructive/15 text-[color-mix(in_srgb,var(--color-destructive)_60%,black)] dark:text-destructive',
    violet: 'bg-primary/15 text-[color-mix(in_srgb,var(--color-primary)_60%,black)] dark:text-primary',
};
</script>

<template>
    <span
        class="inline-flex items-center rounded px-1.5 py-0.5 text-[0.625rem] font-semibold tracking-wide uppercase"
        :class="tones[prefix.color] ?? tones.slate"
    >
        {{ prefix.name }}
    </span>
</template>
