<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import type { SharedData } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import { Eye } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

/**
 * Shown at the top of every page while a member of staff is viewing the site as a customer (see docs/impersonation.md): who, why, how
 * long is left, and the way out. The server ends the view at the time, whatever this page says; the countdown only shows it coming.
 */
const page = usePage<SharedData>();
const { t } = useI18n();

const impersonation = computed(() => page.props.impersonation ?? null);

const now = ref(Date.now());
let timer: number | undefined;

onMounted(() => {
    timer = window.setInterval(() => (now.value = Date.now()), 1000);
});

onBeforeUnmount(() => window.clearInterval(timer));

const secondsLeft = computed(() => {
    if (!impersonation.value) {
        return 0;
    }

    return Math.max(0, Math.floor((new Date(impersonation.value.expires_at).getTime() - now.value) / 1000));
});

const timeLeft = computed(() => `${Math.floor(secondsLeft.value / 60)}:${String(secondsLeft.value % 60).padStart(2, '0')}`);

// When the time is up, ask the server for the page: it ends the view and takes the staff member back to the account.
watch(secondsLeft, (seconds) => {
    if (impersonation.value && seconds === 0) {
        router.reload();
    }
});

const stopping = ref(false);

function stop() {
    if (!impersonation.value) {
        return;
    }

    stopping.value = true;
    router.post(impersonation.value.stop_url, {}, { onFinish: () => (stopping.value = false) });
}
</script>

<template>
    <div
        v-if="impersonation"
        role="status"
        class="relative z-50 border-b border-warning/40 bg-warning/15 text-foreground backdrop-blur sm:sticky sm:top-0"
        data-testid="impersonation-banner"
    >
        <!-- On a phone the text has a row of its own, then the time and the way out: sharing one row left the text a sliver wide. -->
        <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-x-4 gap-y-1 px-4 py-2 text-xs sm:text-sm">
            <div class="flex min-w-0 basis-full items-start gap-2 sm:flex-1 sm:basis-0 sm:items-center">
                <Eye class="mt-0.5 size-4 shrink-0 text-warning sm:mt-0" aria-hidden="true" />
                <p class="min-w-0 flex-1">
                    <strong class="font-semibold">{{
                        t('ui.impersonation.viewing_as', { name: `${impersonation.user.nickname} (${impersonation.user.email})` })
                    }}</strong>
                    <span class="text-muted-foreground"> · {{ t('ui.impersonation.read_only') }}</span>
                    <span v-if="impersonation.reason" class="block truncate text-muted-foreground" :title="impersonation.reason">{{
                        t('ui.impersonation.reason', { reason: impersonation.reason })
                    }}</span>
                </p>
            </div>
            <div class="flex w-full items-center justify-between gap-3 sm:w-auto sm:justify-end">
                <span class="tabular-nums">{{ t('ui.impersonation.ends_in', { time: timeLeft }) }}</span>
                <Button type="button" size="sm" variant="outline" :disabled="stopping" @click="stop">{{ t('ui.impersonation.stop') }}</Button>
            </div>
        </div>
    </div>
</template>
