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
    sky: 'bg-sky-500/15 text-sky-800 dark:text-sky-200',
    green: 'bg-emerald-500/15 text-emerald-800 dark:text-emerald-200',
    amber: 'bg-amber-500/15 text-amber-900 dark:text-amber-200',
    rose: 'bg-rose-500/15 text-rose-800 dark:text-rose-200',
    violet: 'bg-violet-500/15 text-violet-800 dark:text-violet-200',
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
