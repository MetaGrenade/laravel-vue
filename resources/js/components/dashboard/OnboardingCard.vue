<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { useI18n } from '@/composables/useI18n';
import type { SharedData } from '@/types';
import type { DashboardOnboarding } from '@/types/dashboard';
import { Link, router, usePage } from '@inertiajs/vue3';
import { CheckCircle2, Circle, Rocket } from '@lucide/vue';
import { computed, ref } from 'vue';

/**
 * The "Get started" checklist (docs/onboarding.md). Each step is done or not according to the account itself, so there is nothing to
 * tick here: it ticks when the person does the thing, wherever they do it.
 */
const props = defineProps<{ onboarding: DashboardOnboarding }>();

const { t } = useI18n();
const page = usePage<SharedData>();

const percent = computed(() => (props.onboarding.total === 0 ? 0 : Math.round((props.onboarding.done / props.onboarding.total) * 100)));

// Staff viewing as the customer cannot hide it for them (nothing can be changed in that view).
const canHide = computed(() => !page.props.impersonation);

const hiding = ref(false);

function hide() {
    router.post(
        props.onboarding.dismiss_url,
        {},
        { preserveScroll: true, onStart: () => (hiding.value = true), onFinish: () => (hiding.value = false) },
    );
}
</script>

<template>
    <Card>
        <CardHeader class="flex flex-row items-start justify-between gap-4 space-y-0">
            <div class="space-y-1.5">
                <CardTitle class="flex items-center gap-2"
                    ><Rocket class="size-5 text-muted-foreground" aria-hidden="true" /> {{ t('ui.onboarding.title') }}</CardTitle
                >
                <CardDescription>{{ t('ui.onboarding.description') }}</CardDescription>
            </div>
            <Button v-if="canHide" variant="ghost" size="sm" class="shrink-0" :disabled="hiding" @click="hide">{{
                t('ui.onboarding.dismiss')
            }}</Button>
        </CardHeader>
        <CardContent class="space-y-4">
            <div class="space-y-1.5">
                <p class="text-xs text-muted-foreground tabular-nums">
                    {{ t('ui.onboarding.progress', { done: onboarding.done, total: onboarding.total }) }}
                </p>
                <div
                    class="h-1.5 overflow-hidden rounded-full bg-muted"
                    role="progressbar"
                    :aria-valuenow="onboarding.done"
                    aria-valuemin="0"
                    :aria-valuemax="onboarding.total"
                    :aria-label="t('ui.onboarding.title')"
                >
                    <div class="h-full rounded-full bg-primary transition-[width]" :style="{ width: `${percent}%` }" />
                </div>
            </div>

            <ul class="divide-y divide-sidebar-border/60 dark:divide-sidebar-border">
                <li v-for="step in onboarding.steps" :key="step.key" class="flex flex-wrap items-center gap-3 py-3 first:pt-0 last:pb-0">
                    <CheckCircle2 v-if="step.done" class="size-5 shrink-0 text-success" aria-hidden="true" />
                    <Circle v-else class="size-5 shrink-0 text-muted-foreground" aria-hidden="true" />
                    <div class="min-w-0 flex-1 basis-56">
                        <p class="text-sm font-medium" :class="step.done ? 'text-muted-foreground line-through' : ''">
                            {{ t(`ui.onboarding.steps.${step.key}.title`) }}
                            <span v-if="step.done" class="sr-only"> ({{ t('ui.onboarding.done') }})</span>
                        </p>
                        <p v-if="!step.done" class="text-xs text-muted-foreground">{{ t(`ui.onboarding.steps.${step.key}.description`) }}</p>
                    </div>
                    <Button v-if="!step.done" size="sm" variant="outline" as-child>
                        <Link :href="step.url">{{ t(`ui.onboarding.steps.${step.key}.action`) }}</Link>
                    </Button>
                </li>
            </ul>
        </CardContent>
    </Card>
</template>
