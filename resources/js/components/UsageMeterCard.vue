<script setup lang="ts">
import type { UsageSummary } from '@/types/usage';
import { computed } from 'vue';

/**
 * What an account has used of one metered thing this month, and what it may: a bar when there is a limit, and a plain count when there
 * is not.
 */
const props = defineProps<{ meter: UsageSummary }>();

const number = (value: number) => new Intl.NumberFormat().format(value);

/** The moment the count starts again, in the viewer's own time: the month ends at midnight in the site's time zone, which can be the evening before here. */
const resets = computed(() => new Date(props.meter.resets_at).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }));

/** How much of the allowance is used, 0 to 100 (an allowance of nothing is all used). */
const percent = computed(() => {
    if (props.meter.allowance === null) {
        return 0;
    }

    return props.meter.allowance === 0 ? 100 : Math.min(100, Math.round((props.meter.used / props.meter.allowance) * 100));
});

const barClass = computed(() => {
    if (props.meter.exhausted) {
        return 'bg-destructive';
    }

    return percent.value >= 80 ? 'bg-warning' : 'bg-primary';
});
</script>

<template>
    <div class="rounded-lg border border-border bg-card p-4 shadow-xs">
        <div class="flex items-baseline justify-between gap-3">
            <p class="text-sm font-medium">{{ meter.label }}</p>
            <p class="text-sm tabular-nums">
                {{ number(meter.used) }}<span v-if="meter.allowance !== null" class="text-muted-foreground"> of {{ number(meter.allowance) }}</span>
            </p>
        </div>

        <div
            v-if="meter.allowance !== null"
            role="progressbar"
            :aria-label="`${meter.label} used this month`"
            aria-valuemin="0"
            :aria-valuemax="meter.allowance"
            :aria-valuenow="Math.min(meter.used, meter.allowance)"
            class="mt-3 h-2 overflow-hidden rounded-full bg-muted"
        >
            <div class="h-full rounded-full transition-[width]" :class="barClass" :style="{ width: `${percent}%` }" />
        </div>

        <p v-if="meter.allowance === null" class="mt-2 text-xs text-muted-foreground">
            No limit on your account. The count starts again on {{ resets }}.
        </p>
        <p v-else-if="meter.blocks" class="mt-2 text-xs text-destructive" role="alert">
            You have used all of it, so more {{ meter.unit }}s are refused until {{ resets }}.
        </p>
        <p v-else-if="meter.exhausted" class="mt-2 text-xs text-warning">
            You are over what your plan includes. The count starts again on {{ resets }}.
        </p>
        <p v-else class="mt-2 text-xs text-muted-foreground">{{ number(meter.remaining ?? 0) }} left. The count starts again on {{ resets }}.</p>
    </div>
</template>
